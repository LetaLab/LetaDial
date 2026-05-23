<?php
/**
 * LetaDial — RateLimit (sesja 065: key_plain stored for admin display)
 *
 * check(action, key, maxAttempts, windowSec, blockSec)
 *   Returns TRUE  → limit exceeded, caller should block the request.
 *   Returns FALSE → within limit, request allowed.
 *
 * clear(action, key)
 *   Removes the rate-limit entry (use after successful auth).
 *
 * Storage:
 *   rate_limits.key_hash  = sha256(key) — lookup index
 *   rate_limits.action    = action name — part of unique key
 *   rate_limits.key_plain = plain-text key — admin display only
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

class RateLimit
{
    /**
     * Check & increment rate limit.
     *
     * Window logic:
     *   - First attempt in a window: create row, return false.
     *   - Subsequent attempts within windowSec: increment, return true when > max.
     *   - After blockSec from window_start: reset the window.
     *
     * Note: blockSec >= windowSec in all callers.
     */
    public static function check(
        string $action,
        string $key,
        int    $maxAttempts,
        int    $windowSec,
        int    $blockSec
    ): bool {
        $hash  = hash('sha256', $key);
        $plain = mb_substr($key, 0, 255);

        // Purge expired entries (older than blockSec)
        DB::run(
            "DELETE FROM rate_limits
             WHERE window_start < DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$blockSec]
        );

        $row = DB::row(
            "SELECT id, attempts, window_start
             FROM rate_limits
             WHERE key_hash = ? AND action = ?",
            [$hash, $action]
        );

        if (!$row) {
            // First attempt — insert and allow
            DB::run(
                "INSERT INTO rate_limits (key_hash, action, attempts, window_start, key_plain)
                 VALUES (?, ?, 1, NOW(), ?)",
                [$hash, $action, $plain]
            );
            return false;
        }

        // Check if current window has expired → reset
        $elapsed = time() - strtotime($row['window_start']);
        if ($elapsed > $windowSec) {
            DB::run(
                "UPDATE rate_limits
                 SET attempts = 1, window_start = NOW(), key_plain = ?
                 WHERE key_hash = ? AND action = ?",
                [$plain, $hash, $action]
            );
            return false;
        }

        // Within window — increment
        DB::run(
            "UPDATE rate_limits
             SET attempts = attempts + 1, key_plain = ?
             WHERE key_hash = ? AND action = ?",
            [$plain, $hash, $action]
        );

        return ((int)$row['attempts'] + 1) > $maxAttempts;
    }

    /**
     * Remove rate limit entry after successful operation.
     */
    public static function clear(string $action, string $key): void
    {
        $hash = hash('sha256', $key);
        DB::run(
            "DELETE FROM rate_limits WHERE key_hash = ? AND action = ?",
            [$hash, $action]
        );
    }
}
