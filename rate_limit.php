<?php
/**
 * Global Rate Limiting Middleware
 * Include at the top of any API file to apply rate limits
 * 
 * Usage:
 *   require_once 'rate_limit.php';
 *   applyRateLimit('login', 5, 300); // 5 attempts per 5 minutes
 */
declare(strict_types=1);

/**
 * Apply rate limiting using file-based token bucket
 * 
 * @param string $key     Unique key (e.g., 'login', 'signup', 'api')
 * @param int $maxRequests Max requests allowed
 * @param int $windowSecs  Time window in seconds
 * @param string $identifier IP or user-based identifier (default: client IP)
 */
function applyRateLimit(
    string $key,
    int $maxRequests = 60,
    int $windowSecs = 60,
    string $identifier = ''
): void {
    if (!$identifier) {
        $identifier = $_SERVER['HTTP_X_FORWARDED_FOR'] 
            ?? $_SERVER['REMOTE_ADDR'] 
            ?? 'unknown';
        // Use first IP if comma-separated
        $identifier = trim(explode(',', $identifier)[0]);
    }

    $cacheDir  = sys_get_temp_dir() . '/myanai_ratelimit';
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0700, true);

    $hash      = md5("{$key}:{$identifier}");
    $cacheFile = "{$cacheDir}/{$hash}.json";
    $now       = time();

    // Load existing data
    $data = ['count' => 0, 'window_start' => $now, 'blocked_until' => 0];
    if (file_exists($cacheFile)) {
        $raw = @json_decode(file_get_contents($cacheFile), true);
        if ($raw) $data = $raw;
    }

    // Check if currently blocked
    if ($data['blocked_until'] > $now) {
        $retry = $data['blocked_until'] - $now;
        header('Retry-After: ' . $retry);
        header('X-RateLimit-Limit: ' . $maxRequests);
        header('X-RateLimit-Remaining: 0');
        http_response_code(429);
        echo json_encode([
            'ok'    => false,
            'msg'   => "Too many requests. Try again in {$retry} seconds.",
            'retry_after' => $retry,
        ]);
        exit;
    }

    // Reset window if expired
    if (($now - $data['window_start']) >= $windowSecs) {
        $data = ['count' => 0, 'window_start' => $now, 'blocked_until' => 0];
    }

    // Increment
    $data['count']++;

    // Block if over limit
    if ($data['count'] > $maxRequests) {
        $data['blocked_until'] = $now + $windowSecs;
        file_put_contents($cacheFile, json_encode($data));

        header('Retry-After: ' . $windowSecs);
        header('X-RateLimit-Limit: ' . $maxRequests);
        header('X-RateLimit-Remaining: 0');
        http_response_code(429);
        echo json_encode([
            'ok'    => false,
            'msg'   => "Rate limit exceeded. Try again in {$windowSecs} seconds.",
            'retry_after' => $windowSecs,
        ]);

        // Log abuse
        error_log("Rate limit exceeded: key={$key} ip={$identifier} count={$data['count']}");
        exit;
    }

    // Save updated count
    $remaining = max(0, $maxRequests - $data['count']);
    file_put_contents($cacheFile, json_encode($data));

    // Set headers
    header('X-RateLimit-Limit: ' . $maxRequests);
    header('X-RateLimit-Remaining: ' . $remaining);
    header('X-RateLimit-Reset: ' . ($data['window_start'] + $windowSecs));
}

/**
 * Preset rate limits for common endpoints
 */
function rateLimitSignup(): void   { applyRateLimit('signup',    3,  600); } // 3 per 10 min
function rateLimitLogin(): void    { applyRateLimit('login',     5,  300); } // 5 per 5 min
function rateLimitApiCall(): void  { applyRateLimit('api',      60,   60); } // 60 per min
function rateLimitWebhook(): void  { applyRateLimit('webhook',  10,   60); } // 10 per min
