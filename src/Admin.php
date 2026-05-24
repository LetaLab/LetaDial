<?php
/**
 * LetaDial — Admin Model (sesja 065)
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

class Admin
{
    // ── Rate Limits / Blocked ─────────────────────────────────────────────────

    public static function getBlocked(int $min = 3): array
    {
        return DB::rows(
            "SELECT
                rl.id,
                rl.key_hash,
                rl.key_plain,
                rl.action,
                rl.attempts,
                rl.window_start,
                (SELECT user_agent
                 FROM login_history
                 WHERE ip = rl.key_plain
                 ORDER BY created_at DESC LIMIT 1)   AS last_ua,
                (SELECT login_attempt
                 FROM login_history
                 WHERE ip = rl.key_plain
                 ORDER BY created_at DESC LIMIT 1)   AS last_login_attempt,
                (SELECT COUNT(*)
                 FROM login_history
                 WHERE ip = rl.key_plain
                   AND status NOT IN ('success'))     AS total_failures,
                (SELECT MAX(created_at)
                 FROM login_history
                 WHERE ip = rl.key_plain)             AS last_seen
             FROM rate_limits rl
             WHERE rl.attempts >= ?
             ORDER BY rl.attempts DESC, rl.window_start DESC",
            [$min]
        );
    }

    public static function unblock(string $keyHash, string $action): bool
    {
        return DB::run(
            "DELETE FROM rate_limits WHERE key_hash = ? AND action = ?",
            [$keyHash, $action]
        ) > 0;
    }

    public static function unblockByKey(string $keyPlain): int
    {
        return DB::run("DELETE FROM rate_limits WHERE key_plain = ?", [$keyPlain]);
    }

    // ── Users ─────────────────────────────────────────────────────────────────

    public static function getUsers(): array
    {
        return DB::rows(
            "SELECT
                u.id, u.login, u.email, u.role,
                u.totp_enabled, u.email_verified,
                u.created_at, u.last_login,
                (SELECT COUNT(*) FROM groups_list g WHERE g.user_id = u.id) AS group_count,
                (SELECT COUNT(*) FROM dials d       WHERE d.user_id = u.id) AS dial_count,
                (SELECT COUNT(*) FROM sessions s    WHERE s.user_id = u.id) AS session_count
             FROM users u
             ORDER BY u.created_at DESC"
        );
    }

    public static function deleteUser(int $userId, int $currentUserId): array
    {
        if ($userId === $currentUserId) {
            return ['ok' => false, 'error' => 'You cannot delete your own account.'];
        }
        $user = DB::row("SELECT id, login, role FROM users WHERE id = ?", [$userId]);
        if (!$user) return ['ok' => false, 'error' => 'User not found.'];

        if ($user['role'] === 'admin') {
            $adminCount = (int)(DB::val("SELECT COUNT(*) FROM users WHERE role = 'admin'") ?? 0);
            if ($adminCount <= 1) {
                return ['ok' => false, 'error' => 'Cannot delete the last admin account.'];
            }
        }

        foreach (['storage/thumbnails', 'storage/group_icons'] as $base) {
            $dir = __DIR__ . '/../' . $base . '/u' . $userId;
            if (is_dir($dir)) {
                array_map('unlink', glob($dir . '/*.webp') ?: []);
                @rmdir($dir);
            }
        }
        $avatarPath = DB::val("SELECT avatar_path FROM users WHERE id = ?", [$userId]);
        if ($avatarPath && file_exists(__DIR__ . '/../' . $avatarPath)) {
            @unlink(__DIR__ . '/../' . $avatarPath);
        }

        DB::run("DELETE FROM users WHERE id = ?", [$userId]);
        return ['ok' => true, 'login' => $user['login']];
    }

    // ── Login History ─────────────────────────────────────────────────────────

    public static function getLoginHistory(?string $ip = null, int $limit = 100): array
    {
        if ($ip !== null) {
            return DB::rows(
                "SELECT lh.*, u.login AS resolved_login
                 FROM login_history lh
                 LEFT JOIN users u ON u.id = lh.user_id
                 WHERE lh.ip = ?
                 ORDER BY lh.created_at DESC LIMIT ?",
                [$ip, $limit]
            );
        }
        return DB::rows(
            "SELECT lh.*, u.login AS resolved_login
             FROM login_history lh
             LEFT JOIN users u ON u.id = lh.user_id
             ORDER BY lh.created_at DESC LIMIT ?",
            [$limit]
        );
    }

    // ── Installation Check ────────────────────────────────────────────────────

    public static function installCheck(): array
    {
        $root   = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
        $groups = [];

        // ── 1. PHP ────────────────────────────────────────────────────────────
        $php = [];
        $phpVer = PHP_VERSION;
        $php[] = self::chk('PHP version ≥ 8.1', version_compare($phpVer, '8.1.0', '>='), true, $phpVer);

        foreach (['pdo_mysql', 'gd', 'mbstring', 'openssl', 'json'] as $ext) {
            $ok = extension_loaded($ext);
            $php[] = self::chk("Extension: {$ext}", $ok, true, $ok ? 'loaded' : 'MISSING');
        }

        $gd   = function_exists('gd_info') ? gd_info() : [];
        $webp = !empty($gd['WebP Support']);
        $php[] = self::chk('GD WebP support', $webp, true, $webp ? 'yes' : 'MISSING');

        $imagick = extension_loaded('imagick');
        $php[] = self::chk('Extension: imagick', $imagick, false,
            $imagick ? 'loaded' : 'not loaded',
            $imagick ? '' : 'Optional — enables better thumbnails and OG image capture.');

        $execOk = function_exists('exec') && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))));
        $php[] = self::chk('exec() available', $execOk, false,
            $execOk ? 'yes' : 'disabled',
            $execOk ? '' : 'Required for git-based updates (Admin → Update tab).');

        $groups['PHP'] = $php;

        // ── 2. Database ───────────────────────────────────────────────────────
        // FIX: Use information_schema instead of "SHOW TABLES LIKE ?" because
        // MariaDB 11.8.7 does not support prepared statements for SHOW commands.
        $db = [];
        try {
            $ver  = DB::val("SELECT VERSION()") ?? 'unknown';
            $db[] = self::chk('DB connection', true, true, $ver);

            foreach (['users','sessions','groups_list','dials','rate_limits','settings',
                      'login_history','totp_backup_codes','remember_tokens'] as $tbl) {
                $exists = (int)(DB::val(
                    "SELECT COUNT(*) FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                    [$tbl]
                ) ?? 0) > 0;
                $db[] = self::chk("Table: {$tbl}", $exists, true, $exists ? 'exists' : 'MISSING');
            }

            // rate_limits.key_plain column (sesja 065)
            $hasKP = (int)(DB::val(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'rate_limits'
                   AND COLUMN_NAME  = 'key_plain'"
            ) ?? 0) > 0;
            $db[] = self::chk('rate_limits.key_plain column', $hasKP, false,
                $hasKP ? 'present' : 'missing',
                $hasKP ? '' : 'Run migrate_065.sql to enable IP display in Blocked IPs panel.');

            // users.recent_disabled column (sesja 064)
            $hasRD = (int)(DB::val(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'users'
                   AND COLUMN_NAME  = 'recent_disabled'"
            ) ?? 0) > 0;
            $db[] = self::chk('users.recent_disabled column', $hasRD, false,
                $hasRD ? 'present' : 'missing',
                $hasRD ? '' : 'Run migrate_064.sql.');

        } catch (\Throwable $e) {
            $db[] = self::chk('DB connection', false, true, 'FAILED', $e->getMessage());
        }
        $groups['Database'] = $db;

        // ── 3. Configuration ──────────────────────────────────────────────────
        $cfg = [];
        $cfgPath = $root . '/config.php';

        $cfgExists = file_exists($cfgPath);
        $cfg[] = self::chk('config.php exists', $cfgExists, true, $cfgExists ? 'yes' : 'MISSING');

        if ($cfgExists) {
            $cfgPerms = substr(sprintf('%o', fileperms($cfgPath)), -4);
            $cfg[] = self::chk('config.php permissions', in_array($cfgPerms, ['0600','0400'], true), false,
                $cfgPerms, 'Should be 600. Run: chmod 600 config.php');
        }

        $constants = ['APP_NAME','APP_URL','APP_VERSION','DB_HOST','DB_NAME','DB_USER',
                      'ENCRYPTION_KEY','HMAC_KEY','SESSION_TTL'];
        foreach ($constants as $c) {
            $defined = defined($c);
            $val     = $defined
                ? (str_contains($c, 'KEY') || str_contains($c, 'PASS') ? '***hidden***' : constant($c))
                : 'NOT DEFINED';
            $cfg[] = self::chk("define('{$c}')", $defined, true, (string)$val);
        }

        $urlHttps = str_starts_with(APP_URL, 'https://');
        $cfg[] = self::chk('APP_URL uses HTTPS', $urlHttps, false, APP_URL,
            $urlHttps ? '' : 'Strongly recommended for production.');

        $encLen = strlen(ENCRYPTION_KEY ?? '');
        $cfg[] = self::chk('ENCRYPTION_KEY length', $encLen === 64, true,
            "{$encLen} chars", $encLen !== 64 ? 'Must be exactly 64 hex chars (32 bytes).' : '');

        $hmacLen = strlen(HMAC_KEY ?? '');
        $cfg[] = self::chk('HMAC_KEY length', $hmacLen === 64, true,
            "{$hmacLen} chars", $hmacLen !== 64 ? 'Must be exactly 64 hex chars (32 bytes).' : '');

        $groups['Configuration'] = $cfg;

        // ── 4. Security ───────────────────────────────────────────────────────
        $sec = [];

        // install.php should be absent (fix_permissions.sh removes it)
        $noInstall = !file_exists($root . '/install.php');
        $sec[] = self::chk('install.php absent', $noInstall, true,
            $noInstall ? 'removed ✓' : 'PRESENT — delete it!',
            $noInstall ? '' : 'Delete install.php immediately — it allows full reinstall.');

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
        $sec[] = self::chk('HTTPS active', $https, false,
            $https ? 'yes' : 'no',
            $https ? '' : 'Strongly recommended.');

        $gitExists = is_dir($root . '/.git');
        $sec[] = self::chk('.git directory present', $gitExists, false,
            $gitExists ? 'yes (verify nginx blocks /.git/)' : 'no',
            $gitExists ? 'Ensure nginx: location ~ /\\. { deny all; }' : '');

        $smtpOk = defined('SMTP_ENABLED') && SMTP_ENABLED;
        $sec[] = self::chk('SMTP configured', $smtpOk, false,
            $smtpOk ? (SMTP_HOST . ':' . SMTP_PORT) : 'disabled',
            $smtpOk ? '' : 'Optional — needed for password reset emails.');

        $groups['Security'] = $sec;

        // ── 5. Filesystem ─────────────────────────────────────────────────────
        $fs = [];
        $dirsToCheck = [
            'storage'             => true,
            'storage/thumbnails'  => true,
            'storage/sessions'    => true,
            'storage/avatars'     => true,
            'storage/group_icons' => true,
            'logs'                => true,
            'assets/css'          => false,
            'assets/js'           => false,
            'assets/icons'        => false,
        ];
        foreach ($dirsToCheck as $rel => $required) {
            $path   = $root . '/' . $rel;
            $exists = is_dir($path);
            $wr     = $exists && is_writable($path);
            $perms  = $exists ? substr(sprintf('%o', fileperms($path)), -4) : 'n/a';
            $fs[] = self::chk("Dir: {$rel}/", $exists && ($required ? $wr : true), $required,
                $exists ? "{$perms}" . ($wr ? ' writable' : ' NOT writable') : 'MISSING');
        }

        $htFiles = [
            'storage/.htaccess',
            'storage/thumbnails/.htaccess',
            'storage/sessions/.htaccess',
            'logs/.htaccess',
        ];
        foreach ($htFiles as $rel) {
            $exists = file_exists($root . '/' . $rel);
            $fs[] = self::chk($rel, $exists, true,
                $exists ? 'present' : 'MISSING',
                $exists ? '' : 'Run fix_permissions.sh to create it.');
        }
        $groups['Filesystem'] = $fs;

        // ── 6. File Integrity (git diff vs local HEAD) ────────────────────────
        $integrity = [];

        if (!$gitExists) {
            $integrity[] = self::chk('Git repository', false, false, 'not found',
                'Git not found in app directory — integrity check unavailable.');
            $groups['File Integrity'] = $integrity;
            return self::buildResult($groups);
        }

        $execAvail = function_exists('exec') && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))));
        if (!$execAvail) {
            $integrity[] = self::chk('exec() for git', false, false, 'disabled',
                'Enable exec() in php.ini to use file integrity checks.');
            $groups['File Integrity'] = $integrity;
            return self::buildResult($groups);
        }

        $dirArg = escapeshellarg($root);

        // Local commit SHA
        $shaOut = [];
        exec("git -C {$dirArg} rev-parse --short HEAD 2>&1", $shaOut);
        $localSha = trim($shaOut[0] ?? 'unknown');
        $integrity[] = self::chk('Local commit (HEAD)', true, false, $localSha);

        // git status --porcelain — compare working tree vs HEAD
        $statusOut  = [];
        $statusCode = 0;
        exec("git -C {$dirArg} status --porcelain 2>&1", $statusOut, $statusCode);

        if ($statusCode !== 0) {
            $integrity[] = self::chk('git status', false, false,
                'error', implode(' ', $statusOut));
            $groups['File Integrity'] = $integrity;
            return self::buildResult($groups);
        }

        // Parse status output
        $modified  = [];
        $untracked = [];
        $deleted   = [];
        foreach ($statusOut as $line) {
            if (strlen($line) < 4) continue;
            $xy   = substr($line, 0, 2);
            $file = trim(substr($line, 3));
            $x    = $xy[0];
            $y    = $xy[1];
            if ($x === '?' && $y === '?') { $untracked[] = $file; }
            elseif ($x === 'D' || $y === 'D') { $deleted[] = $file; }
            else { $modified[] = $file; }
        }

        // Paths to ignore — these change legitimately in production
        $ignorePrefixes = ['storage/', 'logs/', '.git/', 'assets/icons/'];
        // FIX: install.php is intentionally removed by fix_permissions.sh — ignore it
        $ignoreExact    = ['config.php', 'fix_permissions.sh', 'install.php'];

        $filterFiles = function(array $files) use ($ignorePrefixes, $ignoreExact): array {
            return array_values(array_filter($files, function($f) use ($ignorePrefixes, $ignoreExact) {
                if (in_array($f, $ignoreExact)) return false;
                foreach ($ignorePrefixes as $pfx) {
                    if (str_starts_with($f, $pfx)) return false;
                }
                return true;
            }));
        };

        $modFiltered = $filterFiles($modified);
        $delFiltered = $filterFiles($deleted);

        // Commits behind GitHub (public repo) — checked by Updater::gitCheck()
        // Here we only show local state vs committed HEAD
        $integrity[] = self::chk(
            'Modified tracked files',
            count($modFiltered) === 0,
            false,
            count($modFiltered) === 0 ? 'none' : count($modFiltered) . ' file(s) modified',
            count($modFiltered) > 0
                ? implode(', ', array_slice($modFiltered, 0, 8)) . (count($modFiltered) > 8 ? '…' : '')
                : ''
        );

        $integrity[] = self::chk(
            'Deleted tracked files',
            count($delFiltered) === 0,
            false,
            count($delFiltered) === 0 ? 'none' : count($delFiltered) . ' file(s) deleted',
            count($delFiltered) > 0 ? implode(', ', $delFiltered) : ''
        );

        // Suspicious untracked files in code dirs
        $suspicious = array_values(array_filter($untracked, function($f) {
            return str_starts_with($f, 'src/')
                || str_starts_with($f, 'api/')
                || str_starts_with($f, 'pages/');
        }));
        $integrity[] = self::chk(
            'Untracked files in src/ api/ pages/',
            count($suspicious) === 0,
            false,
            count($suspicious) === 0 ? 'none' : count($suspicious) . ' file(s)',
            count($suspicious) > 0 ? implode(', ', $suspicious) : ''
        );

        // Note about login rate limit behavior
        $groups['File Integrity'] = $integrity;
        return self::buildResult($groups);
    }

    private static function chk(string $label, bool $ok, bool $required,
                                  string $value = '', string $note = ''): array
    {
        return compact('label', 'ok', 'required', 'value', 'note');
    }

    private static function buildResult(array $groups): array
    {
        $flat = [];
        foreach ($groups as $group => $items) {
            foreach ($items as $item) {
                $flat[] = array_merge(['group' => $group], $item);
            }
        }
        return $flat;
    }

    // ── Export ────────────────────────────────────────────────────────────────

    public static function exportBlocked(string $format = 'json'): string
    {
        $rows = DB::rows(
            "SELECT action, key_plain, attempts, window_start
             FROM rate_limits
             ORDER BY attempts DESC, window_start DESC"
        );
        if ($format === 'csv') {
            $lines = ["action,key,attempts,window_start"];
            foreach ($rows as $r) {
                $lines[] = implode(',', [
                    self::_csv($r['action']),
                    self::_csv($r['key_plain'] ?? ''),
                    (int)$r['attempts'],
                    self::_csv($r['window_start'] ?? ''),
                ]);
            }
            return implode("\r\n", $lines);
        }
        return json_encode([
            'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'entries'     => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    private static function _csv(string $v): string
    {
        if (strpbrk($v, '",\r\n') !== false) {
            return '"' . str_replace('"', '""', $v) . '"';
        }
        return $v;
    }
}
