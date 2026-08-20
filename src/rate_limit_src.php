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

        // SEC-089: this used to be a SELECT, then a branch into either an
        // INSERT ("no row yet") or an UPDATE ("row exists") — not atomic.
        // Under concurrent requests for the SAME key/action (e.g. a scripted
        // brute-force firing many parallel connections from one IP), several
        // requests could all see "no row yet" and all attempt INSERT at
        // once; the table's own UNIQUE KEY uq_key_action (key_hash, action)
        // then rejected every INSERT after the first with a duplicate-key
        // PDOException, which was not caught anywhere and propagated as an
        // unhandled 500 instead of a clean 429/"invalid password" response.
        // This was NOT a bypass of the limit itself — the exception fires
        // before Auth::login() ever reaches password_verify() — just a
        // stability problem under concurrent load.
        //
        // Fix: a single atomic INSERT ... ON DUPLICATE KEY UPDATE, guarded
        // by the same unique key that used to cause the race. MySQL/MariaDB
        // resolves "fresh row vs. existing row, window still open vs.
        // expired" server-side in one round trip, so two concurrent
        // requests for the same key/action can never both take a
        // "row doesn't exist yet" branch. Column names referenced (not
        // wrapped in VALUES()) inside ON DUPLICATE KEY UPDATE refer to the
        // row's current stored value, per MySQL semantics.
        //
        // SEC-112: the write itself is now wrapped in try/catch(PDOException)
        // as a defense-in-depth backstop, independent of the `attempts`
        // column's type. The primary fix is install.php's schema — attempts
        // is now SMALLINT UNSIGNED (0-65535), comfortably above every
        // maxAttempts value used anywhere in the app — but this method has
        // no way to know, at runtime, whether it is running against an
        // up-to-date schema or an older, not-yet-migrated TINYINT column on
        // some install that hasn't applied the migration yet. Before this
        // catch, ANY unexpected write failure here (an out-of-range value,
        // or any other future DB error) propagated as an unhandled
        // PDOException all the way to the router, since db_src.php sets
        // PDO::ATTR_ERRMODE_EXCEPTION and nothing here ever caught anything.
        // That turned the ONE mechanism specifically built to protect an
        // endpoint from abuse (SEC-095/SEC-101) into a self-inflicted 500
        // error for every subsequent request once a single write failed —
        // the opposite of its purpose. Any write failure here now fails
        // CLOSED (treated as "over the limit", i.e. blocked) rather than
        // failing open or crashing the request: safe specifically for a
        // rate limiter, since blocking one legitimate request during a rare
        // DB hiccup costs far less than letting an attacker's request
        // through un-throttled, or than surfacing a raw error to the caller.
        try {
            DB::run(
                "INSERT INTO rate_limits (key_hash, action, attempts, window_start, key_plain)
                 VALUES (?, ?, 1, NOW(), ?)
                 ON DUPLICATE KEY UPDATE
                    attempts     = IF(window_start < DATE_SUB(NOW(), INTERVAL ? SECOND), 1, attempts + 1),
                    window_start = IF(window_start < DATE_SUB(NOW(), INTERVAL ? SECOND), NOW(), window_start),
                    key_plain    = VALUES(key_plain)",
                [$hash, $action, $plain, $windowSec, $windowSec]
            );
        } catch (PDOException $e) {
            error_log('[RateLimit] check() write failed for action=' . $action . ': ' . $e->getMessage());
            return true; // fail closed — block this request rather than let it through unthrottled
        }

        // Read back the now-authoritative attempts count. Safe as a separate
        // statement here because the write above is already atomic — this
        // SELECT can only ever see a value >= what this request's own
        // upsert just wrote (never less, regardless of what any concurrent
        // request did in between).
        $attempts = (int)(DB::val(
            "SELECT attempts FROM rate_limits WHERE key_hash = ? AND action = ?",
            [$hash, $action]
        ) ?? 0);

        return $attempts > $maxAttempts;
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
