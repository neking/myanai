<?php
/**
 * S3 Cloud Backup System
 * Uploads database dumps to AWS S3
 * 
 * Setup:
 * 1. Install AWS CLI: apt install awscli
 * 2. Configure: aws configure (add key/secret)
 * 3. Create bucket: myanai-backups
 * 4. Add to cron: 0 1 * * * php /var/www/myanai/s3_backup.php
 * 
 * Or trigger manually: ?action=backup&secret=myanai_s3_backup_2026
 */
declare(strict_types=1);

$secret = $_GET['secret'] ?? '';
$isCLI  = php_sapi_name() === 'cli';
if (!$isCLI && !hash_equals('myanai_s3_backup_2026', $secret)) {
    http_response_code(403); echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit;
}

// ── CONFIG ──
$DB_HOST = 'localhost';
$DB_USER = 'myanai_user';
$DB_PASS = 'i0It2cUUSHiIbr3v1wZquVWOIZaHuudY';
$DB_NAME = 'noodlehaus';
$S3_BUCKET = 'myanai-backups';
$S3_PREFIX = 'db/' . date('Y/m'); // Organized by year/month
$LOCAL_TMP = sys_get_temp_dir();
$RETENTION_DAYS = 30;

$results = [];

// ── STEP 1: Create DB dump ──
$filename  = "myanai_{$DB_NAME}_".date('Y-m-d_H-i-s').".sql";
$localPath = "{$LOCAL_TMP}/{$filename}";

$dumpCmd = "mysqldump -h {$DB_HOST} -u {$DB_USER} -p'{$DB_PASS}' --single-transaction --quick {$DB_NAME} > {$localPath} 2>&1";
$dumpOut = shell_exec($dumpCmd);
$dumpSize = file_exists($localPath) ? filesize($localPath) : 0;

if ($dumpSize < 100) {
    $msg = "❌ DB dump failed: " . ($dumpOut ?: 'unknown error');
    error_log($msg);
    if (!$isCLI) echo json_encode(['ok'=>false,'msg'=>$msg]);
    else echo $msg . PHP_EOL;
    exit(1);
}

$results['dump'] = "✅ " . round($dumpSize / 1024, 1) . " KB";
error_log("S3 backup: dump created {$filename} (".round($dumpSize/1024,1)."KB)");

// ── STEP 2: Compress ──
$gzPath  = $localPath . '.gz';
$gzOut   = shell_exec("gzip -9 {$localPath} 2>&1");
$gzSize  = file_exists($gzPath) ? filesize($gzPath) : 0;
$results['compress'] = $gzSize > 0 ? "✅ ".round($gzSize/1024,1)."KB (compressed)" : "⚠️ Compression failed, using uncompressed";

$uploadFile = $gzSize > 0 ? $gzPath : $localPath;
$uploadName = $gzSize > 0 ? $filename.'.gz' : $filename;

// ── STEP 3: Upload to S3 ──
$s3Path = "s3://{$S3_BUCKET}/{$S3_PREFIX}/{$uploadName}";
$uploadOut = shell_exec("aws s3 cp {$uploadFile} {$s3Path} 2>&1");
$uploaded  = strpos($uploadOut, 'upload:') !== false;

if ($uploaded) {
    $results['upload'] = "✅ Uploaded to {$s3Path}";
    error_log("S3 backup: uploaded to {$s3Path}");
} else {
    $results['upload'] = "⚠️ S3 upload: " . trim($uploadOut ?: 'Failed - check AWS credentials');
    error_log("S3 backup upload warning: {$uploadOut}");
}

// ── STEP 4: Clean up local temp ──
@unlink($uploadFile);
$results['cleanup'] = "✅ Local temp removed";

// ── STEP 5: Retention — delete old backups from S3 ──
$cutoff  = date('Y-m-d', strtotime("-{$RETENTION_DAYS} days"));
$listOut = shell_exec("aws s3 ls s3://{$S3_BUCKET}/db/ --recursive 2>&1");
if ($listOut) {
    $lines = array_filter(explode("\n", $listOut));
    $deleted = 0;
    foreach ($lines as $line) {
        preg_match('/(\d{4}-\d{2}-\d{2})/', $line, $m);
        if (!empty($m[1]) && $m[1] < $cutoff) {
            preg_match('/db\/\S+/', $line, $pathM);
            if ($pathM) {
                shell_exec("aws s3 rm s3://{$S3_BUCKET}/{$pathM[0]} 2>&1");
                $deleted++;
            }
        }
    }
    $results['retention'] = "✅ Cleaned {$deleted} old backups (>{$RETENTION_DAYS} days)";
}

// ── STEP 6: Also keep local backup ──
$localBackupDir = '/var/www/myanai/backups';
if (is_dir($localBackupDir)) {
    $localBackupPath = "{$localBackupDir}/myanai_".date('Y-m-d').".sql.gz";
    // If S3 upload failed, at least keep local
    if (!$uploaded && $gzSize > 0) {
        copy($gzPath, $localBackupPath);
        $results['local_fallback'] = "✅ Saved to {$localBackupPath}";
    }
    // Clean local backups > 7 days
    foreach (glob("{$localBackupDir}/myanai_*.sql*") as $oldFile) {
        if (filemtime($oldFile) < strtotime('-7 days')) {
            @unlink($oldFile);
        }
    }
}

// ── Output ──
$summary = "S3 Backup ".date('Y-m-d H:i:s').": " . implode(', ', array_values($results));
error_log($summary);

if ($isCLI) {
    echo $summary . PHP_EOL;
} else {
    header('Content-Type: application/json');
    echo json_encode(['ok'=>$uploaded, 'results'=>$results, 'file'=>$uploadName]);
}
