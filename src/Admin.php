<?php
/**
 * LetaDial — Admin Model (sesja 065 + 066 + 067 + 068 + 069 + 071b + 077 + 078 + SEC-079)
 *
 * Static methods for the admin panel.
 * 065: Blocked IPs, Users, Login History, Install Check, Export
 * 066: Sessions management, Force Password Reset
 * 067: Invite User (send setup-account link to new user email)
 * 068: Registration toggle (registration_enabled setting)
 * 069: Direct user creation (admin sets login + email + password + role immediately)
 * 071b: installCheck — 3 nowe kolumny theme_*_primary
 * 077: installCheck — dodano pages/bookmarklet.php do listy integralności plików
 * 078: getUsers() zwraca avatar_path; deleteUser() usuwa plik avatara;
 *      installCheck() — dodano src/Avatar.php + api/avatars.php do listy integralności
 * SEC-079: fix_permissions.sh usunięty z repo i z listy integralności — patrz
 *      README → Permissions. installCheck() — dodano: wykrywanie katalogów
 *      world-writable, diagnostyka właściciela plików, weryfikacja
 *      git remote "origin" (musi być zawsze github.com/LetaLab/LetaDial).
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

    public static function exportBlocked(string $format): string
    {
        $rows = DB::rows(
            "SELECT key_plain, action, attempts, window_start FROM rate_limits ORDER BY attempts DESC"
        );

        if ($format === 'csv') {
            $out = "ip_or_key,action,attempts,window_start\n";
            foreach ($rows as $r) {
                $out .= implode(',', [
                    '"' . str_replace('"', '""', $r['key_plain'] ?? '') . '"',
                    '"' . str_replace('"', '""', $r['action']) . '"',
                    (int)$r['attempts'],
                    '"' . ($r['window_start'] ?? '') . '"',
                ]) . "\n";
            }
            return $out;
        }

        return json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    // ── Users ─────────────────────────────────────────────────────────────────

    public static function getUsers(): array
    {
        return DB::rows(
            "SELECT u.id, u.login, u.email, u.role, u.totp_enabled, u.email_verified,
                    u.avatar_path, u.created_at, u.last_login,
                    (SELECT COUNT(*) FROM groups_list g WHERE g.user_id = u.id) AS group_count,
                    (SELECT COUNT(*) FROM dials d WHERE d.user_id = u.id) AS dial_count,
                    (SELECT COUNT(*) FROM sessions s WHERE s.user_id = u.id AND s.expires_at > NOW()) AS session_count
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

        // Delete user (cascades to sessions, dials, groups, backup codes, remember tokens)
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

        DB::run(
            "INSERT INTO users
                (login, email, password_hash, role, email_verified, activation_token,
                 totp_required, created_at)
             VALUES (?, ?, ?, ?, 1, NULL, ?, NOW())",
            [$login, $email, $hash, $role, ($role === 'admin') ? 1 : 0]
        );

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

        DB::run(
            "INSERT INTO users
                (login, email, password_hash, role, email_verified, activation_token,
                 totp_required, created_at)
             VALUES (?, ?, ?, 'user', 0, ?, 0, NOW())",
            [$login, $email, $dummyHash, $token]
        );

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
        $tables = ['users','sessions','remember_tokens','groups_list','dials',
                   'totp_backup_codes','rate_limits','settings','login_history'];
        foreach ($tables as $tbl) {
            $exists = DB::val(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$tbl]
            ) !== null;
            $checks[] = self::chk("Table: {$tbl}", $exists, true,
                $exists ? 'exists' : 'MISSING', 'Database');
        }

        // Key columns
        $colChecks = [
            ['users',       'totp_secret',             'VARCHAR — 2FA support'],
            ['users',       'totp_enabled',             'TINYINT — 2FA flag'],
            ['users',       'avatar_path',              'VARCHAR — sesja 078 avatar'],
            ['users',       'reset_token',              'VARCHAR — password reset'],
            ['users',       'reset_expires',            'DATETIME — password reset expiry'],
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
            'src/Auth.php'                 => true,
            'src/DB.php'                   => true,
            'src/CSRF.php'                 => true,
            'src/Dial.php'                 => true,
            'src/Group.php'                => true,
            'src/Thumbnail.php'            => true,
            'src/Admin.php'                => true,
            'src/Mailer.php'               => true,
            'src/TOTP.php'                 => true,
            'src/RateLimit.php'            => true,
            'src/Password.php'             => true,
            'src/Import.php'               => true,
            'src/Export.php'               => true,
            'src/Meta.php'                 => true,
            'src/Updater.php'              => true,
            'src/GroupIcon.php'            => true,
            'src/Avatar.php'               => true,   // sesja 078
            'pages/login.php'              => true,
            'pages/dashboard.php'          => true,
            'pages/setup-2fa.php'          => true,
            'pages/logout.php'             => true,
            'pages/activate.php'           => true,
            'pages/admin.php'              => true,
            'pages/settings.php'           => true,
            'pages/forgot-password.php'    => true,
            'pages/reset-password.php'     => true,
            'pages/confirm-email.php'      => true,
            'pages/setup-account.php'      => true,
            'pages/bookmarklet.php'        => true,   // sesja 077
            'api/dials.php'                => true,
            'api/groups.php'               => true,
            'api/thumbs.php'               => true,
            'api/export.php'               => true,
            'api/import.php'               => true,
            'api/admin.php'                => true,
            'api/settings.php'             => true,
            'api/update.php'               => true,
            'api/meta.php'                 => true,
            'api/group_icons.php'          => true,
            'api/avatars.php'              => true,   // sesja 078
            'assets/css/app.css'           => true,
            'assets/css/design-system.css' => true,
            'assets/js/app.js'             => true,
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
