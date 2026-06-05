<?php
/**
 * LetaDial — Installer v2.0
 *
 * Single-file, zero-dependency installation wizard.
 * Drop into web root, navigate to it, follow steps.
 * Self-deletes after successful installation.
 *
 * Changes from v1.0 (DialVault):
 *  - Branding: LetaDial
 *  - dials table: matches Dial.php (thumb_path, thumb_updated_at, click_count, last_click, notes)
 *  - groups_list: icon, color, icon_path columns included
 *  - rate_limits: key_plain column included
 *  - sessions: includes totp_verified, pending_totp (previously in migrate_001)
 *  - users: includes avatar_path (previously in migrate_001)
 *  - remember_tokens table (previously in migrate_001)
 *  - config.php: HMAC_KEY added, SESSION_KEY removed, SESSION_TTL = 30 days
 *  - settings: full set including registration_enabled, remember_me_days
 *  - migrate_001.sql is now fully integrated — no separate migration needed
 */

define('DIALVAULT_APP', true);
define('INSTALLER_VERSION', '2.0.0');
define('APP_BRAND', 'LetaDial');

// ── Block if already installed ────────────────────────────────────────────────
if (file_exists(__DIR__ . '/config.php')) {
    http_response_code(403);
    die(render_already_installed());
}

// ── Session ───────────────────────────────────────────────────────────────────
session_name('letadial_install');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// ── Router ────────────────────────────────────────────────────────────────────
$step   = 'requirements';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        die('CSRF validation failed. Go back and try again.');
    }
    $step = $_POST['next_step'] ?? 'requirements';
    process_post($step);
} else {
    $step = $_GET['step'] ?? 'requirements';
}

// ── POST Processors ───────────────────────────────────────────────────────────
function process_post(string &$step): void {
    match ($step) {
        'database' => process_database($step),
        'admin'    => process_admin($step),
        'email'    => process_email($step),
        'install'  => process_install($step),
        default    => null,
    };
}

function process_database(string &$step): void {
    global $errors;
    $host = trim($_POST['db_host'] ?? 'localhost');
    $name = trim($_POST['db_name'] ?? '');
    $user = trim($_POST['db_user'] ?? '');
    $pass = $_POST['db_pass'] ?? '';

    if (!$host) $errors[] = 'Database host is required.';
    if (!$name) $errors[] = 'Database name is required.';
    if (!$user) $errors[] = 'Database user is required.';

    if (empty($errors)) {
        try {
            $pdo = db_connect($host, $name, $user, $pass);
            $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
            $_SESSION['idata']['db'] = compact('host', 'name', 'user', 'pass');
            $_SESSION['idata']['db_version'] = $ver;
            $step = 'admin';
        } catch (PDOException $e) {
            $errors[] = 'Connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            $step = 'database';
        }
    } else {
        $step = 'database';
    }
}

function process_admin(string &$step): void {
    global $errors;
    $login    = trim($_POST['admin_login'] ?? '');
    $email    = trim($_POST['admin_email'] ?? '');
    $password = $_POST['admin_password'] ?? '';
    $confirm  = $_POST['admin_confirm'] ?? '';
    $app_name = trim($_POST['app_name'] ?? APP_BRAND);
    $app_url  = rtrim(trim($_POST['app_url'] ?? ''), '/');

    if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $login))
        $errors[] = 'Login: 3–50 characters, letters/numbers/underscore only.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors[] = 'Invalid email address.';
    if (strlen($password) < 12)
        $errors[] = 'Password must be at least 12 characters.';
    if (!preg_match('/[A-Z]/', $password)) $errors[] = 'Password needs an uppercase letter.';
    if (!preg_match('/[0-9]/', $password)) $errors[] = 'Password needs a number.';
    if (!preg_match('/[^A-Za-z0-9]/', $password)) $errors[] = 'Password needs a special character.';
    if ($password !== $confirm)
        $errors[] = 'Passwords do not match.';
    if (!filter_var($app_url, FILTER_VALIDATE_URL) &&
        !preg_match('/^https?:\/\/(\d{1,3}\.){3}\d{1,3}/', $app_url))
        $errors[] = 'Invalid Application URL (must start with http:// or https://).';

    if (empty($errors)) {
        $_SESSION['idata']['admin'] = [
            'login'         => $login,
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
        ];
        $_SESSION['idata']['app'] = ['name' => $app_name ?: APP_BRAND, 'url' => $app_url];
        $step = 'email';
    } else {
        $step = 'admin';
    }
}

function process_email(string &$step): void {
    global $errors;

    if (isset($_POST['skip_email'])) {
        $_SESSION['idata']['smtp'] = null;
        process_install($step);
        return;
    }

    $host = trim($_POST['smtp_host'] ?? '');
    $port = (int)($_POST['smtp_port'] ?? 587);
    $user = trim($_POST['smtp_user'] ?? '');
    $pass = $_POST['smtp_pass'] ?? '';
    $from = trim($_POST['smtp_from'] ?? '');
    $name = trim($_POST['smtp_name'] ?? APP_BRAND);

    if (!$host) $errors[] = 'SMTP host required, or click "Skip".';
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid sender email.';
    if (!in_array($port, [25, 465, 587, 2525], true)) $errors[] = 'Port must be 25, 465, 587, or 2525.';

    if (empty($errors)) {
        $_SESSION['idata']['smtp'] = compact('host', 'port', 'user', 'pass', 'from', 'name');
        process_install($step);
    } else {
        $step = 'email';
    }
}

