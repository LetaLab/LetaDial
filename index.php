<?php
/**
 * LetaDial — Main Router
 * Loads all core classes once here — pages and API files must NOT require_once src/ files themselves.
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
require_once __DIR__ . '/src/DB.php';         // PDO singleton — always first
require_once __DIR__ . '/src/Password.php';   // Password hashing & validation
require_once __DIR__ . '/src/CSRF.php';       // CSRF protection (v5)
require_once __DIR__ . '/src/RateLimit.php';  // Brute-force protection
require_once __DIR__ . '/src/TOTP.php';       // RFC 6238 TOTP 2FA
require_once __DIR__ . '/src/QRCode.php';     // Pure PHP QR SVG (no external requests)
require_once __DIR__ . '/src/Mailer.php';     // Raw SMTP socket mailer
require_once __DIR__ . '/src/Auth.php';       // Session management & login
require_once __DIR__ . '/src/Group.php';      // Dial group CRUD
require_once __DIR__ . '/src/Thumbnail.php';  // Thumbnail generation (GD/WebP) — before Dial
require_once __DIR__ . '/src/GroupIcon.php';  // Group icon upload (GD/WebP) — sesja 052
require_once __DIR__ . '/src/Avatar.php';     // User avatar upload (GD/WebP) — sesja 078
require_once __DIR__ . '/src/Meta.php';       // OG/title meta fetcher — sesja 057
require_once __DIR__ . '/src/Updater.php';    // GitHub release checker + git update — sesja 059/065
require_once __DIR__ . '/src/Admin.php';      // Admin panel model — sesja 065
require_once __DIR__ . '/src/Dial.php';       // Speed dial CRUD — after Thumbnail (uses it)
require_once __DIR__ . '/src/Import.php';     // JSON import
require_once __DIR__ . '/src/Export.php';     // JSON export

// ── URI ───────────────────────────────────────────────────────────────────────
$uri = rtrim(strtolower(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/'), '/') ?: '/';

// ── API ───────────────────────────────────────────────────────────────────────
if (str_starts_with($uri, '/api/')) {
    $segment = explode('/', ltrim(substr($uri, 5), '/'))[0];
    $segment = preg_replace('/[^a-z0-9_-]/', '', $segment);
    $file    = __DIR__ . '/api/' . $segment . '.php';
    if ($segment !== '' && is_file($file)) { require $file; exit; }
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not found']); exit;
}

// ── Pages ─────────────────────────────────────────────────────────────────────
$routes = [
    '/'                => 'pages/dashboard.php',
    '/login'           => 'pages/login.php',
    '/logout'          => 'pages/logout.php',
    '/activate'        => 'pages/activate.php',
    '/setup-2fa'       => 'pages/setup-2fa.php',
    '/settings'        => 'pages/settings.php',
    '/admin'           => 'pages/admin.php',
    '/forgot-password' => 'pages/forgot-password.php',
    '/reset-password'  => 'pages/reset-password.php',
    '/confirm-email'   => 'pages/confirm-email.php',   // sesja 066
    '/setup-account'   => 'pages/setup-account.php',   // sesja 067 — invite flow
    '/bookmarklet'     => 'pages/bookmarklet.php',      // sesja 077 — LetaLink
];

$page = $routes[$uri] ?? null;
if ($page && is_file(__DIR__ . '/' . $page)) { require __DIR__ . '/' . $page; exit; }

http_response_code(404);
$f404 = __DIR__ . '/pages/404.php';
if (is_file($f404)) { require $f404; exit; }
echo '<!DOCTYPE html><html><body style="font-family:system-ui;text-align:center;padding:4rem">
<h1 style="color:#690B22">404</h1><p>Page not found.</p><a href="/" style="color:#690B22">← Home</a></body></html>';
