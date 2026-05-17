<?php
/**
 * LetaDial - CSRF Protection v5
 * ==============================
 * Two-mode token strategy:
 *
 * MODE A - Session exists (authenticated or partial):
 *   token = HMAC-SHA256(sha256(raw_session_token), HMAC_KEY)
 *   The sha256 of the raw cookie = the DB session `id` column.
 *   Deterministic: same cookie always gives the same token.
 *
 *   Token derivation order (first match wins):
 *   1. Auth::getSessionId()       — if Auth already loaded the session
 *   2. hash('sha256', $_COOKIE['dv_s'])  — if cookie exists but not yet loaded
 *      (covers POST requests where CSRF::token() is called before Auth loads
 *       the session — prevents mismatch between GET render and POST validation)
 *
 * MODE B - No session at all (login page, first visit):
 *   Double-submit cookie 'dv_pa' (HttpOnly session cookie).
 *   MUST call CSRF::token() before any HTML output to set the cookie.
 *
 * Usage:
 *   HTML forms:  <?= CSRF::field() ?>
 *   API header:  X-CSRF-Token: <token>  (value from LETADIAL_BOOT.csrfToken)
 *   Validation:  CSRF::require()
 *   Pre-warm:    $_ = CSRF::token();    ← add at top of login.php (before HTML)
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

class CSRF
{
    private const HEADER          = 'HTTP_X_CSRF_TOKEN';
    private const FIELD           = '_csrf';
    private const PRE_AUTH_COOKIE = 'dv_pa';

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Return the CSRF token for the current request context.
     *
     * The key insight: both GET (render) and POST (validate) must derive
     * the token from the same stable value — the sha256 hash of the session
     * cookie, which equals the DB session `id` column.
     *
     * We check the session cookie directly when Auth hasn't been initialized
     * yet, so we never fall back to Mode B when a session actually exists.
     */
    public static function token(): string
    {
        // 1. Auth already loaded the session — use cached session ID (sha256 hash)
        $sessionId = Auth::getSessionId();
        if ($sessionId) {
            return hash_hmac('sha256', $sessionId, HMAC_KEY);
        }

        // 2. Session cookie exists but Auth hasn't loaded it yet.
        //    Derive session ID directly from cookie (sha256 of raw token = DB id).
        //    This is the same value getPartialUser() would set, ensuring GET/POST
        //    consistency even when CSRF::token() is called before Auth loads.
        $rawToken = $_COOKIE[Auth::COOKIE_SESSION] ?? '';
        if ($rawToken !== '') {
            $sessionId = hash('sha256', $rawToken);
            return hash_hmac('sha256', $sessionId, HMAC_KEY);
        }

        // 3. Mode B: no session — double-submit cookie for pre-auth forms
        return self::preAuthToken();
    }

    public static function validate(string $submitted): bool
    {
        if ($submitted === '') return false;
        $expected = self::token();
        if ($expected === '') return false;
        return hash_equals($expected, $submitted);
    }

    /** Require a valid token — exits with 403 JSON on failure. */
    public static function require(): void
    {
        $submitted = $_SERVER[self::HEADER] ?? $_POST[self::FIELD] ?? '';
        if (!self::validate($submitted)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => 'CSRF validation failed. Refresh and try again.']);
            exit;
        }
    }

    /** Hidden input for HTML forms. Call CSRF::token() before HTML output. */
    public static function field(): string
    {
        $token = htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="' . self::FIELD . '" value="' . $token . '">';
    }

    // ── Mode B: pre-auth double-submit cookie ─────────────────────────────────

    private static function preAuthToken(): string
    {
        $existing = $_COOKIE[self::PRE_AUTH_COOKIE] ?? '';

        if ($existing !== '' && strlen($existing) === 64 && ctype_xdigit($existing)) {
            return $existing;
        }

        $token   = bin2hex(random_bytes(32));
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || ($_SERVER['SERVER_PORT'] ?? 80) == 443;

        setcookie(self::PRE_AUTH_COOKIE, $token, [
            'expires'  => 0,
            'path'     => '/',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        $_COOKIE[self::PRE_AUTH_COOKIE] = $token;
        return $token;
    }
}