function process_install(string &$step): void {
    global $errors;
    $d = $_SESSION['idata'] ?? [];

    if (empty($d['db']) || empty($d['admin'])) {
        $errors[] = 'Session expired. Please restart.';
        $step = 'requirements';
        return;
    }

    try {
        // 1. DB connection
        $db  = $d['db'];
        $pdo = db_connect($db['host'], $db['name'], $db['user'], $db['pass']);

        // 2. Create all tables
        db_create_tables($pdo);

        // 3. Admin user
        $admin     = $d['admin'];
        $has_smtp  = !empty($d['smtp']);
        $act_token = bin2hex(random_bytes(32));

        $pdo->prepare(
            "INSERT INTO users (login, email, password_hash, role, email_verified, activation_token, totp_required)
             VALUES (?, ?, ?, 'admin', ?, ?, 1)"
        )->execute([
            $admin['login'],
            $admin['email'],
            $admin['password_hash'],
            $has_smtp ? 0 : 1,
            $has_smtp ? $act_token : null,
        ]);

        // 4. Generate security keys
        $enc_key  = bin2hex(random_bytes(32));  // AES-256 for TOTP secrets
        $hmac_key = bin2hex(random_bytes(32));  // HMAC for CSRF tokens

        // 5. Write config.php
        $cfg_path = __DIR__ . '/config.php';
        file_put_contents($cfg_path, build_config($d['db'], $d['app'], $d['smtp'] ?? null, $enc_key, $hmac_key));
        chmod($cfg_path, 0600);

        // 6. Create directory structure
        $dirs = [
            'storage', 'storage/thumbnails', 'storage/sessions', 'storage/avatars',
            'logs', 'assets/css', 'assets/js', 'assets/icons', 'src', 'api', 'pages',
        ];
        foreach ($dirs as $dir) {
            $path = __DIR__ . '/' . $dir;
            if (!is_dir($path)) mkdir($path, 0755, true);
        }

        // Protect sensitive directories with .htaccess
        $deny_all  = "Options -Indexes\nOrder deny,allow\nDeny from all\n";
        $no_php    = "Options -Indexes\nphp_flag engine off\n";
        foreach ([
            'storage/.htaccess'            => $deny_all,
            'storage/thumbnails/.htaccess' => $no_php,
            'storage/sessions/.htaccess'   => $deny_all,
            'logs/.htaccess'               => $deny_all,
        ] as $rel => $content) {
            $p = __DIR__ . '/' . $rel;
            if (!file_exists($p)) file_put_contents($p, $content);
        }

        // 7. Default settings (full set — no separate migrate_001.sql needed)
        $stmt = $pdo->prepare("INSERT IGNORE INTO settings (key_name, value) VALUES (?, ?)");
        foreach ([
            ['app_name',               $d['app']['name']],
            ['app_url',                $d['app']['url']],
            ['installed_at',           date('Y-m-d H:i:s')],
            ['installed_version',      '2.0.0'],
            ['require_2fa',            '1'],          // admin always required; users optional
            ['registration_enabled',   '1'],
            ['session_lifetime',       '2592000'],    // 30 days
            ['remember_me_days',       '30'],
            ['max_groups_per_user',    '50'],
            ['max_dials_per_user',     '500'],
            ['thumb_width',            '163'],
            ['thumb_height',           '100'],
            ['thumb_quality',          '72'],
        ] as [$k, $v]) {
            $stmt->execute([$k, $v]);
        }

        // 8. Send activation email
        $email_sent = false;
        if ($has_smtp) {
            $email_sent = smtp_send_activation(
                $d['smtp'],
                $admin['email'],
                $d['app']['url'],
                $d['app']['name'],
                $act_token
            );
        }

        // 9. Store result and clean session
        $_SESSION['install_result'] = [
            'app_url'       => $d['app']['url'],
            'admin_login'   => $admin['login'],
            'admin_email'   => $admin['email'],
            'email_sent'    => $email_sent,
            'auto_verified' => !$has_smtp,
        ];
        unset($_SESSION['idata']);

        // 10. Self-delete
        register_shutdown_function(fn() => @unlink(__FILE__));

        $step = 'success';

    } catch (Throwable $e) {
        $errors[] = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $step = 'email';
    }
}

// ── Database Helpers ──────────────────────────────────────────────────────────
function db_connect(string $host, string $name, string $user, string $pass): PDO {
    return new PDO(
        "mysql:host={$host};dbname={$name};charset=utf8mb4",
        $user, $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
        ]
    );
}

