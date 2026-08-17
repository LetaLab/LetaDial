<?php
/**
 * LetaDial — Main Router
 * Loads all core classes once here — pages and API files must NOT require_once src/ files themselves.
 *
 * NAMING: every src/ class file ends in _src.php, every pages/ template
 * ends in _page.php, every api/ endpoint ends in _api.php. This means no
 * two files anywhere in the project can ever collide on a case-insensitive
 * filesystem (Windows/macOS) even though Linux would treat e.g. Admin.php
 * and admin.php as two different files. Class names inside these files
 * are UNCHANGED (still Auth, Admin, CSRF, Dial, ...) — only the filenames
 * moved. See NAMING_CONVENTION.md for the full old-name -> new-name map.
 */
declare(strict_types=1);
define('DIALVAULT_APP', true);

if (!file_exists(__DIR__ . '/config.php')) {
    if (file_exists(__DIR__ . '/install.php')) { header('Location: /install.php'); exit; }
    http_response_code(503);
    die('LetaDial is not installed. Upload install.php to begin.');
}

require_once __DIR__ . '/config.php';

// ── Load all src/ classes (ORDER MATTERS) ─────────────────────────────────────
require_once __DIR__ . '/src/db_src.php';           // PDO singleton — always first
require_once __DIR__ . '/src/password_src.php';     // Password hashing & validation
require_once __DIR__ . '/src/csrf_src.php';         // CSRF protection (v5)
require_once __DIR__ . '/src/csp_src.php';          // Content Security Policy — nonce + Report-Only (Krok 2, plan CSP)
require_once __DIR__ . '/src/rate_limit_src.php';   // Brute-force protection
require_once __DIR__ . '/src/totp_src.php';         // RFC 6238 TOTP 2FA
require_once __DIR__ . '/src/qr_code_src.php';      // Pure PHP QR SVG (no external requests)
require_once __DIR__ . '/src/mailer_src.php';       // Raw SMTP socket mailer
require_once __DIR__ . '/src/auth_src.php';         // Session management & login
require_once __DIR__ . '/src/group_src.php';        // Dial group CRUD
require_once __DIR__ . '/src/thumbnail_src.php';    // Thumbnail generation (GD/WebP) — before Dial
require_once __DIR__ . '/src/group_icon_src.php';   // Group icon upload (GD/WebP) — sesja 052
require_once __DIR__ . '/src/avatar_src.php';       // User avatar upload (GD/WebP) — sesja 078
require_once __DIR__ . '/src/meta_src.php';         // OG/title meta fetcher — sesja 057
require_once __DIR__ . '/src/updater_src.php';      // GitHub release checker + git update — sesja 059/065
require_once __DIR__ . '/src/admin_src.php';        // Admin panel model — sesja 065
require_once __DIR__ . '/src/dial_src.php';         // Speed dial CRUD — after Thumbnail (uses it)
require_once __DIR__ . '/src/import_src.php';       // JSON import
require_once __DIR__ . '/src/export_src.php';       // JSON export

// ── CSP: Enforcing + Report-Only (Krok 2 i Krok 6, plan CSP) ──────────────────
// Oba wysyłane na każdy request, przed jakimkolwiek outputem, z TYM SAMYM
// nonce (patrz src/csp_src.php::policy()). Enforcing NAPRAWDĘ blokuje inline
// script/style bez nonce; Report-Only zostaje jako canary (patrz plan,
// "Otwarte pytania" #2 — decyzja o jego zdjęciu to osobny, nierozstrzygnięty
// Krok 7). Stara linia `add_header Content-Security-Policy ...` w configu
// nginx (oba serwery) musi być usunięta/zakomentowana — patrz komentarz na
// górze src/csp_src.php i PLAN_NAPRAWY_CSP.md, Krok 6.
CSP::sendEnforcingHeader();
CSP::sendReportOnlyHeader();

// ── URI ───────────────────────────────────────────────────────────────────────
$uri = rtrim(strtolower(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/'), '/') ?: '/';

// ── API ───────────────────────────────────────────────────────────────────────
// NAMING: explicit URL-segment -> file map (not string concatenation from
// the request), so every api/ file can be named {name}_api.php regardless
// of whether the URL segment itself uses a hyphen (csp-report) or an
// underscore (group_icons) — the URL a client calls never changes, only
// the filename that serves it. This also means the file to load is always
// chosen from a fixed whitelist, never built dynamically from user input.
if (str_starts_with($uri, '/api/')) {
    $segment = explode('/', ltrim(substr($uri, 5), '/'))[0];

    $api_routes = [
        'dials'       => 'dial_api.php',
        'groups'      => 'group_api.php',
        'thumbs'      => 'thumbnail_api.php',
        'meta'        => 'meta_api.php',
        'settings'    => 'settings_api.php',
        'avatars'     => 'avatar_api.php',
        'group_icons' => 'group_icon_api.php',
        'export'      => 'export_api.php',
        'import'      => 'import_api.php',
        'update'      => 'updater_api.php',
        'admin'       => 'admin_api.php',
        'csp-report'  => 'csp_report_api.php',
    ];

    $file = isset($api_routes[$segment]) ? __DIR__ . '/api/' . $api_routes[$segment] : null;
    if ($file !== null && is_file($file)) { require $file; exit; }

    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not found']); exit;
}

// ── Pages ─────────────────────────────────────────────────────────────────────
$routes = [
    '/'                => 'pages/dashboard_page.php',
    '/login'           => 'pages/login_page.php',
    '/logout'          => 'pages/logout_page.php',
    '/activate'        => 'pages/activate_page.php',
    '/setup-2fa'       => 'pages/setup_2fa_page.php',
    '/settings'        => 'pages/settings_page.php',
    '/admin'           => 'pages/admin_page.php',
    '/forgot-password' => 'pages/forgot_password_page.php',
    '/reset-password'  => 'pages/reset_password_page.php',
    '/confirm-email'   => 'pages/confirm_email_page.php',   // sesja 066
    '/setup-account'   => 'pages/setup_account_page.php',   // sesja 067 — invite flow
    '/bookmarklet'     => 'pages/bookmarklet_page.php',      // sesja 077 — LetaLink
];

$page = $routes[$uri] ?? null;
if ($page && is_file(__DIR__ . '/' . $page)) { require __DIR__ . '/' . $page; exit; }

http_response_code(404);
$f404 = __DIR__ . '/pages/not_found_page.php';
if (is_file($f404)) { require $f404; exit; }
echo '<!DOCTYPE html><html><body style="font-family:system-ui;text-align:center;padding:4rem">
<h1 style="color:#690B22">404</h1><p>Page not found.</p><a href="/" style="color:#690B22">← Home</a></body></html>';
