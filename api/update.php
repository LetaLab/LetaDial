<?php
/**
 * LetaDial — Update Check API (sesja 059)
 *
 * GET  /api/update         — return cached update status (admin only)
 * POST /api/update/refresh — force-refresh from GitHub (admin only, CSRF required)
 *
 * Returns:
 *   {ok:true, current:"2.0.0", latest:"2.1.0", update_available:true,
 *    url:"https://github.com/...", notes:"...", cached:true, checked_at:"..."}
 *
 *   {ok:true, update_available:false, ...}
 *
 *   {ok:false, error:"..."}
 *
 * Non-admin users get 403. GITHUB_REPO not configured → {ok:false, error:"not configured"}.
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

header('Content-Type: application/json; charset=UTF-8');

// Admin only — update checks reveal version info, no need for regular users
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
$action = $parts[2] ?? null; // /api/update/refresh → 'refresh'

// ── POST /api/update/refresh — force check ────────────────────────────────────
if ($method === 'POST' && $action === 'refresh') {
    CSRF::require();

    // Rate limit: max 10 force-checks per hour (GitHub rate limit protection)
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

// ── GET /api/update — cached status ──────────────────────────────────────────
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