function db_create_tables(PDO $pdo): void {
    // All tables including columns from migrate_001.sql — integrated here.
    // Note: 'groups' is a reserved SQL word → using groups_list.
    $tables = [

        // ── Users ─────────────────────────────────────────────────────────────
        "CREATE TABLE IF NOT EXISTS users (
            id               INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
            login            VARCHAR(50)      NOT NULL,
            email                 VARCHAR(255)     NOT NULL,
            email_pending         VARCHAR(255)     DEFAULT NULL COMMENT 'New email awaiting confirmation',
            email_change_token    VARCHAR(64)      DEFAULT NULL COMMENT 'Token sent to new email address',
            email_change_expires  DATETIME         DEFAULT NULL COMMENT 'Token expiry (1 hour)',
            password_hash         VARCHAR(255)     NOT NULL,
            role             ENUM('admin','user') NOT NULL DEFAULT 'user',
            totp_secret      VARCHAR(128)     DEFAULT NULL COMMENT 'AES-256-GCM encrypted',
            totp_enabled     TINYINT(1)       NOT NULL DEFAULT 0,
            totp_required    TINYINT(1)       NOT NULL DEFAULT 0,
            email_verified   TINYINT(1)       NOT NULL DEFAULT 0,
            activation_token VARCHAR(64)      DEFAULT NULL,
            reset_token      VARCHAR(64)      DEFAULT NULL,
            reset_expires    DATETIME         DEFAULT NULL,
            avatar_path      VARCHAR(255)     DEFAULT NULL,
            recent_disabled  TINYINT(1)       NOT NULL DEFAULT 0,
            theme            VARCHAR(20)      NOT NULL DEFAULT 'light',
            created_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_login       DATETIME         DEFAULT NULL,
            UNIQUE KEY uq_login (login),
            UNIQUE KEY uq_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // ── Sessions ──────────────────────────────────────────────────────────
        // Includes totp_verified + pending_totp (was in migrate_001)
        "CREATE TABLE IF NOT EXISTS sessions (
            id               CHAR(64)     NOT NULL PRIMARY KEY COMMENT 'SHA-256 hex of raw token',
            user_id          INT UNSIGNED NOT NULL,
            ip               VARCHAR(45)  NOT NULL,
            user_agent       VARCHAR(500) DEFAULT NULL,
            created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_activity    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at       DATETIME     NOT NULL,
            totp_verified    TINYINT(1)   NOT NULL DEFAULT 0,
            pending_totp     VARCHAR(128) DEFAULT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user    (user_id),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // ── Remember-me tokens ────────────────────────────────────────────────
        // selector/verifier split — was in migrate_001
        "CREATE TABLE IF NOT EXISTS remember_tokens (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id     INT UNSIGNED NOT NULL,
            selector    CHAR(24)     NOT NULL,
            verifier    CHAR(64)     NOT NULL COMMENT 'SHA-256 hex of raw verifier bytes',
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at  DATETIME     NOT NULL,
            UNIQUE KEY uq_selector (selector),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user    (user_id),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // ── Groups ────────────────────────────────────────────────────────────
        "CREATE TABLE IF NOT EXISTS groups_list (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id    INT UNSIGNED NOT NULL,
            name       VARCHAR(100) NOT NULL,
            position   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            icon       VARCHAR(10)  DEFAULT NULL,
            color      VARCHAR(7)   DEFAULT NULL,
            icon_path  VARCHAR(255) DEFAULT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // ── Dials ─────────────────────────────────────────────────────────────
        // Schema matches src/Dial.php exactly (thumb_path, thumb_updated_at, click_count, last_click, pinned)
        "CREATE TABLE IF NOT EXISTS dials (
            id               INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
            user_id          INT UNSIGNED  NOT NULL,
            group_id         INT UNSIGNED  NOT NULL,
            title            VARCHAR(100)  NOT NULL,
            url              VARCHAR(2048) NOT NULL,
            notes            TEXT          DEFAULT NULL,
            position         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            pinned           TINYINT(1)    NOT NULL DEFAULT 0,
            thumb_path       VARCHAR(255)  DEFAULT NULL,
            thumb_updated_at DATETIME      DEFAULT NULL,
            click_count      INT UNSIGNED  NOT NULL DEFAULT 0,
            last_click       DATETIME      DEFAULT NULL,
            created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (group_id) REFERENCES groups_list(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id)  REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_group      (group_id),
            INDEX idx_user       (user_id),
            INDEX idx_user_pinned (user_id, pinned)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // ── TOTP Backup Codes ─────────────────────────────────────────────────
        "CREATE TABLE IF NOT EXISTS totp_backup_codes (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id    INT UNSIGNED NOT NULL,
            code_hash  VARCHAR(255) NOT NULL COMMENT 'bcrypt hash',
            used       TINYINT(1)   NOT NULL DEFAULT 0,
            used_at    DATETIME     DEFAULT NULL,
            created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // ── Rate Limits ───────────────────────────────────────────────────────
        "CREATE TABLE IF NOT EXISTS rate_limits (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            key_hash     CHAR(64)     NOT NULL COMMENT 'SHA-256(key+action)',
            action       VARCHAR(50)  NOT NULL,
            attempts     TINYINT UNSIGNED NOT NULL DEFAULT 1,
            window_start DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            key_plain    VARCHAR(255) DEFAULT NULL,
            UNIQUE KEY uq_key_action (key_hash, action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // ── Settings ──────────────────────────────────────────────────────────
        "CREATE TABLE IF NOT EXISTS settings (
            key_name   VARCHAR(100) NOT NULL PRIMARY KEY,
            value      TEXT         DEFAULT NULL,
            updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // ── Login History ─────────────────────────────────────────────────────
        "CREATE TABLE IF NOT EXISTS login_history (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id       INT UNSIGNED DEFAULT NULL,
            login_attempt VARCHAR(50)  DEFAULT NULL,
            ip            VARCHAR(45)  NOT NULL,
            user_agent    VARCHAR(500) DEFAULT NULL,
            status        ENUM('success','fail_password','fail_2fa','fail_locked','fail_token') NOT NULL,
            created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user    (user_id),
            INDEX idx_ip      (ip),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }
}

// ── Config File Builder ───────────────────────────────────────────────────────
function build_config(array $db, array $app, ?array $smtp, string $enc, string $hmac): string {
    $esc = fn(string $s): string => str_replace(["\\", "'"], ["\\\\", "\\'"], $s);
    $now = date('Y-m-d H:i:s');

    $smtp_host    = $esc($smtp['host'] ?? '');
    $smtp_port    = (int)($smtp['port'] ?? 587);
    $smtp_user    = $esc($smtp['user'] ?? '');
    $smtp_pass    = $esc($smtp['pass'] ?? '');
    $smtp_from    = $esc($smtp['from'] ?? '');
    $smtp_name    = $esc($smtp['name'] ?? APP_BRAND);
    $smtp_enabled = $smtp ? 'true' : 'false';
    $app_name     = $esc($app['name']);
    $app_url      = $esc($app['url']);
    $db_host      = $esc($db['host']);
    $db_name      = $esc($db['name']);
    $db_user      = $esc($db['user']);
    $db_pass      = $esc($db['pass']);

    return <<<PHP
<?php
/**
 * LetaDial — Configuration
 * Generated: {$now}
 *
 * !! DO NOT expose this file via web !!
 * !! Permissions should be: 600      !!
 * !! Changing ENCRYPTION_KEY or HMAC_KEY invalidates all 2FA secrets and CSRF tokens !!
 */

defined('DIALVAULT_APP') or die('Direct access forbidden.');

// ── Database ──────────────────────────────────────────────────────────────────
define('DB_HOST',    '{$db_host}');
define('DB_NAME',    '{$db_name}');
define('DB_USER',    '{$db_user}');
define('DB_PASS',    '{$db_pass}');
define('DB_CHARSET', 'utf8mb4');

// ── Application ───────────────────────────────────────────────────────────────
define('APP_NAME',    '{$app_name}');
define('APP_URL',     '{$app_url}');
define('APP_VERSION', '2.0.0');

// ── Security Keys ─────────────────────────────────────────────────────────────
define('ENCRYPTION_KEY', '{$enc}');   // AES-256-GCM key for TOTP secrets
define('HMAC_KEY',       '{$hmac}');  // HMAC-SHA256 key for CSRF tokens (v3)

// ── Sessions ──────────────────────────────────────────────────────────────────
define('SESSION_TTL', 2592000);  // 30 days

// ── Thumbnails ────────────────────────────────────────────────────────────────
define('THUMB_WIDTH',   163);
define('THUMB_HEIGHT',  100);
define('THUMB_QUALITY', 72);

// ── SMTP ──────────────────────────────────────────────────────────────────────
define('SMTP_ENABLED', {$smtp_enabled});
define('SMTP_HOST',    '{$smtp_host}');
define('SMTP_PORT',    {$smtp_port});
define('SMTP_USER',    '{$smtp_user}');
define('SMTP_PASS',    '{$smtp_pass}');
define('SMTP_FROM',    '{$smtp_from}');
define('SMTP_NAME',    '{$smtp_name}');
PHP;
}

// ── Minimal SMTP ──────────────────────────────────────────────────────────────
function smtp_read(mixed $sock): string {
    $out = '';
    do {
        $line = fgets($sock, 512);
        if ($line === false) break;
        $out .= $line;
    } while (isset($line[3]) && $line[3] === '-');
    return $out;
}

function smtp_cmd(mixed $sock, string $cmd): string {
    fwrite($sock, $cmd . "\r\n");
    return smtp_read($sock);
}

function smtp_code(string $r): int { return (int)substr(trim($r), 0, 3); }

function smtp_send_activation(array $smtp, string $to, string $app_url, string $app_name, string $token): bool {
    try {
        $link    = $app_url . '/activate?token=' . rawurlencode($token);
        $subject = "Activate your {$app_name} account";
        $body    = "Hello,\r\n\r\n"
                 . "Your {$app_name} installation is complete.\r\n\r\n"
                 . "Activate your admin account:\r\n"
                 . $link . "\r\n\r\n"
                 . "This link expires in 24 hours.\r\n\r\n"
                 . "— {$app_name}";

        $port   = (int)$smtp['port'];
        $host   = $smtp['host'];
        $prefix = ($port === 465) ? 'ssl://' : '';

        $sock = @fsockopen($prefix . $host, $port, $errno, $errstr, 10);
        if (!$sock) return false;
        stream_set_timeout($sock, 10);

        if (smtp_code(smtp_read($sock)) !== 220) { fclose($sock); return false; }

        $domain = explode('@', $smtp['from'])[1] ?? 'localhost';

        fwrite($sock, "EHLO {$domain}\r\n");
        // Drain multi-line EHLO
        do { $peek = fgets($sock, 512); } while ($peek !== false && isset($peek[3]) && $peek[3] === '-');

        if ($port === 587) {
            if (smtp_code(smtp_cmd($sock, 'STARTTLS')) !== 220) { fclose($sock); return false; }
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($sock); return false;
            }
            fwrite($sock, "EHLO {$domain}\r\n");
            do { $peek = fgets($sock, 512); } while ($peek !== false && isset($peek[3]) && $peek[3] === '-');
        }

        if (!empty($smtp['user'])) {
            smtp_cmd($sock, 'AUTH LOGIN');
            smtp_cmd($sock, base64_encode($smtp['user']));
            if (smtp_code(smtp_cmd($sock, base64_encode($smtp['pass']))) !== 235) {
                fclose($sock); return false;
            }
        }

        $from   = $smtp['from'];
        $name   = $smtp['name'] ?? $app_name;
        $date   = date('r');
        $msg_id = '<' . bin2hex(random_bytes(8)) . '@' . $domain . '>';

        smtp_cmd($sock, "MAIL FROM:<{$from}>");
        smtp_cmd($sock, "RCPT TO:<{$to}>");
        smtp_cmd($sock, "DATA");

        $msg  = "Date: {$date}\r\n";
        $msg .= "From: =?UTF-8?B?" . base64_encode($name) . "?= <{$from}>\r\n";
        $msg .= "To: <{$to}>\r\n";
        $msg .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $msg .= "Message-ID: {$msg_id}\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n";
        $msg .= "\r\n";
        $msg .= chunk_split(base64_encode($body), 76, "\r\n");
        $msg .= "\r\n.";

        fwrite($sock, $msg . "\r\n");
        $r = smtp_read($sock);
        smtp_cmd($sock, 'QUIT');
        fclose($sock);

        return smtp_code($r) === 250;
    } catch (Throwable) {
        return false;
    }
}

