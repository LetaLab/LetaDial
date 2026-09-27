<?php
/**
 * LetaDial — Update API (sesja 059 + sesja 065 + SEC-079 + SEC-155)
 *
 * GET  /api/update              — cached GitHub Release status (sesja 059)
 * POST /api/update/refresh      — force-refresh GitHub Release cache
 * GET  /api/update/git-check    — git fetch + log HEAD..origin/main (sesja 065)
 * POST /api/update/git-pull     — git pull, requires current password (SEC-079 re-auth)
 *
 * Wszystkie endpointy: tylko admin, z ukonczonym 2FA (SEC-155, patrz nizej).
 * POST endpointy: CSRF wymagany.
 * POST /api/update/git-pull dodatkowo wymaga pola "password" w body —
 * skradziona 30-dniowa sesja admina sama w sobie nie wystarcza już do
 * wywołania update'u (który jest RCE-equivalent, jeśli origin repo
 * zostanie kiedyś skompromitowane).
 *
 * SEC-155 (SEC_AND_BUG_ANIH_PLAN.md, Czesc XVIII): "tylko admin" powyzej
 * dawniej sprawdzalo wylacznie role === 'admin', nigdy totp_enabled —
 * konto administratora, ktore jeszcze nie ukonczylo /setup-2fa, dostaje
 * w pelni totp_verified=1 sesje juz po samym hasle (Auth::login()), bez
 * limitu 15 minut jak przy prawdziwie oczekujacym 2FA (SEC-129a). git-pull
 * jest z definicji rownowazne RCE, jesli origin repo zostanie kiedys
 * skompromitowane — nie moze byc osiagalne przez konto, ktore nigdy nie
 * ukonczylo obowiazkowego 2FA. Ten sam fix co w admin_api.php/admin_page.php.
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

header('Content-Type: application/json; charset=UTF-8');

$user = Auth::getUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']); exit;
}
if ($user['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin only.']); exit;
}
// SEC-155 (SEC_AND_BUG_ANIH_PLAN.md, Czesc XVIII): same gap and same fix as
// admin_api.php/admin_page.php — git-pull is functionally RCE-equivalent if
// the origin repo were ever compromised (see gitPull()'s own docblock), so
// it must not be reachable by an admin account that has never completed
// the 2FA setup the rest of this app treats as mandatory. See
// admin_page.php for the full rationale.
if (!$user['totp_enabled']) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Two-factor authentication setup is required before using admin features. Complete setup at /setup-2fa.']); exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$parts  = array_values(array_filter(explode('/', trim($path, '/'))));
$action = $parts[2] ?? null; // /api/update/git-check → 'git-check'

// ── GET /api/update/git-check ─────────────────────────────────────────────────
if ($method === 'GET' && $action === 'git-check') {
    // Rate limit: 10 sprawdzeń na godzinę
    if (RateLimit::check('git_check', (string)$user['id'], 10, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many requests. Try again later.']); exit;
    }
    $result = Updater::gitCheck();
    http_response_code($result['ok'] ? 200 : 500);
    echo json_encode($result);
    exit;
}

// ── POST /api/update/git-pull ─────────────────────────────────────────────────
if ($method === 'POST' && $action === 'git-pull') {
    CSRF::require();

    // Rate limit: 5 pull'ów na godzinę
    if (RateLimit::check('git_pull', (string)$user['id'], 5, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many update requests. Try again later.']); exit;
    }

    // SEC-079: re-auth. Pulling and running new code from GitHub is
    // functionally equivalent to remote code execution if the upstream
    // repo were ever compromised — a stolen 30-day session cookie alone
    // must not be enough to trigger it. Require the current password.
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $password = $body['password'] ?? '';
    $row      = DB::row("SELECT password_hash FROM users WHERE id = ?", [$user['id']]);
    if ($password === '' || !$row || !Password::verifyAndRehash($password, $row['password_hash'], (int)$user['id'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Incorrect password. Re-enter your password to confirm the update.']); exit;
    }

    $result = Updater::gitPull();

    // Clear OPcache so freshly-pulled PHP (including security fixes) takes
    // effect immediately, rather than waiting for the next
    // opcache.revalidate_freq cycle (only matters if validate_timestamps
    // is disabled server-side — harmless no-op otherwise).
    if (function_exists('opcache_reset')) {
        opcache_reset();
    }

    http_response_code($result['ok'] ? 200 : 500);
    echo json_encode($result);
    exit;
}

// ── POST /api/update/refresh — force GitHub Release cache (sesja 059) ─────────
if ($method === 'POST' && $action === 'refresh') {
    CSRF::require();
    if (RateLimit::check('update_check', (string)$user['id'], 10, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many requests. Try again later.']); exit;
    }
    $result = Updater::forceCheck();
    if ($result === null) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Could not reach GitHub. Check GITHUB_REPO in config.php.']); exit;
    }
    echo json_encode(['ok' => true, ...$result]);
    exit;
}

// ── GET /api/update — cached GitHub Release status (sesja 059) ────────────────
if ($method === 'GET' && $action === null) {
    $result = Updater::check();
    if ($result === null) {
        echo json_encode(['ok' => false, 'error' => 'Update check not configured (GITHUB_REPO missing in config.php).']);
        exit;
    }
    echo json_encode(['ok' => true, ...$result]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
