<?php
/**
 * LetaDial — Admin API (sesja 065 + 066)
 *
 * GET  /api/admin/blocked          — list blocked rate_limit entries
 * POST /api/admin/unblock          — unblock one entry  {key_hash, action}
 * POST /api/admin/unblock-all      — unblock all for IP {key_plain}
 * GET  /api/admin/users            — list all users
 * POST /api/admin/delete-user      — delete user        {user_id}
 * GET  /api/admin/login-history    — recent history     [?ip=x.x.x.x] [?limit=N]
 * GET  /api/admin/install-check    — system check
 * GET  /api/admin/export-blocked   — export             ?format=json|csv
 *
 * sesja 066:
 * GET  /api/admin/sessions              — list all active sessions [?user_id=N]
 * POST /api/admin/sessions/delete       — delete one session  {session_id}
 * POST /api/admin/sessions/delete-user  — delete all for user {user_id}
 * POST /api/admin/force-password        — force reset password {user_id, password}
 *
 * All endpoints: admin role required.
 * All POST endpoints: CSRF required.
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
// /api/admin/blocked        → parts[2] = 'blocked'
// /api/admin/sessions       → parts[2] = 'sessions'
// /api/admin/sessions/delete → parts[2] = 'sessions', parts[3] = 'delete'
$action     = $parts[2] ?? null;
$sub_action = $parts[3] ?? null;

// ── GET /api/admin/blocked ────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'blocked') {
    $min = max(1, (int)($_GET['min'] ?? 3));
    echo json_encode(['ok' => true, 'entries' => Admin::getBlocked($min)]);
    exit;
}

// ── POST /api/admin/unblock ───────────────────────────────────────────────────
if ($method === 'POST' && $action === 'unblock') {
    CSRF::require();
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $keyHash = trim($body['key_hash'] ?? '');
    $act     = trim($body['action']   ?? '');
    if (!$keyHash || !$act) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'key_hash and action required.']); exit;
    }
    $ok = Admin::unblock($keyHash, $act);
    echo json_encode(['ok' => $ok]);
    exit;
}

// ── POST /api/admin/unblock-all ──────────────────────────────────────────────
if ($method === 'POST' && $action === 'unblock-all') {
    CSRF::require();
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $keyPlain = trim($body['key_plain'] ?? '');
    if (!$keyPlain) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'key_plain required.']); exit;
    }
    $count = Admin::unblockByKey($keyPlain);
    echo json_encode(['ok' => true, 'deleted' => $count]);
    exit;
}

// ── GET /api/admin/users ──────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'users') {
    echo json_encode(['ok' => true, 'users' => Admin::getUsers()]);
    exit;
}

// ── POST /api/admin/delete-user ───────────────────────────────────────────────
if ($method === 'POST' && $action === 'delete-user') {
    CSRF::require();
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $userId = (int)($body['user_id'] ?? 0);
    if (!$userId) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'user_id required.']); exit;
    }
    $result = Admin::deleteUser($userId, $user['id']);
    http_response_code($result['ok'] ? 200 : 422);
    echo json_encode($result);
    exit;
}

// ── GET /api/admin/login-history ──────────────────────────────────────────────
if ($method === 'GET' && $action === 'login-history') {
    $ip    = trim($_GET['ip']    ?? '') ?: null;
    $limit = max(10, min(500, (int)($_GET['limit'] ?? 100)));
    echo json_encode(['ok' => true, 'history' => Admin::getLoginHistory($ip, $limit)]);
    exit;
}

// ── GET /api/admin/install-check ─────────────────────────────────────────────
if ($method === 'GET' && $action === 'install-check') {
    echo json_encode(['ok' => true, 'checks' => Admin::installCheck()]);
    exit;
}

// ── GET /api/admin/export-blocked ────────────────────────────────────────────
if ($method === 'GET' && $action === 'export-blocked') {
    $format = in_array($_GET['format'] ?? '', ['json', 'csv']) ? $_GET['format'] : 'json';
    $data   = Admin::exportBlocked($format);
    $date   = date('Y-m-d');
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"letadial_blocked_{$date}.csv\"");
    } else {
        header('Content-Type: application/json; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"letadial_blocked_{$date}.json\"");
    }
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo $data;
    exit;
}

// ── sesja 066: Sessions ───────────────────────────────────────────────────────

// GET /api/admin/sessions[?user_id=N]
if ($method === 'GET' && $action === 'sessions' && $sub_action === null) {
    $filterUserId = isset($_GET['user_id']) && ctype_digit($_GET['user_id'])
        ? (int)$_GET['user_id'] : null;
    $sessions = Admin::getSessions($filterUserId);
    echo json_encode(['ok' => true, 'sessions' => $sessions]);
    exit;
}

// POST /api/admin/sessions/delete  — {session_id}
if ($method === 'POST' && $action === 'sessions' && $sub_action === 'delete') {
    CSRF::require();
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $sessionId = trim($body['session_id'] ?? '');
    if (!$sessionId) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'session_id required.']); exit;
    }
    // Prevent admin from deleting their own current session via this endpoint
    if ($sessionId === Auth::getSessionId()) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Cannot delete your own current session. Use Sign out instead.']); exit;
    }
    $ok = Admin::deleteSession($sessionId);
    echo json_encode(['ok' => $ok, 'error' => $ok ? null : 'Session not found.']);
    exit;
}

// POST /api/admin/sessions/delete-user  — {user_id}
if ($method === 'POST' && $action === 'sessions' && $sub_action === 'delete-user') {
    CSRF::require();
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $userId = (int)($body['user_id'] ?? 0);
    if (!$userId) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'user_id required.']); exit;
    }
    // If targeting current admin, warn (they will be logged out)
    $count = Admin::deleteUserSessions($userId);
    echo json_encode(['ok' => true, 'deleted' => $count]);
    exit;
}

// ── sesja 066: Force Password Reset ──────────────────────────────────────────

// POST /api/admin/force-password  — {user_id, password}
if ($method === 'POST' && $action === 'force-password') {
    CSRF::require();

    // Rate limit: 10 force-resets per hour (brute-force protection)
    if (RateLimit::check('admin_force_pw', (string)$user['id'], 10, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many requests. Try again in an hour.']); exit;
    }

    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $targetId = (int)($body['user_id']  ?? 0);
    $password = $body['password'] ?? '';

    if (!$targetId || !$password) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'user_id and password required.']); exit;
    }

    $result = Admin::forcePasswordReset($targetId, $password, $user['id']);
    http_response_code($result['ok'] ? 200 : 422);
    echo json_encode($result);
    exit;
}

http_response_code(404);
echo json_encode(['ok' => false, 'error' => 'Unknown admin action.']);