// ── Requirements Check ────────────────────────────────────────────────────────
function check_requirements(): array {
    $checks = [];

    $php_ver = PHP_VERSION;
    $php_ok  = version_compare($php_ver, '8.1.0', '>=');
    $checks[] = ['label' => "PHP &ge; 8.1 (found {$php_ver})", 'ok' => $php_ok, 'required' => true];

    foreach (['pdo_mysql', 'gd', 'mbstring', 'openssl', 'json'] as $ext) {
        $ok = extension_loaded($ext);
        $checks[] = ['label' => "PHP extension: <code>{$ext}</code>", 'ok' => $ok, 'required' => true];
    }

    $gd_info = function_exists('gd_info') ? gd_info() : [];
    $webp_ok = !empty($gd_info['WebP Support']);
    $checks[] = ['label' => 'GD WebP support', 'ok' => $webp_ok, 'required' => true];

    $wr_ok = is_writable(__DIR__);
    $checks[] = ['label' => 'Web root writable (for config.php)', 'ok' => $wr_ok, 'required' => true];

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || ($_SERVER['SERVER_PORT'] ?? 80) == 443
          || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $checks[] = ['label' => 'HTTPS connection', 'ok' => $https, 'required' => false,
                 'note' => $https ? '' : 'Strongly recommended for production.'];

    return $checks;
}

