<?php
/**
 * LetaDial - Authentication
 *
 * Session flow:
 *   login()             → creates DB session, totp_verified=0 if 2FA enabled
 *   verify2FA()         → sets totp_verified=1
 *   getUser()           → returns user ONLY if totp_verified=1
 *   getPartialUser()    → returns user regardless of totp_verified (2FA page)
 *   loginFromRemember() → creates session with totp_verified=0 if user has 2FA
 *   register()          → sesja 068: self-registration (if enabled)
 *
 * SEC-080: verify2FA() and enable2FA() both call TOTP::verifyAndConsume()
 *   (not TOTP::verify()) so a captured/replayed TOTP code cannot be used
 *   twice. See TOTP.php for the full rationale.
 *
 * CSRF consistency note:
 *   self::$sessionId is ALWAYS set to hash('sha256', raw_token) — the same
 *   value stored in the DB `sessions.id` column — so CSRF::token() produces
 *   identical results whether derived here or from the cookie directly.
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

class Auth
{
    // Public so CSRF.php can read the cookie name for direct derivation
    public  const COOKIE_SESSION  = 'dv_s';
    public  const COOKIE_REMEMBER = 'dv_r';

    private static ?array  $currentUser = null;
    private static bool    $userLoaded  = false;
    private static ?string $sessionId   = null;

    // ── Public API ────────────────────────────────────────────────────────────

    public static function login(string $login, string $password, bool $remember = false): array
    {
        $ip = self::ip();
        if (RateLimit::check('login', $ip, 10, 300, 600)) {
            return ['ok' => false, 'error' => 'Too many login attempts. Please wait 10 minutes.'];
        }

        $user = DB::row(
            "SELECT * FROM users WHERE (login = ? OR email = ?) AND email_verified = 1 LIMIT 1",
            [$login, $login]
        );

        if (!$user || !password_verify($password, $user['password_hash'])) {
            DB::run("INSERT INTO login_history (user_id, login_attempt, ip, user_agent, status)
                     VALUES (?, ?, ?, ?, 'fail_password')",
                [$user['id'] ?? null, $login, $ip, self::ua()]
            );
            return ['ok' => false, 'error' => 'Invalid login or password.'];
        }

        RateLimit::clear('login', $ip);

        $totp_verified = ($user['totp_enabled'] ? 0 : 1);
        $raw_token     = self::createSession($user['id'], $totp_verified);

        DB::run("UPDATE users SET last_login = NOW() WHERE id = ?", [$user['id']]);
        DB::run("INSERT INTO login_history (user_id, login_attempt, ip, user_agent, status)
                 VALUES (?, ?, ?, ?, 'success')",
            [$user['id'], $login, $ip, self::ua()]
        );

        self::setSessionCookie($raw_token);

        if ($remember) {
            self::createRememberToken($user['id']);
        }

        self::$sessionId   = hash('sha256', $raw_token);
        self::$currentUser = $user;

        if ($user['totp_enabled']) {
            return ['ok' => true, 'needs_2fa' => true, 'needs_setup' => false];
        }
        if ($user['totp_required']) {
            return ['ok' => true, 'needs_2fa' => false, 'needs_setup' => true];
        }
        return ['ok' => true, 'needs_2fa' => false, 'needs_setup' => false];
    }

    /**
     * Self-registration (sesja 068).
     *
     * Returns:
     *   ['ok' => true, 'auto_verified' => bool]   — success
     *   ['ok' => false, 'error' => string]         — validation failed
     *
     * If SMTP is enabled: creates unverified account, sends activation email.
     * If SMTP is disabled: creates verified account immediately (no email needed).
     *
     * Rate limit: 5 registrations per IP per hour.
     */
    public static function register(
        string $login,
        string $email,
        string $password,
        string $confirm
    ): array {
        $ip = self::ip();

        // Rate limit — stricter than login (registration is more expensive)
        if (RateLimit::check('register', $ip, 5, 3600, 3600)) {
            return ['ok' => false, 'error' => 'Too many registration attempts. Try again in an hour.'];
        }

        // ── Validate login ────────────────────────────────────────────────────
        if (!$login) {
            return ['ok' => false, 'error' => 'Login is required.'];
        }
        if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $login)) {
            return ['ok' => false, 'error' => 'Login must be 3–50 characters: letters, numbers, underscore only.'];
        }

        // ── Validate email ────────────────────────────────────────────────────
        $email = strtolower(trim($email));
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Please enter a valid email address.'];
        }

        // ── Validate password ─────────────────────────────────────────────────
        $pwErrors = Password::validate($password);
        if (!empty($pwErrors)) {
            return ['ok' => false, 'error' => implode(' ', $pwErrors)];
        }
        if ($password !== $confirm) {
            return ['ok' => false, 'error' => 'Passwords do not match.'];
        }

        // ── Check uniqueness ──────────────────────────────────────────────────
        $loginTaken = DB::val("SELECT id FROM users WHERE login = ?", [$login]);
        if ($loginTaken) {
            return ['ok' => false, 'error' => 'This login is already taken.'];
        }

        $emailTaken = DB::val("SELECT id FROM users WHERE email = ?", [$email]);
        if ($emailTaken) {
            return ['ok' => false, 'error' => 'This email address is already registered.'];
        }

        // ── Enforce max users limit (optional) ────────────────────────────────
        $maxUsers = (int)(DB::val("SELECT value FROM settings WHERE key_name = 'max_users'") ?? 0);
        if ($maxUsers > 0) {
            $userCount = (int)(DB::val("SELECT COUNT(*) FROM users") ?? 0);
            if ($userCount >= $maxUsers) {
                return ['ok' => false, 'error' => 'Registration is currently full. Contact the administrator.'];
            }
        }

        // ── Create account ────────────────────────────────────────────────────
        $smtpEnabled   = defined('SMTP_ENABLED') && SMTP_ENABLED;
        $autoVerified  = !$smtpEnabled;
        $activToken    = $autoVerified ? null : bin2hex(random_bytes(32));
        $passwordHash  = Password::hash($password);

        DB::run(
            "INSERT INTO users (login, email, password_hash, role, email_verified, activation_token, created_at)
             VALUES (?, ?, ?, 'user', ?, ?, NOW())",
            [$login, $email, $passwordHash, $autoVerified ? 1 : 0, $activToken]
        );

        // ── Send activation email ─────────────────────────────────────────────
        if (!$autoVerified && $activToken) {
            Mailer::sendActivation($email, $activToken);
        }

        return ['ok' => true, 'auto_verified' => $autoVerified];
    }

    public static function verify2FA(string $code): array
    {
        $user = self::getPartialUser();
        if (!$user) return ['ok' => false, 'error' => 'Session expired. Log in again.'];

        $ip = self::ip();
        if (RateLimit::check('2fa', $ip, 5, 300, 600)) {
            return ['ok' => false, 'error' => 'Too many 2FA attempts. Wait 10 minutes.'];
        }

        $secret_enc = $user['totp_secret'] ?? '';
        if ($secret_enc && TOTP::verifyAndConsume(TOTP::decrypt($secret_enc), $code, $user['id'])) {
            RateLimit::clear('2fa', $ip);
            DB::run("UPDATE sessions SET totp_verified = 1 WHERE id = ?", [self::$sessionId]);
            return ['ok' => true];
        }

        $codes = DB::rows("SELECT * FROM totp_backup_codes WHERE user_id = ? AND used = 0", [$user['id']]);
        foreach ($codes as $bc) {
            if (password_verify($code, $bc['code_hash'])) {
                RateLimit::clear('2fa', $ip);
                DB::run("UPDATE totp_backup_codes SET used = 1, used_at = NOW() WHERE id = ?", [$bc['id']]);
                DB::run("UPDATE sessions SET totp_verified = 1 WHERE id = ?", [self::$sessionId]);
                return ['ok' => true, 'used_backup' => true];
            }
        }

        DB::run("INSERT INTO login_history (user_id, login_attempt, ip, user_agent, status)
                 VALUES (?, ?, ?, ?, 'fail_2fa')",
            [$user['id'], $user['login'], $ip, self::ua()]
        );
        return ['ok' => false, 'error' => 'Invalid code. Try again.'];
    }

    public static function storeSetupSecret(string $secret): void
    {
        $sid = self::getSessionId();
        if (!$sid) return;
        DB::run("UPDATE sessions SET pending_totp = ? WHERE id = ?",
            [TOTP::encrypt($secret), $sid]);
    }

    public static function getSetupSecret(): ?string
    {
        $sid = self::getSessionId();
        if (!$sid) return null;
        $enc = DB::val("SELECT pending_totp FROM sessions WHERE id = ?", [$sid]);
        if (!$enc) return null;
        return TOTP::decrypt($enc);
    }

    public static function enable2FA(string $code): array
    {
        $user = self::getPartialUser();
        if (!$user) return ['ok' => false, 'error' => 'Session expired.'];

        $secret = self::getSetupSecret();
        if (!$secret) return ['ok' => false, 'error' => 'Setup session expired. Start again.'];

        if (!TOTP::verifyAndConsume($secret, $code, $user['id'])) {
            return ['ok' => false, 'error' => 'Invalid code. Check your authenticator app.'];
        }

        DB::run("UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?",
            [TOTP::encrypt($secret), $user['id']]);

        DB::run("DELETE FROM totp_backup_codes WHERE user_id = ?", [$user['id']]);
        $codes = [];
        $stmt  = DB::get()->prepare("INSERT INTO totp_backup_codes (user_id, code_hash) VALUES (?, ?)");
        for ($i = 0; $i < 10; $i++) {
            $raw     = strtoupper(bin2hex(random_bytes(4))) . '-' . strtoupper(bin2hex(random_bytes(4)));
            $codes[] = $raw;
            $stmt->execute([$user['id'], password_hash($raw, PASSWORD_BCRYPT, ['cost' => 10])]);
        }

        DB::run("UPDATE sessions SET totp_verified = 1, pending_totp = NULL WHERE id = ?",
            [self::$sessionId]);

        return ['ok' => true, 'backup_codes' => $codes];
    }

    public static function getUser(): ?array
    {
        if (self::$userLoaded) return self::$currentUser;
        self::$userLoaded = true;

        $token = $_COOKIE[self::COOKIE_SESSION] ?? '';
        if ($token) {
            $row = self::loadSession($token);
            if ($row) {
                self::$sessionId = $row['session_id'];
                if ($row['totp_verified']) {
                    self::$currentUser = self::fetchUser($row['user_id']);
                    return self::$currentUser;
                }
                return null;
            }
        }

        $rem = $_COOKIE[self::COOKIE_REMEMBER] ?? '';
        if ($rem && ($user = self::loginFromRemember($rem))) {
            self::$currentUser = $user;
            return $user;
        }

        return null;
    }

    public static function getPartialUser(): ?array
    {
        $token = $_COOKIE[self::COOKIE_SESSION] ?? '';
        if (!$token) return null;
        $row = self::loadSession($token);
        if (!$row) return null;
        self::$sessionId = $row['session_id'];
        return self::fetchUser($row['user_id']);
    }

    public static function isLoggedIn(): bool { return self::getUser() !== null; }

    public static function requireLogin(): array
    {
        $user = self::getUser();
        if (!$user) { header('Location: /login'); exit; }
        return $user;
    }

    public static function requireAdmin(): array
    {
        $user = self::requireLogin();
        if ($user['role'] !== 'admin') { http_response_code(403); die('Access denied.'); }
        return $user;
    }

    public static function logout(): void
    {
        $token = $_COOKIE[self::COOKIE_SESSION] ?? '';
        if ($token) DB::run("DELETE FROM sessions WHERE id = ?", [hash('sha256', $token)]);

        $rem = $_COOKIE[self::COOKIE_REMEMBER] ?? '';
        if ($rem && str_contains($rem, ':')) {
            $selector = explode(':', $rem)[0];
            DB::run("DELETE FROM remember_tokens WHERE selector = ?", [$selector]);
        }

        self::clearCookies();
        self::$currentUser = null;
        self::$sessionId   = null;
        self::$userLoaded  = false;
    }

    public static function logoutAllSessions(int $userId): void
    {
        DB::run("DELETE FROM sessions        WHERE user_id = ?", [$userId]);
        DB::run("DELETE FROM remember_tokens WHERE user_id = ?", [$userId]);
    }

    public static function logoutEveryone(): void
    {
        DB::run("DELETE FROM sessions");
        DB::run("DELETE FROM remember_tokens");
    }

    public static function getSessionId(): ?string { return self::$sessionId; }

    // ── Session Helpers ───────────────────────────────────────────────────────

    private static function createSession(int $userId, int $totpVerified = 0): string
    {
        $lifetime = (int)(DB::val("SELECT value FROM settings WHERE key_name = 'session_lifetime'") ?? SESSION_TTL);
        $token    = bin2hex(random_bytes(32));
        $id       = hash('sha256', $token);
        $expires  = date('Y-m-d H:i:s', time() + $lifetime);

        DB::run(
            "INSERT INTO sessions (id, user_id, ip, user_agent, expires_at, totp_verified)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$id, $userId, self::ip(), self::ua(), $expires, $totpVerified]
        );

        return $token;
    }

    private static function loadSession(string $rawToken): ?array
    {
        $id  = hash('sha256', $rawToken);
        $row = DB::row(
            "SELECT id AS session_id, user_id, totp_verified, expires_at
             FROM sessions WHERE id = ? AND expires_at > NOW()",
            [$id]
        );
        if (!$row) return null;
        DB::run("UPDATE sessions SET last_activity = NOW() WHERE id = ?", [$id]);
        return $row;
    }

    private static function setSessionCookie(string $rawToken): void
    {
        $lifetime = (int)(DB::val("SELECT value FROM settings WHERE key_name = 'session_lifetime'") ?? SESSION_TTL);
        $secure   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(self::COOKIE_SESSION, $rawToken, [
            'expires'  => time() + $lifetime,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function clearCookies(): void
    {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $past = ['expires' => time() - 86400, 'path' => '/', 'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax'];
        setcookie(self::COOKIE_SESSION,  '', $past);
        setcookie(self::COOKIE_REMEMBER, '', $past);
    }

    private static function fetchUser(int $id): ?array
    {
        return DB::row("SELECT * FROM users WHERE id = ?", [$id]) ?: null;
    }

    private static function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private static function ua(): string
    {
        return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
    }

    // ── Remember-me ───────────────────────────────────────────────────────────

    private static function createRememberToken(int $userId): void
    {
        $days   = (int)(DB::val("SELECT value FROM settings WHERE key_name = 'remember_me_days'") ?? 30);
        $expiry = time() + $days * 86400;

        $selector_raw  = random_bytes(12);
        $verifier_raw  = random_bytes(32);
        $selector      = rtrim(strtr(base64_encode($selector_raw), '+/', '-_'), '=');
        $verifier_hash = hash('sha256', $verifier_raw);
        $verifier_b64  = rtrim(strtr(base64_encode($verifier_raw), '+/', '-_'), '=');

        DB::run(
            "INSERT INTO remember_tokens (user_id, selector, verifier, expires_at)
             VALUES (?, ?, ?, FROM_UNIXTIME(?))",
            [$userId, $selector, $verifier_hash, $expiry]
        );

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(self::COOKIE_REMEMBER, $selector . ':' . $verifier_b64, [
            'expires'  => $expiry,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function loginFromRemember(string $cookie): ?array
    {
        if (!str_contains($cookie, ':')) return null;
        [$selector, $verifier_b64] = explode(':', $cookie, 2);

        $row = DB::row(
            "SELECT * FROM remember_tokens WHERE selector = ? AND expires_at > NOW()",
            [$selector]
        );
        if (!$row) { self::clearCookies(); return null; }

        $verifier_raw  = base64_decode(strtr($verifier_b64, '-_', '+/') . '==');
        $verifier_hash = hash('sha256', $verifier_raw);

        if (!hash_equals($row['verifier'], $verifier_hash)) {
            DB::run("DELETE FROM remember_tokens WHERE user_id = ?", [$row['user_id']]);
            self::clearCookies();
            return null;
        }

        DB::run("DELETE FROM remember_tokens WHERE id = ?", [$row['id']]);
        $user = self::fetchUser($row['user_id']);
        if (!$user) return null;

        $totp_verified = ($user['totp_enabled'] ? 0 : 1);
        $raw_token     = self::createSession($user['id'], $totp_verified);

        self::setSessionCookie($raw_token);
        self::createRememberToken($user['id']);

        self::$sessionId = hash('sha256', $raw_token);

        DB::run("UPDATE users SET last_login = NOW() WHERE id = ?", [$user['id']]);

        if ($user['totp_enabled']) {
            return null;
        }

        return $user;
    }
}
