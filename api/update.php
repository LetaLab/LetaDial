<?php
/**
 * LetaDial — Update API (sesja 059 + sesja 065)
 *
 * GET  /api/update              — cached GitHub Release status (sesja 059)
 * POST /api/update/refresh      — force-refresh GitHub Release cache
 * GET  /api/update/git-check    — git fetch + log HEAD..origin/main (sesja 065)
 * POST /api/update/git-pull     — git pull + fix_permissions.sh    (sesja 065)
 *
 * Wszystkie endpointy: tylko admin.
 * POST endpointy: CSRF wymagany.
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
    $result = Updater::gitPull();
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