function all_required_pass(array $checks): bool {
    foreach ($checks as $c) {
        if ($c['required'] && !$c['ok']) return false;
    }
    return true;
}

// ── HTML Helpers ──────────────────────────────────────────────────────────────
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function input_val(string $key, string $default = ''): string { return h(trim($_POST[$key] ?? $default)); }

function steps_bar(string $current): string {
    $steps = ['requirements' => 'Requirements', 'database' => 'Database',
              'admin' => 'Admin', 'email' => 'Email', 'success' => 'Done'];
    $order  = array_keys($steps);
    $cur_i  = array_search($current, $order, true);
    $html   = '<ol class="steps">';
    foreach ($order as $i => $key) {
        $cls = 'step';
        if ($i < $cur_i)   $cls .= ' done';
        if ($i === $cur_i) $cls .= ' active';
        $html .= "<li class=\"{$cls}\"><span>{$steps[$key]}</span></li>";
    }
    return $html . '</ol>';
}

function render_errors(array $errs): string {
    if (!$errs) return '';
    $items = implode('', array_map(fn($e) => "<li>{$e}</li>", $errs));
    return "<div class=\"alert alert-error\"><ul>{$items}</ul></div>";
}

// ── Page: Already Installed ───────────────────────────────────────────────────
function render_already_installed(): string {
    $brand = APP_BRAND;
    return render_layout('Already Installed', 'requirements',
        "<div class=\"card\"><div class=\"card-body\" style=\"text-align:center\">
        <div class=\"big-icon\">⛔</div>
        <h2>Already Installed</h2>
        <p><code>config.php</code> already exists.</p>
        <p><strong>Delete it to reinstall</strong> — this will not remove your database.</p>
        <a href=\"/\" class=\"btn btn-primary\">Go to {$brand} →</a>
        </div></div>");
}

// ── Page Renderers ────────────────────────────────────────────────────────────
function render_requirements(): string {
    $checks = check_requirements();
    $all_ok = all_required_pass($checks);

    $rows = '';
    foreach ($checks as $c) {
        $icon  = $c['ok'] ? '✓' : ($c['required'] ? '✗' : '⚠');
        $cls   = $c['ok'] ? 'pass' : ($c['required'] ? 'fail' : 'warn');
        $note  = !empty($c['note']) ? " <span class=\"note\">{$c['note']}</span>" : '';
        $rows .= "<tr class=\"{$cls}\"><td class=\"icon\">{$icon}</td>
                  <td>{$c['label']}{$note}</td></tr>";
    }

    $btn = $all_ok
        ? '<form method="post"><input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">
           <input type="hidden" name="next_step" value="database">
           <button type="submit" class="btn btn-primary">Continue →</button></form>'
        : '<p class="alert alert-error">Fix the issues above before continuing.</p>';

    return render_layout('Requirements', 'requirements',
        "<div class=\"card\">
            <div class=\"card-header\"><h2>System Requirements</h2></div>
            <div class=\"card-body\">
                <table class=\"checks\">{$rows}</table>
                <div class=\"actions\">{$btn}</div>
            </div>
        </div>");
}

function render_database(): string {
    global $errors;
    return render_layout('Database', 'database',
        render_errors($errors) .
        "<div class=\"card\">
            <div class=\"card-header\"><h2>Database Configuration</h2></div>
            <div class=\"card-body\">
                <form method=\"post\">
                <input type=\"hidden\" name=\"csrf\" value=\"" . h($_SESSION['csrf']) . "\">
                <input type=\"hidden\" name=\"next_step\" value=\"database\">
                <p class=\"hint\">The database must already exist. User needs CREATE, INSERT, SELECT, UPDATE, DELETE, DROP privileges.</p>
                <div class=\"form-group\">
                    <label>Database Host</label>
                    <input type=\"text\" name=\"db_host\" value=\"" . input_val('db_host', 'localhost') . "\" required autocomplete=\"off\">
                </div>
                <div class=\"form-group\">
                    <label>Database Name</label>
                    <input type=\"text\" name=\"db_name\" value=\"" . input_val('db_name') . "\" required autocomplete=\"off\">
                </div>
                <div class=\"form-grid\">
                    <div class=\"form-group\">
                        <label>Database User</label>
                        <input type=\"text\" name=\"db_user\" value=\"" . input_val('db_user') . "\" required autocomplete=\"off\">
                    </div>
                    <div class=\"form-group\">
                        <label>Database Password</label>
                        <input type=\"password\" name=\"db_pass\" autocomplete=\"off\">
                    </div>
                </div>
                <div class=\"actions\">
                    <button type=\"submit\" class=\"btn btn-primary\">Test &amp; Continue →</button>
                </div>
                </form>
            </div>
        </div>");
}

function render_admin(): string {
    global $errors;
    $app_url = h((!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $brand   = APP_BRAND;
    return render_layout('Admin Account', 'admin',
        render_errors($errors) .
        "<div class=\"card\">
            <div class=\"card-header\"><h2>Application &amp; Admin Account</h2></div>
            <div class=\"card-body\">
                <form method=\"post\">
                <input type=\"hidden\" name=\"csrf\" value=\"" . h($_SESSION['csrf']) . "\">
                <input type=\"hidden\" name=\"next_step\" value=\"admin\">
                <h3>Application</h3>
                <div class=\"form-grid\">
                    <div class=\"form-group\">
                        <label>Application Name</label>
                        <input type=\"text\" name=\"app_name\" value=\"" . input_val('app_name', $brand) . "\" required>
                    </div>
                    <div class=\"form-group\">
                        <label>Application URL <span class=\"hint-inline\">(no trailing slash)</span></label>
                        <input type=\"text\" name=\"app_url\" value=\"" . input_val('app_url', $app_url) . "\" required placeholder=\"https://yourdomain.com\">
                    </div>
                </div>
                <h3>Admin Account</h3>
                <p class=\"hint\">Admin login requires 2FA setup on first login.</p>
                <div class=\"form-grid\">
                    <div class=\"form-group\">
                        <label>Login <span class=\"hint-inline\">(letters, numbers, underscore)</span></label>
                        <input type=\"text\" name=\"admin_login\" value=\"" . input_val('admin_login') . "\" required autocomplete=\"username\">
                    </div>
                    <div class=\"form-group\">
                        <label>Email</label>
                        <input type=\"email\" name=\"admin_email\" value=\"" . input_val('admin_email') . "\" required autocomplete=\"email\">
                    </div>
                </div>
                <div class=\"form-grid\">
                    <div class=\"form-group\">
                        <label>Password <span class=\"hint-inline\">(min. 12 chars, mixed)</span></label>
                        <input type=\"password\" name=\"admin_password\" required autocomplete=\"new-password\" minlength=\"12\">
                    </div>
                    <div class=\"form-group\">
                        <label>Confirm Password</label>
                        <input type=\"password\" name=\"admin_confirm\" required autocomplete=\"new-password\">
                    </div>
                </div>
                <div class=\"actions\">
                    <button type=\"submit\" class=\"btn btn-primary\">Continue →</button>
                </div>
                </form>
            </div>
        </div>");
}

function render_email(): string {
    global $errors;
    $brand = APP_BRAND;
    return render_layout('Email Setup', 'email',
        render_errors($errors) .
        "<div class=\"card\">
            <div class=\"card-header\"><h2>Email / SMTP <span class=\"badge\">Optional</span></h2></div>
            <div class=\"card-body\">
                <p class=\"hint\">Used for account activation and password resets.
                   If skipped, your admin account is activated automatically.</p>
                <form method=\"post\">
                <input type=\"hidden\" name=\"csrf\" value=\"" . h($_SESSION['csrf']) . "\">
                <input type=\"hidden\" name=\"next_step\" value=\"email\">
                <div class=\"form-grid\">
                    <div class=\"form-group\" style=\"flex:2\">
                        <label>SMTP Host</label>
                        <input type=\"text\" name=\"smtp_host\" value=\"" . input_val('smtp_host') . "\" placeholder=\"smtp.example.com\">
                    </div>
                    <div class=\"form-group\">
                        <label>Port</label>
                        <input type=\"number\" name=\"smtp_port\" value=\"" . input_val('smtp_port', '587') . "\">
                    </div>
                </div>
                <div class=\"form-grid\">
                    <div class=\"form-group\">
                        <label>SMTP Username</label>
                        <input type=\"text\" name=\"smtp_user\" value=\"" . input_val('smtp_user') . "\" autocomplete=\"off\">
                    </div>
                    <div class=\"form-group\">
                        <label>SMTP Password</label>
                        <input type=\"password\" name=\"smtp_pass\" autocomplete=\"off\">
                    </div>
                </div>
                <div class=\"form-grid\">
                    <div class=\"form-group\">
                        <label>Sender Email</label>
                        <input type=\"email\" name=\"smtp_from\" value=\"" . input_val('smtp_from') . "\">
                    </div>
                    <div class=\"form-group\">
                        <label>Sender Name</label>
                        <input type=\"text\" name=\"smtp_name\" value=\"" . input_val('smtp_name', $brand) . "\">
                    </div>
                </div>
                <div class=\"actions\">
                    <button type=\"submit\" class=\"btn btn-primary\">Install →</button>
                    <button type=\"submit\" name=\"skip_email\" value=\"1\" class=\"btn btn-ghost\">Skip &amp; Install →</button>
                </div>
                </form>
            </div>
        </div>");
}

function render_success(): string {
    $r          = $_SESSION['install_result'] ?? [];
    $app_url    = h($r['app_url'] ?? '/');
    $login      = h($r['admin_login'] ?? 'admin');
    $email      = h($r['admin_email'] ?? '');
    $auto_ver   = $r['auto_verified'] ?? true;
    $email_sent = $r['email_sent'] ?? false;
    $brand      = APP_BRAND;

    $email_status = $auto_ver
        ? '<div class="alert alert-success">✓ Admin account activated automatically (no SMTP configured).</div>'
        : ($email_sent
            ? "<div class=\"alert alert-success\">✓ Activation email sent to <strong>{$email}</strong>.</div>"
            : "<div class=\"alert alert-warn\">⚠ Could not send activation email. Activate manually in DB:<br>
               <code>UPDATE users SET email_verified=1, activation_token=NULL WHERE login='{$login}';</code></div>");

    return render_layout('Installed!', 'success',
        "<div class=\"card\">
            <div class=\"card-body\" style=\"text-align:center\">
                <div class=\"big-icon\">🎉</div>
                <h2>{$brand} Installed Successfully</h2>
                <p>The installer has deleted itself.</p>
                {$email_status}
                <div class=\"summary\">
                    <div><span>Admin login</span><strong>{$login}</strong></div>
                    <div><span>App URL</span><strong>{$app_url}</strong></div>
                </div>
                <p class=\"hint\" style=\"margin-top:1.5rem\">
                    ⚠ Set up <strong>2FA (Google Authenticator)</strong> on first login — required for admin.
                </p>
                <a href=\"{$app_url}/login\" class=\"btn btn-primary\" style=\"margin-top:1rem\">Go to Login →</a>
            </div>
        </div>");
}

// ── Master Layout ─────────────────────────────────────────────────────────────
function render_layout(string $title, string $current_step, string $content): string {
    $brand = APP_BRAND;
    $steps = steps_bar($current_step);
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{$brand} Installer — {$title}</title>
<style>
:root{--bg:#f0f2f5;--surface:#fff;--border:#e2e8f0;--text:#1a202c;--text-muted:#64748b;
  --primary:#690B22;--primary-h:#520818;--success:#16a34a;--error:#dc2626;--warn:#d97706;
  --radius:10px;--shadow:0 1px 3px rgba(0,0,0,.08),0 4px 16px rgba(0,0,0,.06)}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,-apple-system,'Segoe UI',sans-serif;background:var(--bg);
  color:var(--text);min-height:100vh;padding:2rem 1rem;font-size:15px;line-height:1.6}
.wrap{max-width:680px;margin:0 auto}
.logo{text-align:center;margin-bottom:2rem}
.logo h1{font-size:1.6rem;font-weight:700;color:var(--primary)}
.logo p{color:var(--text-muted);font-size:.9rem}
.steps{display:flex;list-style:none;margin-bottom:2rem;position:relative;counter-reset:step}
.steps::before{content:'';position:absolute;top:14px;left:0;right:0;height:2px;background:var(--border);z-index:0}
.step{flex:1;text-align:center;position:relative;z-index:1}
.step span{display:inline-block;font-size:.72rem;color:var(--text-muted);margin-top:6px}
.step::before{content:counter(step);counter-increment:step;display:block;width:28px;height:28px;
  border-radius:50%;background:var(--bg);border:2px solid var(--border);line-height:24px;
  font-size:.75rem;font-weight:600;color:var(--text-muted);margin:0 auto;transition:all .2s}
.step.done::before{background:var(--success);border-color:var(--success);color:#fff;content:'✓'}
.step.active::before{background:var(--primary);border-color:var(--primary);color:#fff}
.step.active span{color:var(--primary);font-weight:600}
.card{background:var(--surface);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden}
.card-header{padding:1.25rem 1.5rem;border-bottom:1px solid var(--border)}
.card-header h2{font-size:1.15rem;font-weight:600}
.card-body{padding:1.5rem}
.card-body h3{font-size:.9rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;
  color:var(--text-muted);margin:1.5rem 0 .75rem}
.card-body h3:first-child{margin-top:0}
.form-group{margin-bottom:1rem}
.form-group label{display:block;font-size:.85rem;font-weight:500;margin-bottom:.35rem}
.form-group input{width:100%;padding:.55rem .75rem;border:1.5px solid var(--border);border-radius:6px;
  font-size:.95rem;font-family:inherit;transition:border-color .15s;background:#fff;color:var(--text)}
.form-group input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(105,11,34,.12)}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 1rem}
.hint{font-size:.82rem;color:var(--text-muted);margin:.5rem 0}
.hint-inline{font-size:.78rem;font-weight:400;color:var(--text-muted)}
.actions{display:flex;gap:.75rem;margin-top:1.5rem;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.6rem 1.3rem;border-radius:6px;
  font-size:.9rem;font-weight:600;cursor:pointer;border:none;transition:all .15s;
  text-decoration:none;font-family:inherit}
.btn-primary{background:var(--primary);color:#fff}
.btn-primary:hover{background:var(--primary-h)}
.btn-ghost{background:transparent;color:var(--text-muted);border:1.5px solid var(--border)}
.btn-ghost:hover{background:var(--bg)}
.alert{padding:.85rem 1rem;border-radius:6px;margin-bottom:1rem;font-size:.88rem}
.alert ul{margin:.25rem 0 0 1rem}
.alert-error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}
.alert-warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e}
.checks{width:100%;border-collapse:collapse}
.checks td{padding:.5rem .25rem;border-bottom:1px solid var(--border);font-size:.9rem}
.checks tr:last-child td{border-bottom:none}
.checks .icon{width:28px;font-size:1rem;text-align:center}
.checks .pass .icon{color:var(--success)} .checks .fail .icon{color:var(--error)} .checks .warn .icon{color:var(--warn)}
.note{font-size:.78rem;color:var(--warn);display:block}
code{background:var(--bg);padding:.1em .4em;border-radius:4px;font-size:.85em;font-family:monospace}
.big-icon{font-size:3.5rem;margin:1rem 0}
.summary{background:var(--bg);border-radius:8px;padding:1rem 1.25rem;margin-top:1.25rem;text-align:left}
.summary div{display:flex;justify-content:space-between;align-items:center;
  padding:.4rem 0;border-bottom:1px solid var(--border);font-size:.9rem}
.summary div:last-child{border-bottom:none}
.summary span{color:var(--text-muted)}
.badge{font-size:.7rem;background:var(--bg);border:1px solid var(--border);color:var(--text-muted);
  padding:.1em .5em;border-radius:4px;vertical-align:middle;margin-left:.4rem}
@media(max-width:520px){.form-grid{grid-template-columns:1fr}.steps span{display:none}}
</style>
</head>
<body>
<div class="wrap">
    <div class="logo">
        <h1>{$brand}</h1>
        <p>Installer v2.0</p>
    </div>
    {$steps}
    {$content}
</div>
</body>
</html>
HTML;
}

// ── Dispatch Renderer ─────────────────────────────────────────────────────────
echo match ($step) {
    'requirements' => render_requirements(),
    'database'     => render_database(),
    'admin'        => render_admin(),
    'email'        => render_email(),
    'success'      => render_success(),
    default        => render_requirements(),
};
