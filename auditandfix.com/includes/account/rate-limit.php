<?php
/**
 * Atomic SQLite-based rate limiting.
 *
 * Uses BEGIN IMMEDIATE + upsert to prevent race conditions under
 * concurrent PHP-FPM workers on shared hosting.
 */

require_once __DIR__ . '/db.php';

/**
 * Check and increment a rate limit counter.
 *
 * @param string $key     Rate limit key (IP hash or email hash)
 * @param string $action  Action name (e.g. 'magic_link_ip', 'magic_link_email')
 * @param int    $limit   Max requests allowed in the window
 * @param int    $windowMinutes  Window size in minutes
 * @return bool  True if within limit, false if rate limited
 */
function checkRateLimit(string $key, string $action, int $limit, int $windowMinutes = 60): bool {
    $db = getCustomerDb();

    try {
        $db->exec('BEGIN IMMEDIATE');

        // Check existing counter
        $stmt = $db->prepare(
            "SELECT count, window_start FROM rate_limits
             WHERE ip_hash = ? AND action = ?"
        );
        $stmt->execute([$key, $action]);
        $row = $stmt->fetch();

        $now = gmdate('Y-m-d H:i:s');
        $windowStart = gmdate('Y-m-d H:i:s', strtotime("-{$windowMinutes} minutes"));

        if ($row && $row['window_start'] > $windowStart) {
            // Within current window
            if ($row['count'] >= $limit) {
                $db->exec('COMMIT');
                return false; // Rate limited
            }
            // Increment
            $db->prepare(
                "UPDATE rate_limits SET count = count + 1 WHERE ip_hash = ? AND action = ?"
            )->execute([$key, $action]);
        } else {
            // New window or expired — reset counter
            $db->prepare(
                "INSERT INTO rate_limits (ip_hash, action, count, window_start)
                 VALUES (?, ?, 1, ?)
                 ON CONFLICT(ip_hash, action) DO UPDATE SET count = 1, window_start = ?"
            )->execute([$key, $action, $now, $now]);
        }

        $db->exec('COMMIT');
        return true;
    } catch (Throwable $e) {
        try { $db->exec('ROLLBACK'); } catch (Throwable $_) {}
        error_log('Rate limit check failed: ' . $e->getMessage());
        return true; // Fail open — don't block legitimate users on DB errors
    }
}

/**
 * Probabilistic cleanup of stale rate limit entries (~1% of requests).
 */
function rateLimitGc(): void {
    if (mt_rand(1, 100) !== 1) return;

    try {
        $db = getCustomerDb();
        $db->exec("DELETE FROM rate_limits WHERE window_start < datetime('now', '-2 hours')");
    } catch (Throwable $e) {
        error_log('Rate limit GC failed: ' . $e->getMessage());
    }
}
