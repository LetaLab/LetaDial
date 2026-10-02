<?php
/**
 * LetaDial — Admin Model (sesja 065 + 066 + 067 + 068 + 069 + 071b + 077 + 078 + 079 + SEC-079)
 *
 * Static methods for the admin panel.
 * 065: Blocked IPs, Users, Login History, Install Check, Export
 * 066: Sessions management, Force Password Reset
 * 067: Invite User (send setup-account link to new user email)
 * 068: Registration toggle (registration_enabled setting)
 * 069: Direct user creation (admin sets login + email + password + role immediately)
 * 071b: installCheck — 3 nowe kolumny theme_*_primary
 * 077: installCheck — dodano pages/bookmarklet_page.php do listy integralności plików
 * 078: getUsers() zwraca avatar_path; deleteUser() usuwa plik avatara;
 *      installCheck() — dodano src/avatar_src.php + api/avatar_api.php do listy integralności
 * 079: installCheck() — dodano tabelę trusted_devices do sprawdzania schematu bazy
 *      oraz src/trusted_device_src.php do listy integralności plików. Trusted
 *      Device nie dodaje żadnego nowego katalogu w storage/ (dane trzymane
 *      wyłącznie w DB), więc LetaDial_Permissions.sh nie wymaga zmian.
 * SEC-079: fix_permissions.sh usunięty z repo i z listy integralności — patrz
 *      README → Permissions. installCheck() — dodano: wykrywanie katalogów
 *      world-writable, diagnostyka właściciela plików, weryfikacja
 *      git remote "origin" (musi być zawsze github.com/LetaLab/LetaDial).
 * SEC-110: createUser() i inviteUser() — INSERT do users owinięty w
 *      try/catch(PDOException). Poprzedzające go SELECT-owe sprawdzenia
 *      unikalności login/email nie są atomowe z samym INSERT-em (ten sam
 *      wyścig co w Auth::register() — pełne uzasadnienie w docblocku
 *      auth_src.php); bez catch-a `uq_login`/`uq_email` UNIQUE KEY (install.php)
 *      zamieniał kolizję w nieobsłużony PDOException zamiast czytelnej
 *      odpowiedzi błędu.
 * SEC-151 (SEC_AND_BUG_ANIH_PLAN.md, Czesc XVII): installCheck() — nowy
 *      check w grupie "Security" ostrzegający, jeśli display_errors jest
 *      włączone na hostingu. Uzupełnia (nie zastępuje) nowy globalny
 *      set_exception_handler() w index.php — ten sam problem od strony
 *      wykrywania w panelu admina, nie tylko od strony samego kodu.
 * SEC-156 (SEC_AND_BUG_ANIH_PLAN.md, Czesc XVIII): exportBlocked() —
 *      dodano parametr $min (domyslnie 3, jak w getBlocked()) i twardy
 *      LIMIT 5000 jako niezalezny backstop. Wczesniej eksport nie mial
 *      ani filtra, ani LIMIT — zwracal doslownie kazdy wiersz
 *      rate_limits, wlacznie ze zwyklymi, jednorazowymi wpisami z
 *      bucketow uzytkowych (dial_mutate, settings_mutate, ...), mimo ze
 *      SEC-144 dodalo juz rate limit na samo wywolanie endpointu.
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

class Admin
{
    // SEC-079: fix_permissions.sh nie istnieje już w repo — utrzymanie
    // uprawnień jest teraz w całości poza gitem. Ten hint jest pokazywany
    // w installCheck() przy każdym problemie z uprawnieniami plików/katalogów.
    private const PERMS_FIX_HINT = 'Run: sudo /usr/sbin/LetaDial_Permissions.sh (see README → Permissions)';

    // ── Blocked IPs (Rate Limits) ─────────────────────────────────────────────

    public static function getBlocked(int $min = 3): array
    {
        $rows = DB::rows(
            "SELECT rl.id, rl.key_hash, rl.action, rl.attempts, rl.window_start,
                    rl.key_plain,
                    (SELECT login_attempt FROM login_history
                     WHERE ip = rl.key_plain ORDER BY created_at DESC LIMIT 1) AS last_login_attempt,
                    (SELECT user_agent FROM login_history
                     WHERE ip = rl.key_plain ORDER BY created_at DESC LIMIT 1) AS last_ua
             FROM rate_limits rl
             WHERE rl.attempts >= ?
             ORDER BY rl.attempts DESC, rl.window_start DESC",
            [$min]
        );
        return $rows ?: [];
    }

    public static function unblock(string $keyHash, string $action): bool
    {
        $affected = DB::run(
            "DELETE FROM rate_limits WHERE key_hash = ? AND action = ?",
            [$keyHash, $action]
        );
        return $affected > 0;
    }

    public static function unblockByKey(string $keyPlain): int
    {
        return DB::run(
            "DELETE FROM rate_limits WHERE key_plain = ?",
            [$keyPlain]
        );
    }

    /**
     * SEC-156 (SEC_AND_BUG_ANIH_PLAN.md, Czesc XVIII): $min and the hard
     * LIMIT below are both new. Before this, exportBlocked() had no
     * filter and no LIMIT at all - SEC-144's own rate limit
     * (admin_export, 30/h/admin) only bounds how often the ENDPOINT can
     * be called, not how many rows a single call serializes. Unlike
     * getBlocked() (used by the on-screen "Blocked IPs" table, which
     * already filters attempts >= $min), this method dumped literally
     * every row in rate_limits - including ordinary, non-suspicious,
     * single-attempt bookkeeping rows from routine usage buckets
     * (dial_mutate, settings_mutate, group_mutate, admin_mutate,
     * trusted_device, ... keyed by a plain numeric user_id or a plain
     * IP, not anything blocked or suspicious) mixed in with genuinely
     * blocked entries, inconsistent with the tab's own "Blocked IPs"
     * framing and its own visible "min attempts" filter. $min defaults
     * to 3, matching getBlocked()'s own default, and admin_api.php now
     * passes through whatever the admin currently has the on-screen
     * filter set to, so the export matches what they're looking at. The
     * LIMIT is a second, independent backstop (same "filter as the main
     * control, hard cap as a belt-and-suspenders ceiling" pattern already
     * used for the 10MB CSP log cap in SEC-093 and the 512MB Imagick
     * resource limits in SEC-090) - it bounds worst-case size even if
     * $min is ever passed as 0/1 from a future caller.
     */
    public static function exportBlocked(string $format, int $min = 3): string
    {
        $min  = max(0, $min);
        $rows = DB::rows(
            "SELECT key_plain, action, attempts, window_start FROM rate_limits
             WHERE attempts >= ?
             ORDER BY attempts DESC
             LIMIT 5000",
            [$min]
        );

        if ($format === 'csv') {
            $out = "ip_or_key,action,attempts,window_start\n";
            foreach ($rows as $r) {
                // SEC-122: key_plain can contain arbitrary attacker text
                // (see sanitizeCsvField() docblock below) - neutralize
                // before writing to CSV, not just before writing to the
                // DB. A $min of 0 (an admin can still choose that from the
                // UI) still lets a single, first-ever attempt appear here,
                // same as it always could on the on-screen table at min=0.
                $out .= implode(',', [
                    '"' . str_replace('"', '""', self::sanitizeCsvField($r['key_plain'])) . '"',
                    '"' . str_replace('"', '""', self::sanitizeCsvField($r['action']))    . '"',
                    (int)$r['attempts'],
                    '"' . ($r['window_start'] ?? '') . '"',
                ]) . "\n";
            }
            return $out;
        }

        return json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * SEC-122: neutralize a leading formula/DDE trigger character before a
     * value is written into a CSV cell. Spreadsheet applications (Excel,
     * LibreOffice, Google Sheets) treat a cell starting with =, +, -, @,
     * or a leading TAB/CR as a formula to evaluate, not as literal text.
     *
     * key_plain for the 'login_account' rate limit bucket (see
     * Auth::login() in auth_src.php) stores the raw, attacker-controlled
     * 'login' field from an anonymous, unauthenticated POST /login attempt
     * - a single failed attempt is enough to write an arbitrary string
     * here, and unlike getBlocked() (used by the HTML table, filtered to
     * $min attempts), exportBlocked() has no minimum-attempts filter, so
     * the value is exported on the very first attempt. An admin opening
     * the resulting CSV in a spreadsheet application would have that
     * formula evaluate on their own machine.
     *
     * Prefixing a single quote forces every major spreadsheet application
     * to render the value as plain text instead of evaluating it, without
     * changing what the cell visibly displays (the leading quote is not
     * shown).
     *
     * Deliberately NOT applied at write time (RateLimit::check()) or to
     * the JSON export: admins legitimately need to see the exact login
     * string being attacked in both places (unlike the SEC-117 case,
     * which hides genuine secrets from key_plain entirely), and JSON has
     * no formula-evaluation semantics to protect against.
     */
    private static function sanitizeCsvField(?string $value): string
    {
        $value = (string)($value ?? '');
        if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) {
            $value = "'" . $value;
        }
        return $value;
    }

    // ── Users ─────────────────────────────────────────────────────────────────

    public static function getUsers(): array
    {
        return DB::rows(
            "SELECT u.id, u.login, u.email, u.role, u.totp_enabled, u.email_verified,
                    u.avatar_path, u.created_at, u.last_login,
                    (SELECT COUNT(*) FROM groups_list g WHERE g.user_id = u.id) AS group_count,
                    (SELECT COUNT(*) FROM dials d WHERE d.user_id = u.id) AS dial_count,
                    (SELECT COUNT(*) FROM sessions s WHERE s.user_id = u.id AND s.expires_at > NOW()) AS session_count,
                    (SELECT COUNT(*) FROM trusted_devices t WHERE t.user_id = u.id AND t.expires_at > NOW()) AS trusted_device_count
             FROM users u
             ORDER BY u.created_at DESC"
        ) ?: [];
    }

    public static function deleteUser(int $userId, int $adminId): array
    {
        if ($userId === $adminId) {
            return ['ok' => false, 'error' => 'Cannot delete your own account.'];
        }

        $user = DB::row("SELECT id, login FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            return ['ok' => false, 'error' => 'User not found.'];
        }

        // Delete thumbnail files
        $thumbDir = __DIR__ . '/../storage/thumbnails/u' . $userId;
        if (is_dir($thumbDir)) {
            array_map('unlink', glob($thumbDir . '/*.webp') ?: []);
            @rmdir($thumbDir);
        }

        // Delete group icon files
        $iconDir = __DIR__ . '/../storage/group_icons/u' . $userId;
        if (is_dir($iconDir)) {
            array_map('unlink', glob($iconDir . '/*.webp') ?: []);
            @rmdir($iconDir);
        }

        // Delete avatar file — sesja 078 (single file, not a per-user directory)
        $avatarFile = __DIR__ . '/../storage/avatars/u' . $userId . '.webp';
        if (is_file($avatarFile)) {
            @unlink($avatarFile);
        }

        // Delete user (cascades to sessions, dials, groups, backup codes,
        // remember tokens, AND trusted_devices — sesja 079, ON DELETE
        // CASCADE in install.php's schema — nothing extra to do here).
        DB::run("DELETE FROM users WHERE id = ?", [$userId]);

        return ['ok' => true, 'login' => $user['login']];
    }

    // ── Direct User Creation (sesja 069) ──────────────────────────────────────

    public static function createUser(
        string $login,
        string $email,
        string $password,
        string $role,
        int    $adminId
    ): array {
        $login = trim($login);
        $email = strtolower(trim($email));
        $role  = in_array($role, ['user', 'admin'], true) ? $role : 'user';

        if (!$login) {
            return ['ok' => false, 'error' => 'Login is required.'];
        }
        if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $login)) {
            return ['ok' => false, 'error' => 'Login must be 3–50 characters: letters, numbers, underscore only.'];
        }

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Please enter a valid email address.'];
        }

        $pwErrors = Password::validate($password);
        if (!empty($pwErrors)) {
            return ['ok' => false, 'error' => implode(' ', $pwErrors)];
        }

        $loginTaken = DB::val("SELECT id FROM users WHERE login = ?", [$login]);
        if ($loginTaken) {
            return ['ok' => false, 'error' => 'This login is already taken.'];
        }

        $emailTaken = DB::val("SELECT id FROM users WHERE email = ?", [$email]);
        if ($emailTaken) {
            return ['ok' => false, 'error' => 'This email address is already registered.'];
        }

        $hash = Password::hash($password);

        // SEC-110: the two SELECT pre-checks above are not atomic with this
        // INSERT — same class of race as Auth::register() (see its docblock
        // for the full rationale). Lower real-world odds here, since this
        // endpoint requires an authenticated admin rather than an anonymous
        // visitor, but an admin double-clicking "Create user", or two admins
        // acting at once, can still hit it. Without this catch, the
        // uq_login/uq_email UNIQUE KEY (install.php) would turn that race
        // into an uncaught PDOException instead of the normal error
        // response.
        try {
            DB::run(
                "INSERT INTO users
                    (login, email, password_hash, role, email_verified, activation_token,
                     totp_required, created_at)
                 VALUES (?, ?, ?, ?, 1, NULL, ?, NOW())",
                [$login, $email, $hash, $role, ($role === 'admin') ? 1 : 0]
            );
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'error' => 'This login or email address was just taken by another request. Please try again.'];
            }
            throw $e; // any other DB error stays a real, loud failure
        }

        $newUserId = (int)DB::lastId();

        return [
            'ok'      => true,
            'user_id' => $newUserId,
            'login'   => $login,
            'role'    => $role,
        ];
    }

    // ── Registration Toggle (sesja 068) ───────────────────────────────────────

    public static function getRegistrationEnabled(): bool
    {
        $val = DB::val("SELECT value FROM settings WHERE key_name = 'registration_enabled'");
        return ($val ?? '1') === '1';
    }

    public static function setRegistrationEnabled(bool $enabled): bool
    {
        DB::run(
            "INSERT INTO settings (key_name, value) VALUES ('registration_enabled', ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)",
            [$enabled ? '1' : '0']
        );
        return $enabled;
    }

    // ── Sessions (066) ────────────────────────────────────────────────────────

    public static function getSessions(?int $filterUserId = null): array
    {
        if ($filterUserId !== null) {
            return DB::rows(
                "SELECT s.id, s.user_id, s.ip, s.user_agent, s.created_at, s.last_activity,
                        s.expires_at, s.totp_verified, u.login, u.role
                 FROM sessions s
                 JOIN users u ON u.id = s.user_id
                 WHERE s.expires_at > NOW() AND s.user_id = ?
                 ORDER BY s.last_activity DESC",
                [$filterUserId]
            ) ?: [];
        }

        return DB::rows(
            "SELECT s.id, s.user_id, s.ip, s.user_agent, s.created_at, s.last_activity,
                    s.expires_at, s.totp_verified, u.login, u.role
             FROM sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.expires_at > NOW()
             ORDER BY s.last_activity DESC"
        ) ?: [];
    }

    public static function deleteSession(string $sessionId): bool
    {
        return DB::run("DELETE FROM sessions WHERE id = ?", [$sessionId]) > 0;
    }

    public static function deleteUserSessions(int $userId): int
    {
        return DB::run("DELETE FROM sessions WHERE user_id = ?", [$userId]);
    }

    // ── Trusted Devices (sesja 079) ───────────────────────────────────────────

    /**
     * List every trusted device across every user, for a future admin-facing
     * view. Not yet wired into a tab in admin_page.php — getSessions() above
     * covers the equivalent, more urgent "who is currently logged in"
     * question; this is exposed here so a future session can add an
     * admin-side "Trusted Devices" table (e.g. next to Sessions) without
     * needing a new model method. Left unused for now is intentional: adding
     * UI for it is out of scope for sesja 079, which focuses on the
     * user-facing Settings → Trusted Devices flow.
     */
    public static function getTrustedDevices(?int $filterUserId = null): array
    {
        if ($filterUserId !== null) {
            return DB::rows(
                "SELECT t.id, t.user_id, t.label, t.ip, t.created_at, t.last_used_at, t.expires_at, u.login
                 FROM trusted_devices t
                 JOIN users u ON u.id = t.user_id
                 WHERE t.expires_at > NOW() AND t.user_id = ?
                 ORDER BY t.last_used_at DESC",
                [$filterUserId]
            ) ?: [];
        }

        return DB::rows(
            "SELECT t.id, t.user_id, t.label, t.ip, t.created_at, t.last_used_at, t.expires_at, u.login
             FROM trusted_devices t
             JOIN users u ON u.id = t.user_id
             WHERE t.expires_at > NOW()
             ORDER BY t.last_used_at DESC"
        ) ?: [];
    }

    public static function deleteUserTrustedDevices(int $userId): int
    {
        return DB::run("DELETE FROM trusted_devices WHERE user_id = ?", [$userId]);
    }

    // ── Force Password Reset (066) ────────────────────────────────────────────

    public static function forcePasswordReset(int $targetId, string $password, int $adminId): array
    {
        if ($targetId === $adminId) {
            return ['ok' => false, 'error' => 'Use the Settings page to change your own password.'];
        }

        $target = DB::row("SELECT id, login FROM users WHERE id = ?", [$targetId]);
        if (!$target) {
            return ['ok' => false, 'error' => 'User not found.'];
        }

        $errors = Password::validate($password);
        if (!empty($errors)) {
            return ['ok' => false, 'error' => implode(' ', $errors)];
        }

        $hash = Password::hash($password);
        DB::run("UPDATE users SET password_hash = ? WHERE id = ?", [$hash, $targetId]);

        // Sesja 079: Auth::logoutAllSessions() also revokes every trusted
        // device for $targetId — a device trusted to skip 2FA must not
        // remain trusted once an admin has just force-reset the password
        // behind it.
        Auth::logoutAllSessions($targetId);

        return ['ok' => true, 'login' => $target['login']];
    }

    // ── Invite User (067) ─────────────────────────────────────────────────────

    public static function inviteUser(string $email, string $login, int $adminId): array
    {
        $email = strtolower(trim($email));
        $login = trim($login);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Invalid email address.'];
        }

        if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $login)) {
            return ['ok' => false, 'error' => 'Login must be 3–50 characters: letters, numbers, underscore only.'];
        }

        $emailTaken = DB::val("SELECT id FROM users WHERE email = ?", [$email]);
        if ($emailTaken) {
            return ['ok' => false, 'error' => 'This email address is already registered.'];
        }

        $loginTaken = DB::val("SELECT id FROM users WHERE login = ?", [$login]);
        if ($loginTaken) {
            return ['ok' => false, 'error' => 'This login is already taken.'];
        }

        $admin      = DB::row("SELECT login FROM users WHERE id = ?", [$adminId]);
        $adminLogin = $admin['login'] ?? 'Admin';

        $token = bin2hex(random_bytes(32));

        $dummyHash = '$2y$12$InvalidHashThatCanNeverMatchAnyRealPassword00000000000000';

        // SEC-110: same non-atomic SELECT-then-INSERT race as createUser()
        // above and Auth::register() — see auth_src.php's docblock for the full
        // rationale. Without this catch, the uq_login/uq_email UNIQUE KEY
        // (install.php) would turn a concurrent collision into an uncaught
        // PDOException instead of the normal error response.
        try {
            DB::run(
                "INSERT INTO users
                    (login, email, password_hash, role, email_verified, activation_token,
                     totp_required, created_at)
                 VALUES (?, ?, ?, 'user', 0, ?, 0, NOW())",
                [$login, $email, $dummyHash, $token]
            );
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'error' => 'This login or email address was just taken by another request. Please try again.'];
            }
            throw $e; // any other DB error stays a real, loud failure
        }

        $newUserId = (int)DB::lastId();

        $sent = false;
        if (defined('SMTP_ENABLED') && SMTP_ENABLED) {
            $sent = Mailer::sendInviteToSetup($email, $token, $adminLogin);
        }

        return [
            'ok'           => true,
            'user_id'      => $newUserId,
            'email_sent'   => $sent,
            'smtp_enabled' => defined('SMTP_ENABLED') && SMTP_ENABLED,
        ];
    }

    // ── Login History ─────────────────────────────────────────────────────────

    public static function getLoginHistory(?string $ip, int $limit = 100): array
    {
        $limit = max(10, min(500, $limit));

        if ($ip) {
            return DB::rows(
                "SELECT lh.*, u.login AS resolved_login
                 FROM login_history lh
                 LEFT JOIN users u ON u.id = lh.user_id
                 WHERE lh.ip = ?
                 ORDER BY lh.created_at DESC
                 LIMIT " . $limit,
                [$ip]
            ) ?: [];
        }

        return DB::rows(
            "SELECT lh.*, u.login AS resolved_login
             FROM login_history lh
             LEFT JOIN users u ON u.id = lh.user_id
             ORDER BY lh.created_at DESC
             LIMIT " . $limit
        ) ?: [];
    }

    // ── Git remote verification (SEC-079) ───────────────────────────────────────
    //
    // Read-only. Used only by installCheck() to warn if 'origin' is ever
    // something other than the official public GitHub repo — gitPull()
    // always pulls from 'origin' by name, so a misconfigured remote would
    // silently change where "Update now" fetches code from.

    private static function execAvailable(): bool
    {
        if (!function_exists('exec')) return false;
        $disabled = array_map('trim', explode(',', ini_get('disable_functions') ?: ''));
        return !in_array('exec', $disabled, true);
    }

    private static function gitRemoteOriginUrl(string $dir): ?string
    {
        if (!self::execAvailable()) return null;
        $dirEsc = escapeshellarg($dir);
        $output = [];
        $return = 1;
        exec("git -C {$dirEsc} remote get-url origin 2>&1", $output, $return);
        if ($return !== 0 || empty($output)) return null;
        return trim(implode('', $output));
    }

    // ── Install Check ─────────────────────────────────────────────────────────

    public static function installCheck(): array
    {
        $checks = [];
        $appDir = realpath(__DIR__ . '/..') ?: dirname(__DIR__);

        // ── PHP ───────────────────────────────────────────────────────────────
        $phpVer = PHP_VERSION;
        $checks[] = self::chk('PHP ≥ 8.1', version_compare($phpVer, '8.1.0', '>='), true,
            "Found: {$phpVer}", 'PHP');

        foreach (['pdo_mysql', 'gd', 'mbstring', 'openssl', 'json'] as $ext) {
            $checks[] = self::chk("Extension: {$ext}", extension_loaded($ext), true,
                extension_loaded($ext) ? 'loaded' : 'MISSING', 'PHP');
        }

        $gdInfo  = function_exists('gd_info') ? gd_info() : [];
        $webpOk  = !empty($gdInfo['WebP Support']);
        $checks[] = self::chk('GD WebP support', $webpOk, true,
            $webpOk ? 'yes' : 'MISSING — install php-gd with WebP', 'PHP');

        $imagick = extension_loaded('imagick');
        $checks[] = self::chk('Imagick extension', $imagick, false,
            $imagick ? 'loaded (better thumbnails)' : 'not installed (optional)', 'PHP',
            'Imagick enables OG image capture and better thumbnail quality.');

        $exif = extension_loaded('exif');
        $checks[] = self::chk('exif extension', $exif, false,
            $exif ? 'loaded (avatar auto-rotation)' : 'not installed (optional)', 'PHP',
            'Used only to auto-correct avatar photo orientation from phone cameras. Avatar upload works without it — photos just keep their original orientation.');

        $execOk = function_exists('exec') && !in_array('exec', explode(',', ini_get('disable_functions') ?: ''));
        $checks[] = self::chk('exec() available', $execOk, false,
            $execOk ? 'yes' : 'disabled — git-based auto-update will not work', 'PHP',
            'Required for Admin → Update tab (git pull). Not needed for normal operation.');

        // ── Database ──────────────────────────────────────────────────────────
        // Sesja 079: trusted_devices added to this list.
        $tables = ['users','sessions','remember_tokens','trusted_devices','groups_list','dials',
                   'totp_backup_codes','rate_limits','settings','login_history'];
        foreach ($tables as $tbl) {
            $exists = DB::val(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$tbl]
            ) !== null;
            $note = ($tbl === 'trusted_devices' && !$exists)
                ? 'New in sesja 079. Existing installs must create it manually — see PROJECT_080.md, "Migracja dla istniejących instalacji".'
                : '';
            $checks[] = self::chk("Table: {$tbl}", $exists, true,
                $exists ? 'exists' : 'MISSING', 'Database', $note);
        }

        // Key columns
        $colChecks = [
            ['users',       'totp_secret',             'VARCHAR — 2FA support'],
            ['users',       'totp_last_step',           'INT — SEC-080 TOTP replay guard'],
            ['users',       'totp_enabled',             'TINYINT — 2FA flag'],
            ['users',       'avatar_path',              'VARCHAR — sesja 078 avatar'],
            ['users',       'reset_token',              'VARCHAR — password reset'],
            ['users',       'reset_expires',            'DATETIME — password reset expiry'],
            ['users',       'activation_expires',       'DATETIME - SEC-135 activation token expiry'],
            ['users',       'recent_disabled',          'TINYINT — sesja 064'],
            ['users',       'theme',                    'VARCHAR — sesja 071a midnight theme'],
            ['users',       'theme_light_primary',      'VARCHAR(7) — sesja 071b custom color'],
            ['users',       'theme_dark_primary',       'VARCHAR(7) — sesja 071b custom color'],
            ['users',       'theme_midnight_primary',   'VARCHAR(7) — sesja 071b custom color'],
            ['users',       'theme_light_extra',        'TEXT — sesja 072 bg+text extras'],
            ['users',       'theme_dark_extra',         'TEXT — sesja 072 bg+text extras'],
            ['users',       'theme_midnight_extra',     'TEXT — sesja 072 bg+text extras'],
            ['users',       'dial_width',               'SMALLINT — sesja 074 dial size slider'],
            ['users',       'email_pending',            'VARCHAR — sesja 066 email change'],
            ['users',       'email_change_token',       'VARCHAR — sesja 066 email change'],
            ['users',       'email_change_expires',     'DATETIME — sesja 066 email change'],
            ['sessions',    'totp_verified',            'TINYINT — 2FA session flag'],
            ['sessions',    'pending_totp',             'VARCHAR — 2FA setup token'],
            ['dials',       'notes',                    'TEXT — sesja 054'],
            ['dials',       'pinned',                   'TINYINT — sesja 061'],
            ['dials',       'click_count',              'INT — click tracking'],
            ['dials',       'last_click',               'DATETIME — recent tab'],
            ['groups_list', 'icon',                     'VARCHAR — emoji icon'],
            ['groups_list', 'color',                    'VARCHAR — tab color'],
            ['groups_list', 'icon_path',                'VARCHAR — custom icon image'],
            ['rate_limits', 'key_plain',                'VARCHAR — admin blocked IPs display'],
            // Sesja 079
            ['trusted_devices', 'selector',      'CHAR(24) — device trust lookup key'],
            ['trusted_devices', 'verifier',      'CHAR(64) — SHA-256 hash of the raw cookie verifier'],
            ['trusted_devices', 'expires_at',    'DATETIME — 180-day trust window'],
        ];

        foreach ($colChecks as [$table, $col, $desc]) {
            $exists = DB::val(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$table, $col]
            ) !== null;
            $label = "Column: {$table}.{$col}";
            $checks[] = self::chk($label, $exists, true,
                $exists ? 'ok' : "MISSING — run migration SQL", 'Database',
                $exists ? '' : "ALTER TABLE {$table} ADD COLUMN {$col} ... — see README Troubleshooting");
        }

        // ── Settings ──────────────────────────────────────────────────────────
        $regEnabled = self::getRegistrationEnabled();
        $checks[] = self::chk('registration_enabled setting', true, false,
            $regEnabled ? 'open (users can self-register)' : 'disabled (invite-only)',
            'Configuration',
            'Toggle in Admin → Users → Registration.');

        // ── Configuration ─────────────────────────────────────────────────────
        $constants = ['APP_NAME','APP_URL','APP_VERSION','ENCRYPTION_KEY','HMAC_KEY',
                      'DB_HOST','DB_NAME','DB_USER','SESSION_TTL'];
        foreach ($constants as $c) {
            $defined = defined($c);
            $checks[] = self::chk("Constant: {$c}", $defined, true,
                $defined ? 'defined' : 'MISSING in config.php', 'Configuration');
        }

        $smtpConfigured = defined('SMTP_ENABLED') && SMTP_ENABLED;
        $checks[] = self::chk('SMTP configured', $smtpConfigured, false,
            $smtpConfigured ? 'yes' : 'disabled — email features unavailable', 'Configuration',
            'Required for password reset, activation emails, and user invites.');

        // ── Security ──────────────────────────────────────────────────────────
        $cfgPath   = $appDir . '/config.php';
        $cfgExists = file_exists($cfgPath);
        $cfgPerms  = $cfgExists ? substr(sprintf('%o', fileperms($cfgPath)), -4) : '????';
        $cfgSafe   = in_array($cfgPerms, ['0600', '0400'], true);
        $checks[] = self::chk('config.php permissions', $cfgSafe, true,
            $cfgExists ? "{$cfgPerms} (should be 0600)" : 'file not found', 'Security',
            $cfgSafe ? '' : 'Run: chmod 600 config.php — or: ' . self::PERMS_FIX_HINT);

        $installExists = file_exists($appDir . '/install.php');
        $checks[] = self::chk('install.php removed', !$installExists, true,
            $installExists ? 'STILL PRESENT — security risk!' : 'not found (good)', 'Security',
            $installExists ? 'Delete immediately: rm install.php — or wait for the next scheduled run of LetaDial_Permissions.sh, if installed' : '');

        $keyLen = defined('ENCRYPTION_KEY') ? strlen(ENCRYPTION_KEY) : 0;
        $checks[] = self::chk('ENCRYPTION_KEY length', $keyLen === 64, true,
            "{$keyLen} chars (must be 64 hex = 32 bytes)", 'Security');

        $hmacLen = defined('HMAC_KEY') ? strlen(HMAC_KEY) : 0;
        $checks[] = self::chk('HMAC_KEY length', $hmacLen === 64, true,
            "{$hmacLen} chars (must be 64 hex = 32 bytes)", 'Security');

        // SEC-079: origin must always be the official public GitHub repo —
        // never a private/self-hosted remote. Skipped entirely (no check
        // added) if exec() is disabled or this isn't a git checkout, since
        // in that case we genuinely cannot know and shouldn't guess.
        $remoteUrl = self::gitRemoteOriginUrl($appDir);
        if ($remoteUrl !== null) {
            $expectedRemotes = [
                'https://github.com/LetaLab/LetaDial',
                'https://github.com/LetaLab/LetaDial.git',
                'git@github.com:LetaLab/LetaDial.git',
            ];
            $remoteOk = in_array(rtrim($remoteUrl, '/'), $expectedRemotes, true);
            $checks[] = self::chk('git remote "origin"', $remoteOk, true,
                $remoteOk ? $remoteUrl : "{$remoteUrl} — UNEXPECTED",
                'Security',
                $remoteOk ? '' : 'origin must be https://github.com/LetaLab/LetaDial.git — fix with: git remote set-url origin https://github.com/LetaLab/LetaDial.git');
        }

        // SEC-151 (SEC_AND_BUG_ANIH_PLAN.md, Czesc XVII): index.php now
        // installs a global set_exception_handler() as a backstop, but
        // display_errors is still the FIRST line of defense against an
        // uncaught error (or plain warning/notice, which the exception
        // handler does not touch) printing file paths, class names, or
        // query fragments straight into the response. Common web-SAPI
        // values are '', '0' (off) or '1' (on); anything else falling
        // through the whitelist below is treated conservatively as "on".
        $displayErrorsRaw = (string)ini_get('display_errors');
        $displayErrorsOff = in_array(strtolower($displayErrorsRaw), ['', '0', 'off', 'no', 'false'], true);
        $checks[] = self::chk('display_errors disabled', $displayErrorsOff, false,
            $displayErrorsOff ? 'disabled (good)' : "enabled ({$displayErrorsRaw}) — may print file paths or stack traces on error",
            'Security',
            $displayErrorsOff ? '' : 'Set display_errors = Off in php.ini for production. index.php\'s global exception handler already limits what an UNCAUGHT exception shows regardless, but a plain PHP warning/notice is not an exception and is unaffected by it — display_errors is still the correct baseline.');

        // ── Filesystem ────────────────────────────────────────────────────────

        // SEC-079: app root itself must not be world-writable.
        $rootMode = @fileperms($appDir);
        if ($rootMode !== false) {
            $rootModeOct = substr(sprintf('%o', $rootMode), -4);
            $rootWorldWr = ($rootMode & 0002) !== 0;
            $checks[] = self::chk('App root not world-writable', !$rootWorldWr, true,
                $rootWorldWr ? "{$rootModeOct} — WORLD-WRITABLE" : "{$rootModeOct} ok",
                'Filesystem',
                $rootWorldWr ? self::PERMS_FIX_HINT : '');
        }

        // Sesja 079: no new storage/ subdirectory needed — trusted device
        // data lives entirely in the trusted_devices table, not on disk —
        // so the directory list below is UNCHANGED from sesja 078.
        $dirs = [
            'storage'             => ['writable' => true,  'required' => true],
            'storage/thumbnails'  => ['writable' => true,  'required' => true],
            'storage/sessions'    => ['writable' => false, 'required' => true],
            'storage/avatars'     => ['writable' => true,  'required' => true],
            'storage/group_icons' => ['writable' => true,  'required' => true],
            'logs'                => ['writable' => true,  'required' => true],
        ];
        foreach ($dirs as $rel => $opts) {
            $full   = $appDir . '/' . $rel;
            $exists = is_dir($full);
            $ok     = $exists && (!$opts['writable'] || is_writable($full));
            $checks[] = self::chk("Dir: {$rel}", $ok, $opts['required'],
                $ok ? ($opts['writable'] ? 'exists + writable' : 'exists') : ($exists ? 'not writable' : 'MISSING'),
                'Filesystem',
                $ok ? '' : self::PERMS_FIX_HINT);

            // SEC-079: flag world-writable dirs — the classic "chmod -R 777
            // to make it work" self-inflicted vulnerability. Any local user
            // or process could write into LetaDial's data directories.
            if ($exists) {
                $mode    = fileperms($full);
                $modeOct = substr(sprintf('%o', $mode), -4);
                $worldWr = ($mode & 0002) !== 0;
                $checks[] = self::chk("Dir: {$rel} not world-writable", !$worldWr, true,
                    $worldWr ? "{$modeOct} — WORLD-WRITABLE" : "{$modeOct} ok",
                    'Filesystem',
                    $worldWr ? self::PERMS_FIX_HINT : '');
            }
        }

        // SEC-079: ownership diagnostic — informational only (is_writable()
        // above is the authoritative required check). Helps distinguish a
        // mode problem from an ownership problem at a glance. Silently
        // skipped if the posix extension isn't loaded.
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $procUid      = posix_geteuid();
            $procInfo     = posix_getpwuid($procUid);
            $procUser     = $procInfo['name'] ?? (string)$procUid;
            $storageOwner = @fileowner($appDir . '/storage');
            if ($storageOwner !== false) {
                $ownerMatch = ($storageOwner === $procUid);
                $ownerInfo  = posix_getpwuid($storageOwner);
                $ownerName  = $ownerInfo['name'] ?? (string)$storageOwner;
                $checks[] = self::chk('storage/ owner matches PHP process user', $ownerMatch, false,
                    $ownerMatch ? $procUser : "owned by {$ownerName}, PHP runs as {$procUser}",
                    'Filesystem',
                    $ownerMatch ? '' : self::PERMS_FIX_HINT);
            }
        }

        // ── File Integrity ────────────────────────────────────────────────────
        $keyFiles = [
            'index.php'                    => true,
            // NAMING: every src/ class file below ends in _src.php, every
            // pages/ template ends in _page.php, every api/ endpoint ends
            // in _api.php — chosen so no two files anywhere in the project
            // can ever collide on a case-insensitive filesystem (Windows/
            // macOS), even though Linux would treat e.g. Admin.php and
            // admin.php as two different files. Class names inside these
            // files are UNCHANGED (still Auth, Admin, CSRF, ... ) — only
            // the filenames moved.
            'src/auth_src.php'             => true,
            'src/db_src.php'               => true,
            'src/csrf_src.php'             => true,
            'src/csp_src.php'              => true,   // BUG-007 — was missing despite being loaded by index.php
            'src/dial_src.php'             => true,
            'src/group_src.php'            => true,
            'src/ssrf_guard_src.php'       => true,   // SEC-153 — Thumbnail and Meta both hard-depend on it now
            'src/thumbnail_src.php'        => true,
            'src/admin_src.php'            => true,
            'src/mailer_src.php'           => true,
            'src/totp_src.php'             => true,
            'src/qr_code_src.php'          => true,   // BUG-025 — used by setup_2fa_page.php, was missing
            'src/rate_limit_src.php'       => true,
            'src/password_src.php'         => true,
            'src/import_src.php'           => true,
            'src/export_src.php'           => true,
            'src/meta_src.php'             => true,
            'src/updater_src.php'          => true,
            'src/group_icon_src.php'       => true,
            'src/avatar_src.php'           => true,   // sesja 078
            'src/trusted_device_src.php'   => true,   // sesja 079
            'pages/login_page.php'         => true,
            'pages/dashboard_page.php'     => true,
            'pages/setup_2fa_page.php'     => true,
            'pages/logout_page.php'        => true,
            'pages/activate_page.php'      => true,
            'pages/admin_page.php'         => true,
            'pages/settings_page.php'      => true,
            'pages/forgot_password_page.php'  => true,
            'pages/reset_password_page.php'   => true,
            'pages/confirm_email_page.php' => true,
            'pages/setup_account_page.php' => true,
            'pages/bookmarklet_page.php'   => true,   // sesja 077
            'pages/not_found_page.php'     => true,   // BUG-025 — 404 handler, was missing
            'api/dial_api.php'             => true,
            'api/group_api.php'            => true,
            'api/thumbnail_api.php'        => true,
            'api/export_api.php'           => true,
            'api/import_api.php'           => true,
            'api/admin_api.php'            => true,
            'api/settings_api.php'         => true,
            'api/updater_api.php'          => true,
            'api/meta_api.php'             => true,
            'api/group_icon_api.php'       => true,
            'api/avatar_api.php'           => true,   // sesja 078
            'api/csp_report_api.php'       => true,   // BUG-007 — CSP-era file, was missing
            'assets/css/app.css'           => true,
            'assets/css/design-system.css' => true,
            'assets/js/app.js'             => true,
            // BUG-007 — 12 per-page stylesheets extracted during the CSP
            // cleanup were never added here; Install Check could report
            // "all OK" even if one had gone missing on the server.
            'assets/css/pages/404.css'             => true,
            'assets/css/pages/activate.css'        => true,
            'assets/css/pages/admin.css'           => true,
            'assets/css/pages/login.css'           => true,
            'assets/css/pages/confirm-email.css'   => true,
            'assets/css/pages/forgot-password.css' => true,
            'assets/css/pages/reset-password.css'  => true,
            'assets/css/pages/settings.css'        => true,
            'assets/css/pages/dashboard.css'       => true,
            'assets/css/pages/bookmarklet.css'     => true,
            'assets/css/pages/setup-2fa.css'       => true,
            'assets/css/pages/setup-account.css'   => true,
        ];
        foreach ($keyFiles as $rel => $required) {
            $exists = file_exists($appDir . '/' . $rel);
            $checks[] = self::chk("File: {$rel}", $exists, $required,
                $exists ? 'present' : ($required ? 'MISSING' : 'not found'), 'File Integrity');
        }

        return $checks;
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private static function chk(
        string $label, bool $ok, bool $required,
        string $value = '', string $group = 'General', string $note = ''
    ): array {
        return compact('label', 'ok', 'required', 'value', 'group', 'note');
    }
}
