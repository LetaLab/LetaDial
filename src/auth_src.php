<?php
/**
 * LetaDial - Authentication
 *
 * Session flow:
 *   login()             → creates DB session, totp_verified=0 if 2FA enabled
 *                          UNLESS this device is trusted (sesja 079,
 *                          TrustedDevice::verify()), in which case 2FA is
 *                          skipped and the session is created fully verified.
 *   verify2FA()         → sets totp_verified=1 AND rotates to a brand-new
 *                          session/token (SEC-149) — the pre-2FA token is
 *                          deleted, never just "upgraded" in place. If the
 *                          caller asked to trust this device (sesja 079),
 *                          a new trusted-device record + dv_td cookie is
 *                          created here, immediately after the real 2FA
 *                          success — never before it.
 *   getUser()           → returns user ONLY if totp_verified=1
 *   getPartialUser()    → returns user regardless of totp_verified (2FA page)
 *   loginFromRemember() → creates session with totp_verified=0 if user has 2FA
 *                          AND the device is not trusted (sesja 079);
 *                          rate limited and logged to login_history (SEC-152)
 *   register()          → sesja 068: self-registration (if enabled)
 *
 * Sesja 079 (Trusted Device — skip 2FA for 180 days):
 *   A device that has already completed one real 2FA challenge can be
 *   marked "trusted" (src/trusted_device_src.php). A trusted device still
 *   has to supply the correct password every time the session cookie
 *   itself expires — trust only ever removes the SECOND factor prompt,
 *   never the first. Trusted devices are revoked automatically whenever
 *   Auth::logoutAllSessions() runs (password change, forced reset, email
 *   change, password reset via email) — see that method below.
 *
 * SEC-080: verify2FA() and enable2FA() both call TOTP::verifyAndConsume()
 *   so a captured/replayed TOTP code cannot be used twice. See totp_src.php
 *   for the full rationale. (SEC-096: the older, replay-unsafe
 *   TOTP::verify() this comment used to contrast against was removed
 *   entirely on 02.08.2026, once confirmed unused anywhere in the app.)
 *
 * CSRF consistency note:
 *   self::$sessionId is ALWAYS set to hash('sha256', raw_token) — the same
 *   value stored in the DB `sessions.id` column — so CSRF::token() produces
 *   identical results whether derived here or from the cookie directly.
 *
 * BUG-010: login() calls Password::verifyAndRehash() instead of a raw
 *   password_verify() — on a correct password, a hash still on an older
 *   bcrypt cost is transparently re-hashed to the current one. See
 *   password_src.php for the full rationale.
 *
 * SEC-097: login() always spends one bcrypt verify, win or lose. Before
 *   this fix, `!$user || !Password::verifyAndRehash(...)` short-circuited
 *   on `!$user` for a login that does not exist in the DB, skipping the
 *   ~2s (cost=15, see BUG-010) bcrypt call entirely — a non-existent login
 *   returned in a few ms, a wrong password on a real login took ~2s. The
 *   response TEXT was already identical either way ("Invalid login or
 *   password"), but the TIMING alone was enough to enumerate valid
 *   logins. Fix: verify against DUMMY_HASH (a fixed, unusable, pre-computed
 *   hash — never a real credential) when no user is found, so both paths
 *   pay the same constant bcrypt cost. Same pattern Django's
 *   authenticate() uses for the same reason.
 *
 * BUG-012: login() truncates $login to users.login's own VARCHAR(50)
 *   width before it is written into login_history.login_attempt (also
 *   VARCHAR(50)) — see loginAttemptForHistory(). Without it, a login
 *   value longer than 50 chars (login also matches against `email`,
 *   VARCHAR(255), so this is reachable with a long email) hit an
 *   unhandled PDOException under strict SQL mode instead of the normal
 *   "Invalid login or password." response.
 *
 * SEC-104: register() no longer reveals, via message text or response
 *   timing, whether it was the login or the email address that collided
 *   with an existing account — see REGISTER_TIMING_FLOOR and
 *   equalizeRegisterTiming() below, and the docblock on register() itself.
 *   Mirrors the SEC-098 fix in forgot_password_page.php for the same class of
 *   account-enumeration problem.
 *
 * SEC-110: register()'s users INSERT is now wrapped in try/catch(PDOException).
 *   The SELECT-based uniqueness pre-check a few lines above it is not atomic
 *   with the INSERT — two near-simultaneous requests for the same login/email
 *   can both pass that SELECT before either writes, a window SEC-104's own
 *   timing floor makes wider, not narrower. Without the catch, the
 *   uq_login/uq_email UNIQUE KEY (install.php) turned that race into an
 *   uncaught PDOException instead of a clean, enumeration-safe error
 *   response. See register()'s own inline comment for the full rationale;
 *   the same pattern was applied to Admin::createUser()/inviteUser() and
 *   confirm_email_page.php's "apply the change" branch in the same pass.
 *
 * SEC-135: register() now also computes activation_expires (48h) alongside
 *   activation_token. Before this, self-registration's activation token was
 *   the only one of the app's four single-use secret tokens with no
 *   time-based expiry at all. See activate_page.php for the matching
 *   read-side check.
 *
 * SEC-139 (11.09.2026): every TOTP::decrypt()/TOTP::encrypt() call in this
 *   file (verify2FA, storeSetupSecret, getSetupSecret, enable2FA) is
 *   wrapped in try/catch(RuntimeException). See totp_src.php.
 *
 * SEC-149 (SEC_AND_BUG_ANIH_PLAN.md, Czesc XVII): verify2FA() and
 *   enable2FA() call rotateSessionAfter2FA() on every success path instead
 *   of updating the pre-2FA session row in place.
 *
 * SEC-152 (SEC_AND_BUG_ANIH_PLAN.md, Czesc XVII): loginFromRemember() rate
 *   limits per-IP then per-account, and writes a login_history entry on
 *   success.
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

class Auth
{
    // Public so csrf_src.php can read the cookie name for direct derivation
    public  const COOKIE_SESSION  = 'dv_s';
    public  const COOKIE_REMEMBER = 'dv_r';

    /**
     * SEC-097: fixed decoy hash used ONLY to pay the same bcrypt cost as a
     * real verify when the submitted login does not match any account.
     */
    private const DUMMY_HASH = '$2y$15$N7E8msBbPqnQWwfk8p5JrOxz/YNPKi.d1MJ68jRmBd4As8i0xWLVW';

    /**
     * SEC-104: fixed floor (seconds) that register() pads BOTH the
     * "login or email already taken" branch and the "account created"
     * branch up to, via equalizeRegisterTiming() below.
     */
    private const REGISTER_TIMING_FLOOR = 2.5;

    private static ?array  $currentUser = null;
    private static bool    $userLoaded  = false;
    private static ?string $sessionId   = null;

    // ── Public API ────────────────────────────────────────────────────────────

    public static function login(string $login, string $password, bool $remember = false): array
    {
        $ip = self::ip();
        if (RateLimit::check('login', $ip, 10, 600, 600)) {
            return ['ok' => false, 'error' => 'Too many login attempts. Please wait 10 minutes.'];
        }

        $loginKey = mb_strtolower(trim($login));
        if ($loginKey !== '' && RateLimit::check('login_account', $loginKey, 20, 900, 900)) {
            return ['ok' => false, 'error' => 'Too many login attempts for this account. Please wait 15 minutes.'];
        }

        $user = DB::row(
            "SELECT * FROM users WHERE (login = ? OR email = ?) AND email_verified = 1 LIMIT 1",
            [$login, $login]
        );

        if ($user) {
            $passwordOk = Password::verifyAndRehash($password, $user['password_hash'], (int)$user['id']);
        } else {
            Password::verify($password, self::DUMMY_HASH);
            $passwordOk = false;
        }

        $loginForHistory = mb_substr($login, 0, 50);

        if (!$user || !$passwordOk) {
            DB::run("INSERT INTO login_history (user_id, login_attempt, ip, user_agent, status)
                     VALUES (?, ?, ?, ?, 'fail_password')",
                [$user['id'] ?? null, $loginForHistory, $ip, self::ua()]
            );
            return ['ok' => false, 'error' => 'Invalid login or password.'];
        }

        RateLimit::clear('login', $ip);
        RateLimit::clear('login_account', $loginKey);

        // Sesja 079: a device that already proved it completed a real 2FA
        // challenge on this account can skip the 2FA prompt for up to 180
        // days. Password is ALWAYS required regardless — this only ever
        // affects the second factor.
        $deviceTrusted = $user['totp_enabled'] && TrustedDevice::verify((int)$user['id']);
        $totp_verified = (!$user['totp_enabled'] || $deviceTrusted) ? 1 : 0;

        $raw_token = self::createSession($user['id'], $totp_verified);

        DB::run("UPDATE users SET last_login = NOW() WHERE id = ?", [$user['id']]);
        DB::run("INSERT INTO login_history (user_id, login_attempt, ip, user_agent, status)
                 VALUES (?, ?, ?, ?, 'success')",
            [$user['id'], $loginForHistory, $ip, self::ua()]
        );

        self::setSessionCookie($raw_token, $totp_verified);

        if ($remember) {
            self::createRememberToken($user['id']);
        }

        self::$sessionId   = hash('sha256', $raw_token);
        self::$currentUser = $user;

        if ($user['totp_enabled'] && !$deviceTrusted) {
            return ['ok' => true, 'needs_2fa' => true, 'needs_setup' => false];
        }
        if ($user['totp_required'] && !$user['totp_enabled']) {
            return ['ok' => true, 'needs_2fa' => false, 'needs_setup' => true];
        }
        return ['ok' => true, 'needs_2fa' => false, 'needs_setup' => false, 'device_trusted' => $deviceTrusted];
    }

    /**
     * Self-registration (sesja 068). See original docblock at top of file
     * for the SEC-104 timing-equalization rationale — unchanged by sesja 079.
     */
    public static function register(
        string $login,
        string $email,
        string $password,
        string $confirm
    ): array {
        $ip = self::ip();

        if (RateLimit::check('register', $ip, 5, 3600, 3600)) {
            return ['ok' => false, 'error' => 'Too many registration attempts. Try again in an hour.'];
        }

        if (!$login) {
            return ['ok' => false, 'error' => 'Login is required.'];
        }
        if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $login)) {
            return ['ok' => false, 'error' => 'Login must be 3–50 characters: letters, numbers, underscore only.'];
        }

        $email = strtolower(trim($email));
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Please enter a valid email address.'];
        }

        $pwErrors = Password::validate($password);
        if (!empty($pwErrors)) {
            return ['ok' => false, 'error' => implode(' ', $pwErrors)];
        }
        if ($password !== $confirm) {
            return ['ok' => false, 'error' => 'Passwords do not match.'];
        }

        $_reg_t0 = microtime(true);

        $loginTaken = (bool)DB::val("SELECT id FROM users WHERE login = ?", [$login]);
        $emailTaken = (bool)DB::val("SELECT id FROM users WHERE email = ?", [$email]);

        if ($loginTaken || $emailTaken) {
            self::equalizeRegisterTiming($_reg_t0);
            return [
                'ok'    => false,
                'error' => 'Could not create account with these details. If you already have an account, try signing in instead.',
            ];
        }

        $maxUsers = (int)(DB::val("SELECT value FROM settings WHERE key_name = 'max_users'") ?? 0);
        if ($maxUsers > 0) {
            $userCount = (int)(DB::val("SELECT COUNT(*) FROM users") ?? 0);
            if ($userCount >= $maxUsers) {
                return ['ok' => false, 'error' => 'Registration is currently full. Contact the administrator.'];
            }
        }

        $smtpEnabled   = defined('SMTP_ENABLED') && SMTP_ENABLED;
        $autoVerified  = !$smtpEnabled;
        $activToken    = $autoVerified ? null : bin2hex(random_bytes(32));
        $activExpires  = $autoVerified ? null : date('Y-m-d H:i:s', time() + 172800);
        $passwordHash  = Password::hash($password);

        try {
            DB::run(
                "INSERT INTO users (login, email, password_hash, role, email_verified, activation_token, activation_expires, created_at)
                 VALUES (?, ?, ?, 'user', ?, ?, ?, NOW())",
                [$login, $email, $passwordHash, $autoVerified ? 1 : 0, $activToken, $activExpires]
            );
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                self::equalizeRegisterTiming($_reg_t0);
                return [
                    'ok'    => false,
                    'error' => 'Could not create account with these details. If you already have an account, try signing in instead.',
                ];
            }
            throw $e;
        }

        if (!$autoVerified && $activToken) {
            Mailer::sendActivation($email, $activToken);
        }

        self::equalizeRegisterTiming($_reg_t0);

        return ['ok' => true, 'auto_verified' => $autoVerified];
    }

    private static function equalizeRegisterTiming(float $startTime): void
    {
        $elapsed = microtime(true) - $startTime;
        if ($elapsed < self::REGISTER_TIMING_FLOOR) {
            usleep((int)((self::REGISTER_TIMING_FLOOR - $elapsed) * 1_000_000));
        }
    }

    /**
     * Sesja 079: $trustDevice controls whether a trusted-device record +
     * dv_td cookie is created on success. Only ever created AFTER a real
     * TOTP/backup-code success below — never before it, and never on a
     * failed attempt.
     */
    public static function verify2FA(string $code, bool $trustDevice = false): array
    {
        $user = self::getPartialUser();
        if (!$user) return ['ok' => false, 'error' => 'Session expired. Log in again.'];

        $ip = self::ip();
        if (RateLimit::check('2fa', $ip, 5, 600, 600)) {
            return ['ok' => false, 'error' => 'Too many 2FA attempts. Wait 10 minutes.'];
        }

        if (RateLimit::check('2fa_account', (string)$user['id'], 10, 900, 900)) {
            return ['ok' => false, 'error' => 'Too many 2FA attempts for this account. Please wait 15 minutes.'];
        }

        $secret_enc = $user['totp_secret'] ?? '';
        $totpOk     = false;
        if ($secret_enc) {
            try {
                $totpOk = TOTP::verifyAndConsume(TOTP::decrypt($secret_enc), $code, $user['id']);
            } catch (RuntimeException $e) {
                error_log('[Auth] verify2FA() TOTP::decrypt failed for user ' . $user['id'] . ': ' . $e->getMessage());
            }
        }
        if ($totpOk) {
            RateLimit::clear('2fa', $ip);
            RateLimit::clear('2fa_account', (string)$user['id']);
            self::rotateSessionAfter2FA((int)$user['id']);
            if ($trustDevice) {
                TrustedDevice::create((int)$user['id'], TrustedDevice::labelFromUserAgent(self::ua()), $ip);
            }
            return ['ok' => true];
        }

        if (TOTP::useBackupCode($user['id'], $code)) {
            RateLimit::clear('2fa', $ip);
            RateLimit::clear('2fa_account', (string)$user['id']);
            self::rotateSessionAfter2FA((int)$user['id']);
            if ($trustDevice) {
                TrustedDevice::create((int)$user['id'], TrustedDevice::labelFromUserAgent(self::ua()), $ip);
            }
            return ['ok' => true, 'used_backup' => true];
        }

        DB::run("INSERT INTO login_history (user_id, login_attempt, ip, user_agent, status)
                 VALUES (?, ?, ?, ?, 'fail_2fa')",
            [$user['id'], $user['login'], $ip, self::ua()]
        );
        return ['ok' => false, 'error' => 'Invalid code. Try again.'];
    }

    public static function storeSetupSecret(string $secret): bool
    {
        $sid = self::getSessionId();
        if (!$sid) return false;
        try {
            $encrypted = TOTP::encrypt($secret);
        } catch (RuntimeException $e) {
            error_log('[Auth] storeSetupSecret() TOTP::encrypt failed: ' . $e->getMessage());
            return false;
        }
        DB::run("UPDATE sessions SET pending_totp = ? WHERE id = ?",
            [$encrypted, $sid]);
        return true;
    }

    public static function getSetupSecret(): ?string
    {
        $sid = self::getSessionId();
        if (!$sid) return null;
        $enc = DB::val("SELECT pending_totp FROM sessions WHERE id = ?", [$sid]);
        if (!$enc) return null;
        try {
            return TOTP::decrypt($enc);
        } catch (RuntimeException $e) {
            error_log('[Auth] getSetupSecret() TOTP::decrypt failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Sesja 079: $trustDevice mirrors verify2FA()'s parameter — a user
     * completing INITIAL 2FA setup can also trust the device they just
     * set it up on in the same step.
     */
    public static function enable2FA(string $code, bool $trustDevice = false): array
    {
        $user = self::getPartialUser();
        if (!$user) return ['ok' => false, 'error' => 'Session expired.'];

        $secret = self::getSetupSecret();
        if (!$secret) return ['ok' => false, 'error' => 'Setup session expired. Start again.'];

        if (!TOTP::verifyAndConsume($secret, $code, $user['id'])) {
            return ['ok' => false, 'error' => 'Invalid code. Check your authenticator app.'];
        }

        try {
            $encryptedSecret = TOTP::encrypt($secret);
        } catch (RuntimeException $e) {
            error_log('[Auth] enable2FA() TOTP::encrypt failed for user ' . $user['id'] . ': ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not complete two-factor setup right now. Please try again or contact your administrator.'];
        }

        DB::run("UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?",
            [$encryptedSecret, $user['id']]);

        DB::run("DELETE FROM totp_backup_codes WHERE user_id = ?", [$user['id']]);
        $codes = [];
        $stmt  = DB::get()->prepare("INSERT INTO totp_backup_codes (user_id, code_hash) VALUES (?, ?)");
        for ($i = 0; $i < 10; $i++) {
            $raw     = strtoupper(bin2hex(random_bytes(4))) . '-' . strtoupper(bin2hex(random_bytes(4)));
            $codes[] = $raw;
            $stmt->execute([$user['id'], password_hash($raw, PASSWORD_BCRYPT, ['cost' => 10])]);
        }

        self::rotateSessionAfter2FA((int)$user['id']);

        if ($trustDevice) {
            TrustedDevice::create((int)$user['id'], TrustedDevice::labelFromUserAgent(self::ua()), self::ip());
        }

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

    /**
     * Sesja 079: also revokes every trusted device for this user.
     * Every existing call site (password change, admin force-reset, email
     * change confirmation, password-reset-via-email) gets this for free —
     * a device trusted to skip 2FA must not remain trusted once whatever
     * credential this method is protecting has just been rotated.
     */
    public static function logoutAllSessions(int $userId): void
    {
        DB::run("DELETE FROM sessions        WHERE user_id = ?", [$userId]);
        DB::run("DELETE FROM remember_tokens WHERE user_id = ?", [$userId]);
        TrustedDevice::deleteAllForUser($userId);
    }

    public static function logoutEveryone(): void
    {
        DB::run("DELETE FROM sessions");
        DB::run("DELETE FROM remember_tokens");
        DB::run("DELETE FROM trusted_devices");
    }

    public static function getSessionId(): ?string { return self::$sessionId; }

    // ── Session Helpers ───────────────────────────────────────────────────────

    private static function createSession(int $userId, int $totpVerified = 0): string
    {
        $lifetime = (int)(DB::val("SELECT value FROM settings WHERE key_name = 'session_lifetime'") ?? SESSION_TTL);

        if (!$totpVerified) {
            $lifetime = min($lifetime, 900); // 15 minutes — pending-2FA cap, SEC-129a
        }

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

    /**
     * SEC-149: rotate the session token at the exact instant 2FA succeeds
     * — see original docblock. Unchanged by sesja 079 except that its
     * callers now also optionally create a trusted-device record right
     * after this returns.
     */
    private static function rotateSessionAfter2FA(int $userId): void
    {
        $oldSessionId = self::$sessionId;

        $rawToken = self::createSession($userId, 1);
        self::setSessionCookie($rawToken, 1);
        self::$sessionId = hash('sha256', $rawToken);

        if ($oldSessionId) {
            DB::run("DELETE FROM sessions WHERE id = ?", [$oldSessionId]);
        }
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

    private static function setSessionCookie(string $rawToken, int $totpVerified = 0): void
    {
        $lifetime = (int)(DB::val("SELECT value FROM settings WHERE key_name = 'session_lifetime'") ?? SESSION_TTL);
        if (!$totpVerified) {
            $lifetime = min($lifetime, 900);
        }
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
        // Note: dv_td (trusted device) is deliberately NOT cleared on a
        // plain sign-out — trust is a property of the DEVICE, not the
        // session, exactly like remember-me. It is only ever removed by
        // explicit revocation (Settings) or by logoutAllSessions().
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

    /**
     * Sesja 079: if the account has 2FA enabled but this device is
     * trusted (TrustedDevice::verify()), the resulting session is created
     * FULLY verified instead of returning null — this is what actually
     * fixes "remember-me always re-asks for a 2FA code". Password is
     * still never skipped: this whole method only ever runs because the
     * dv_r remember-me token itself already proved the account, and even
     * then only a NEW session is minted here, not a bypass of login().
     */
    private static function loginFromRemember(string $cookie): ?array
    {
        if (!str_contains($cookie, ':')) return null;
        [$selector, $verifier_b64] = explode(':', $cookie, 2);

        $ip = self::ip();
        if (RateLimit::check('remember_login', $ip, 30, 600, 600)) {
            return null;
        }

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

        if (RateLimit::check('remember_login_account', (string)$row['user_id'], 30, 900, 900)) {
            return null;
        }

        DB::run("DELETE FROM remember_tokens WHERE id = ?", [$row['id']]);
        $user = self::fetchUser($row['user_id']);
        if (!$user) return null;

        // Sesja 079: trusted device skips the 2FA prompt here too.
        $deviceTrusted = $user['totp_enabled'] && TrustedDevice::verify((int)$user['id']);
        $totp_verified = (!$user['totp_enabled'] || $deviceTrusted) ? 1 : 0;

        $raw_token = self::createSession($user['id'], $totp_verified);

        self::setSessionCookie($raw_token, $totp_verified);
        self::createRememberToken($user['id']);

        self::$sessionId = hash('sha256', $raw_token);

        DB::run("UPDATE users SET last_login = NOW() WHERE id = ?", [$user['id']]);

        DB::run("INSERT INTO login_history (user_id, login_attempt, ip, user_agent, status)
                 VALUES (?, ?, ?, ?, 'success')",
            [$user['id'], $user['login'], $ip, self::ua()]
        );

        if ($user['totp_enabled'] && !$deviceTrusted) {
            return null;
        }

        return $user;
    }
}
