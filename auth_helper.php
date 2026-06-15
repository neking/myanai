<?php
/**
 * NoodleHaus — Auth Helper
 * CSRF token + role-based admin check
 * Include in APIs that need POST protection
 */

function generateCsrfToken(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): bool {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function requireCsrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf()) {
        // Skip CSRF for internal hook calls (localhost)
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($ip === '127.0.0.1' || $ip === '::1') return;
        
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => 'Invalid CSRF token']);
        exit;
    }
}
