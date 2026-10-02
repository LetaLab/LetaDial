<?php
/**
 * LetaDial — Trusted Device (sesja 079)
 *
 * "Skip 2FA on this device for 180 days" — a per-browser trust token,
 * separate from and independent of the remember-me token (dv_r).
 *
 * Model, deliberately mirrored on Auth's own remember-me implementation
 * (selector + verifier split, SHA-256 hash of the verifier stored, raw
 * verifier never stored) — see Auth::createRememberToken() /
 * Auth::loginFromRemember() for the pattern this is copied from.
 *
 * Cookie: dv_td = "{selector}:{verifier_b64}", HttpOnly, Secure (if HTTPS),
 * SameSite=Strict, 180 days, path=/.
 *
 * What this DOES:
 *   - Skips the 2FA CODE prompt on a device that has already proven it
 *     knows the account's password AND completed a real 2FA challenge once.
 *
 * What this does NOT do:
 *   - Never skips the password. A trusted device still has to log in with
 *     login+password every time the dv_s session cookie itself expires —
 *     trusting a device only ever removes the SECOND factor prompt, never
 *     the first.
 *   - Never gets created without a real, successful TOTP/backup-code
 *     check in the same request (see Auth::verify2FA()) — a trusted
 *     device token cannot itself be used to bootstrap 2FA trust.
 *
 * Revocation:
 *   - User can revoke individual devices, or all, from Settings.
 *   - ALL trusted devices for a user are revoked automatically on:
 *       - password change (settings_api.php 'password' action)
 *       - admin force-password-reset (Admin::forcePasswordReset())
 *       - 2FA being disabled/re-enabled from scratch (not applicable yet —
 *         this app has no "disable 2FA" flow; if one is ever added, it
 *         MUST call deleteAllForUser() too)
 *   This mirrors Auth::logoutAllSessions() being called from exactly the
 *   same three places, for the same reason: a device that has been
 *   trusted to skip a security check must not remain trusted once the
 *   credential that check was protecting has been rotated.
 *
 * Rate limiting:
 *   Cookie verification is rate-limited the same way Auth::loginFromRemember()
 *   rate-limits remember-me cookie verification (SEC-152) — an IP-scoped
 *   bucket, checked before any DB lookup, so a flood of malformed/guessed
 *   dv_td cookies cannot be used to hammer the users table.
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

class TrustedDevice
{
    public const COOKIE_NAME = 'dv_td';
    private const DAYS       = 180;

    /**
     * Create a new trusted-device record for $userId and set the dv_td
     * cookie. Called only from Auth::verify2FA() / Auth::enable2FA(),
     * immediately after a real 2FA success, and only when the user
     * explicitly asked to trust this device.
     */
    public static function create(int $userId, string $label, string $ip): void
    {
        $expiry = time() + self::DAYS * 86400;

        $selectorRaw  = random_bytes(12);
        $verifierRaw  = random_bytes(32);
        $selector     = rtrim(strtr(base64_encode($selectorRaw), '+/', '-_'), '=');
        $verifierHash = hash('sha256', $verifierRaw);
        $verifierB64  = rtrim(strtr(base64_encode($verifierRaw), '+/', '-_'), '=');

        DB::run(
            "INSERT INTO trusted_devices (user_id, selector, verifier, label, ip, created_at, last_used_at, expires_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW(), FROM_UNIXTIME(?))",
            [$userId, $selector, $verifierHash, mb_substr($label, 0, 255), $ip, $expiry]
        );

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(self::COOKIE_NAME, $selector . ':' . $verifierB64, [
            'expires'  => $expiry,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    /**
     * Verify the dv_td cookie for $userId. Returns true if this device is
     * currently trusted (skip the 2FA prompt), false otherwise. Never
     * throws, never leaks whether the failure was "no cookie", "expired",
     * "wrong user", or "bad hash" — all collapse to false.
     *
     * Rate limited by IP (mirrors Auth::loginFromRemember(), SEC-152) —
     * checked before any DB read, so a scripted flood of dv_td guesses
     * cannot be used to brute-force the verifier or to hammer the table.
     */
    public static function verify(int $userId): bool
    {
        $cookie = $_COOKIE[self::COOKIE_NAME] ?? '';
        if (!$cookie || !str_contains($cookie, ':')) return false;

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (RateLimit::check('trusted_device', $ip, 30, 600, 600)) {
            return false;
        }

        [$selector, $verifierB64] = explode(':', $cookie, 2);

        $row = DB::row(
            "SELECT id, verifier FROM trusted_devices
             WHERE selector = ? AND user_id = ? AND expires_at > NOW()",
            [$selector, $userId]
        );
        if (!$row) return false;

        $verifierRaw  = base64_decode(strtr($verifierB64, '-_', '+/') . '==');
        if ($verifierRaw === false) return false;
        $verifierHash = hash('sha256', $verifierRaw);

        if (!hash_equals($row['verifier'], $verifierHash)) {
            // Verifier mismatch for an otherwise-valid selector — treat as
            // a compromised/forged token, drop the whole record rather
            // than silently failing this one check.
            DB::run("DELETE FROM trusted_devices WHERE id = ?", [$row['id']]);
            return false;
        }

        DB::run("UPDATE trusted_devices SET last_used_at = NOW() WHERE id = ?", [$row['id']]);
        return true;
    }

    /** List a user's trusted devices, newest first. For Settings display. */
    public static function listForUser(int $userId): array
    {
        return DB::rows(
            "SELECT id, label, ip, created_at, last_used_at, expires_at
             FROM trusted_devices
             WHERE user_id = ?
             ORDER BY last_used_at DESC",
            [$userId]
        ) ?: [];
    }

    /** Revoke a single device. Ownership MUST be verified by the caller. */
    public static function delete(int $id, int $userId): bool
    {
        return DB::run(
            "DELETE FROM trusted_devices WHERE id = ? AND user_id = ?",
            [$id, $userId]
        ) > 0;
    }

    /**
     * Revoke every trusted device for a user. Called alongside
     * Auth::logoutAllSessions() on password change / admin force-reset —
     * a device trusted to skip 2FA must not remain trusted once the
     * password it was gated behind has changed.
     */
    public static function deleteAllForUser(int $userId): int
    {
        return DB::run("DELETE FROM trusted_devices WHERE user_id = ?", [$userId]);
    }

    /** Best-effort human label from a User-Agent string, for the Settings list. */
    public static function labelFromUserAgent(string $ua): string
    {
        $browser = 'Unknown browser';
        $os      = 'Unknown OS';
        if (preg_match('/EdgA?\//', $ua))            $browser = 'Edge';
        elseif (preg_match('/OPR\//', $ua))          $browser = 'Opera';
        elseif (preg_match('/Chrome\//', $ua))       $browser = 'Chrome';
        elseif (preg_match('/Firefox\//', $ua))      $browser = 'Firefox';
        elseif (preg_match('/Safari\//', $ua) && preg_match('/Version\//', $ua)) $browser = 'Safari';

        if (preg_match('/Windows NT/', $ua))         $os = 'Windows';
        elseif (preg_match('/Macintosh/', $ua))      $os = 'macOS';
        elseif (preg_match('/Android/', $ua))        $os = 'Android';
        elseif (preg_match('/iPhone|iPad/', $ua))    $os = 'iOS';
        elseif (preg_match('/Linux/', $ua))          $os = 'Linux';

        return $browser . ' on ' . $os;
    }
}
