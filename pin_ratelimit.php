<?php
/**
 * PIN Login Rate Limiting
 * Prevents brute force attacks on POS staff PIN login
 */
declare(strict_types=1);

/**
 * Check if IP/staff combination is rate limited
 * 
 * @param PDO $pdo Database connection
 * @param int $staffId Staff member ID
 * @param string $ipAddress Client IP address
 * @return array ['limited' => bool, 'remaining_attempts' => int, 'retry_after_seconds' => int]
 */
function checkPINRateLimit(PDO $pdo, int $staffId, string $ipAddress): array {
    $maxAttempts = 5;  // Max failed attempts
    $lockoutMinutes = 15;  // Lock out for this many minutes
    
    try {
        // Get failed attempts in last 15 minutes
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as attempts, MAX(attempted_at) as last_attempt
            FROM pin_login_attempts
            WHERE staff_id = ? AND ip_address = ?
            AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
            AND was_successful = 0
        ");
        
        $stmt->execute([$staffId, $ipAddress, $lockoutMinutes]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $failedAttempts = (int)($result['attempts'] ?? 0);
        
        // Check if locked out
        $locked = $failedAttempts >= $maxAttempts;
        $remaining = max(0, $maxAttempts - $failedAttempts);
        
        return [
            'limited' => $locked,
            'remaining_attempts' => $remaining,
            'retry_after_seconds' => $locked ? 900 : 0,  // 15 minutes
        ];
        
    } catch (Exception $e) {
        error_log("Rate limit check error: " . $e->getMessage());
        // On error, allow login (fail open)
        return [
            'limited' => false,
            'remaining_attempts' => 5,
            'retry_after_seconds' => 0,
        ];
    }
}

/**
 * Record a PIN login attempt
 * 
 * @param PDO $pdo Database connection
 * @param int $staffId Staff member ID
 * @param string $ipAddress Client IP address
 * @param bool $successful Whether the attempt succeeded
 * @return void
 */
function recordPINAttempt(
    PDO $pdo,
    int $staffId,
    string $ipAddress,
    bool $successful
): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO pin_login_attempts 
            (staff_id, ip_address, was_successful, attempted_at, user_agent)
            VALUES (?, ?, ?, NOW(), ?)
        ");
        
        $stmt->execute([
            $staffId,
            $ipAddress,
            $successful ? 1 : 0,
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        
        // Clean up old records (older than 24 hours)
        $pdo->prepare("
            DELETE FROM pin_login_attempts
            WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)
        ")->execute();
        
    } catch (Exception $e) {
        error_log("Failed to record PIN attempt: " . $e->getMessage());
    }
}

/**
 * Get login attempt statistics for admin dashboard
 * 
 * @param PDO $pdo Database connection
 * @return array Statistics
 */
function getPINLoginStats(PDO $pdo): array {
    try {
        $stats = $pdo->query("
            SELECT 
                COUNT(*) as total_attempts,
                SUM(CASE WHEN was_successful = 1 THEN 1 ELSE 0 END) as successful,
                SUM(CASE WHEN was_successful = 0 THEN 1 ELSE 0 END) as failed,
                COUNT(DISTINCT ip_address) as unique_ips,
                COUNT(DISTINCT staff_id) as affected_staff
            FROM pin_login_attempts
            WHERE attempted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ")->fetch(PDO::FETCH_ASSOC);
        
        return [
            'total_attempts' => (int)($stats['total_attempts'] ?? 0),
            'successful' => (int)($stats['successful'] ?? 0),
            'failed' => (int)($stats['failed'] ?? 0),
            'unique_ips' => (int)($stats['unique_ips'] ?? 0),
            'affected_staff' => (int)($stats['affected_staff'] ?? 0),
            'period' => '24h',
        ];
        
    } catch (Exception $e) {
        error_log("Failed to get PIN stats: " . $e->getMessage());
        return [];
    }
}

?>
