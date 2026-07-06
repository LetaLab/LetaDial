<?php
/**
 * LetaDial — RateLimit (sesja 065: key_plain stored for admin display)
 * SEC-082: purge scoped to `action` — see check() for full rationale.
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

        // Purge expired entries for THIS action only (older than its own blockSec).
        //
        // SEC-082: previously this DELETE had no `action` filter, so it purged
        // rows for EVERY action using only the CURRENT call's blockSec. Since
        // blockSec varies per action (600s for login/2fa, 3600s for everything
        // else — see grep across the whole codebase), and login/2fa are hit far
        // more often than any other action, nearly every "hourly" rate limit in
        // the app (forgot_pw, reset_pw, settings_email, admin_invite, …) got
        // silently purged after ~10 minutes instead of the intended 60 —
        // because SOME login/2fa check (blockSec=600) ran in between and wiped
        // out rows that still had 3000+ seconds left on their own window.
        //
        // Fix: scope the purge to `action = ?`. This is safe because every
        // caller of check() for a given action name always passes the same
        // blockSec (verified: 21 distinct action names, each used at exactly
        // one call site in the entire codebase — no action is ever called with
        // two different blockSec values). So this purge now only ever removes
        // rows for the SAME action, once THEIR OWN blockSec has elapsed —
        // never another action's — while still cleaning up abandoned rows for
        // any key under that action (not just the current one), so the table
        // doesn't grow unbounded.
        DB::run(
            "DELETE FROM rate_limits
             WHERE action = ? AND window_start < DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$action, $blockSec]
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
