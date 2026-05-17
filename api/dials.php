<?php
/**
 * LetaDial — Dials API
 * ====================
 * GET    /api/dials?group_id=X      list dials for group (null = all)
 * POST   /api/dials                 create  {group_id, title, url, notes}
 * PUT    /api/dials/{id}            update  {title, url, notes} OR move {group_id}
 * DELETE /api/dials/{id}            delete
 * POST   /api/dials/reorder         reorder {group_id, ids:[1,2,3]}
 * POST   /api/dials/{id}/click      record click (no CSRF — low risk)
 * POST   /api/dials/{id}/duplicate  duplicate dial {group_id}
 * POST   /api/dials/bulk-delete     bulk delete   {ids:[1,2,3]}
 * POST   /api/dials/bulk-move       bulk move     {ids:[...], group_id:X}
 * POST   /api/dials/bulk-duplicate  bulk duplicate{ids:[...], group_id:X}
 *
 * Sesja 054: notes field added to create + update
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

header('Content-Type: application/json; charset=UTF-8');

$user = Auth::getUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated.']); exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$parts  = array_values(array_filter(explode('/', trim($path, '/'))));
$sub    = $parts[2] ?? null;
$action = $parts[3] ?? null;

// ── GET /api/dials?group_id=X ─────────────────────────────────────────────────
if ($method === 'GET' && $sub === null) {
    $groupId = isset($_GET['group_id']) && ctype_digit($_GET['group_id'])
        ? (int)$_GET['group_id'] : null;
    $dials = Dial::getAll($user['id'], $groupId);
    echo json_encode(['ok' => true, 'dials' => $dials]);
    exit;
}

// ── POST /api/dials/reorder ───────────────────────────────────────────────────
if ($method === 'POST' && $sub === 'reorder') {
    CSRF::require();
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $groupId = (int)($body['group_id'] ?? 0);
    $ids     = array_map('intval', (array)($body['ids'] ?? []));
    echo json_encode(Dial::reorder($user['id'], $groupId, $ids));
    exit;
}

// ── POST /api/dials/bulk-delete ───────────────────────────────────────────────
if ($method === 'POST' && $sub === 'bulk-delete') {
    CSRF::require();
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $ids  = (array)($body['ids'] ?? []);
    $result = Dial::bulkDelete($ids, $user['id']);
    http_response_code($result['ok'] ? 200 : 422);
    echo json_encode($result);
    exit;
}

// ── POST /api/dials/bulk-move ─────────────────────────────────────────────────
if ($method === 'POST' && $sub === 'bulk-move') {
    CSRF::require();
    $body          = json_decode(file_get_contents('php://input'), true) ?? [];
    $ids           = (array)($body['ids'] ?? []);
    $targetGroupId = (int)($body['group_id'] ?? 0);
    if (!$targetGroupId) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'group_id required.']); exit;
    }
    $result = Dial::bulkMove($ids, $user['id'], $targetGroupId);
    http_response_code($result['ok'] ? 200 : 422);
    echo json_encode($result);
    exit;
}

// ── POST /api/dials/bulk-duplicate ────────────────────────────────────────────
if ($method === 'POST' && $sub === 'bulk-duplicate') {
    CSRF::require();
    $body          = json_decode(file_get_contents('php://input'), true) ?? [];
    $ids           = (array)($body['ids'] ?? []);
    $targetGroupId = (int)($body['group_id'] ?? 0);
    if (!$targetGroupId) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'group_id required.']); exit;
    }
    $result = Dial::bulkDuplicate($ids, $user['id'], $targetGroupId);
    http_response_code($result['ok'] ? 200 : 422);
    echo json_encode($result);
    exit;
}

// ── POST /api/dials/{id}/click ────────────────────────────────────────────────
if ($method === 'POST' && $sub !== null && ctype_digit($sub) && $action === 'click') {
    Dial::recordClick((int)$sub, $user['id']);
    echo json_encode(['ok' => true]);
    exit;
}

// ── POST /api/dials/{id}/duplicate ───────────────────────────────────────────
if ($method === 'POST' && $sub !== null && ctype_digit($sub) && $action === 'duplicate') {
    CSRF::require();
    $body          = json_decode(file_get_contents('php://input'), true) ?? [];
    $targetGroupId = (int)($body['group_id'] ?? 0);
    if (!$targetGroupId) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'group_id required.']); exit;
    }
    $result = Dial::duplicate((int)$sub, $user['id'], $targetGroupId);
    http_response_code($result['ok'] ? 201 : 422);
    echo json_encode($result);
    exit;
}

// ── POST /api/dials — create ──────────────────────────────────────────────────
if ($method === 'POST' && $sub === null) {
    CSRF::require();
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $groupId = (int)($body['group_id'] ?? 0);
    $title   = trim($body['title'] ?? '');
    $url     = trim($body['url']   ?? '');
    $notes   = trim($body['notes'] ?? '');
    $result  = Dial::create($user['id'], $groupId, $title, $url, $notes);
    http_response_code($result['ok'] ? 201 : 422);
    echo json_encode($result);
    exit;
}

// ── PUT /api/dials/{id} — update title/url/notes OR move to group ─────────────
if ($method === 'PUT' && $sub !== null && ctype_digit($sub)) {
    CSRF::require();
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    // Move-to-group: body contains only group_id (no url key)
    if (isset($body['group_id']) && !isset($body['url'])) {
        $newGroupId = (int)$body['group_id'];
        $result     = Dial::moveToGroup((int)$sub, $user['id'], $newGroupId);
        http_response_code($result['ok'] ? 200 : 422);
        echo json_encode($result);
        exit;
    }

    // Normal update: title + url + notes
    $title  = trim($body['title'] ?? '');
    $url    = trim($body['url']   ?? '');
    $notes  = trim($body['notes'] ?? '');
    $result = Dial::update((int)$sub, $user['id'], $title, $url, $notes);
    http_response_code($result['ok'] ? 200 : 422);
    echo json_encode($result);
    exit;
}

// ── DELETE /api/dials/{id} ────────────────────────────────────────────────────
if ($method === 'DELETE' && $sub !== null && ctype_digit($sub)) {
    CSRF::require();
    $result = Dial::delete((int)$sub, $user['id']);
    http_response_code($result['ok'] ? 200 : 422);
    echo json_encode($result);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
