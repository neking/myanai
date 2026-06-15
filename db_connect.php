<?php
// Load .env file
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        [$key, $val] = array_map('trim', explode('=', $line, 2));
        putenv("$key=$val");
    }
}

define('DB_HOST',    getenv('DB_HOST')    ?: 'localhost');
define('DB_PORT',    getenv('DB_PORT')    ?: '3306');
define('DB_NAME',    getenv('DB_NAME')    ?: 'noodlehaus');
define('DB_USER',    getenv('DB_USER')    ?: 'root');
define('DB_PASS',    getenv('DB_PASS')    ?: '');
define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');

function getPDO(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        try { $pdo->query('SELECT 1'); } catch(PDOException $e) { $pdo = null; }
    }
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',
                    DB_HOST, DB_PORT, DB_NAME, DB_CHARSET),
                DB_USER, DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch(PDOException $e) {
            $logDir = __DIR__ . '/logs';
            if (!is_dir($logDir)) mkdir($logDir, 0755, true);
            file_put_contents($logDir . '/errors.log',
                '[' . date('Y-m-d H:i:s') . '] DB Connect failed: ' . $e->getMessage() . PHP_EOL,
                FILE_APPEND
            );
            http_response_code(503);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Database unavailable']);
            exit;
        }
    }
    return $pdo;
}

