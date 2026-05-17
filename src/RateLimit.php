<?php
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

class RateLimit
{
    /**
     * Check and record an attempt.
     * Returns true if BLOCKED, false if allowed.
     *
     * @param string $action   e.g. 'login', 'api', 'thumb_refresh'
     * @param string $key      usually IP, or user_id for authenticated limits
     * @param int    $max      max attempts allowed in window
     * @param int    $window   window in seconds
     * @param int    $lockout  lockout duration in seconds after max exceeded
     */
    public static function check(
        string $action,
        string $key,
        int    $max     = 5,
        int    $window  = 900,   // 15 min
        int    $lockout = 1800   // 30 min
    ): bool {
        $hash = hash('sha256', $key . ':' . $action);
        $now  = time();

        // Cleanup expired entries periodically (1 in 50 chance = low overhead)
        if (random_int(1, 50) === 1) {
            DB::run("DELETE FROM rate_limits WHERE UNIX_TIMESTAMP(window_start) + ? < ?",
                    [$window + $lockout, $now]);
        }

        $row = DB::row(
            "SELECT attempts, UNIX_TIMESTAMP(window_start) AS ts FROM rate_limits
             WHERE key_hash = ? AND action = ?",
            [$hash, $action]
        );

        if (!$row) {
            // First attempt — insert
            DB::run(
                "INSERT INTO rate_limits (key_hash, action, attempts, window_start) VALUES (?, ?, 1, NOW())
                 ON DUPLICATE KEY UPDATE attempts = attempts",
                [$hash, $action]
            );
            return false;
        }

        $elapsed = $now - (int)$row['ts'];

        if ($elapsed > $window + $lockout) {
            // Old window, reset
            DB::run(
                "UPDATE rate_limits SET attempts = 1, window_start = NOW()
                 WHERE key_hash = ? AND action = ?",
                [$hash, $action]
            );
            return false;
        }

        if ($row['attempts'] >= $max && $elapsed <= $window + $lockout) {
            // Still in lockout
            return true;
        }

        if ($elapsed > $window) {
            // Window expired but not locked out, reset
            DB::run(
                "UPDATE rate_limits SET attempts = 1, window_start = NOW()
                 WHERE key_hash = ? AND action = ?",
                [$hash, $action]
            );
            return false;
        }

        // Within window, increment
        DB::run(
            "UPDATE rate_limits SET attempts = attempts + 1 WHERE key_hash = ? AND action = ?",
            [$hash, $action]
        );

        $new_attempts = $row['attempts'] + 1;
        return $new_attempts > $max;
    }

    /** Clear rate limit for a key+action (e.g. after successful login) */
    public static function clear(string $action, string $key): void
    {
        $hash = hash('sha256', $key . ':' . $action);
        DB::run("DELETE FROM rate_limits WHERE key_hash = ? AND action = ?", [$hash, $action]);
    }

    /** Get current attempt count without incrementing */
    public static function attempts(string $action, string $key): int
    {
        $hash = hash('sha256', $key . ':' . $action);
        return (int)(DB::val("SELECT attempts FROM rate_limits WHERE key_hash = ? AND action = ?",
                             [$hash, $action]) ?? 0);
    }
}
