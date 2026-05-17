<?php
/**
 * LetaDial — Groups API
 * REST endpoint: /api/groups
 *
 * GET    /api/groups              — list all groups for current user
 * POST   /api/groups              — create group   {name}
 * PUT    /api/groups/{id}         — rename group   {name}
 * PUT    /api/groups/{id}/style   — set icon+color {icon, color}
 * DELETE /api/groups/{id}         — delete group
 * POST   /api/groups/reorder      — reorder        {ids: [1,2,3,...]}
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

header('Content-Type: application/json; charset=UTF-8');

// All group API calls require full authentication
$user = Auth::getUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated.']); exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// Parse sub-path: /api/groups or /api/groups/123 or /api/groups/reorder
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$parts  = array_filter(explode('/', trim($path, '/')));
$parts  = array_values($parts); // re-index: ['api', 'groups', '123'?, 'style'?]
$sub    = $parts[2] ?? null;    // '123' or 'reorder' or null
$action = $parts[3] ?? null;    // 'style' or null

// ── GET /api/groups ───────────────────────────────────────────────────────────
if ($method === 'GET' && $sub === null) {
    $groups = Group::getAll($user['id']);
    echo json_encode(['ok' => true, 'groups' => $groups]);
    exit;
}

// ── POST /api/groups/reorder ──────────────────────────────────────────────────
if ($method === 'POST' && $sub === 'reorder') {
    CSRF::require();
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $ids  = array_map('intval', $body['ids'] ?? []);
    echo json_encode(Group::reorder($user['id'], $ids));
    exit;
}

// ── POST /api/groups — create ─────────────────────────────────────────────────
if ($method === 'POST' && $sub === null) {
    CSRF::require();
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $name = trim($body['name'] ?? $_POST['name'] ?? '');
    $result = Group::create($user['id'], $name);
    http_response_code($result['ok'] ? 201 : 422);
    echo json_encode($result);
    exit;
}

// ── PUT /api/groups/{id}/style — set icon + color ─────────────────────────────
if ($method === 'PUT' && $sub !== null && ctype_digit($sub) && $action === 'style') {
    CSRF::require();
    $body  = json_decode(file_get_contents('php://input'), true) ?? [];
    // null = clear, absent key = keep existing (we pass null for both to allow clearing)
    $icon  = array_key_exists('icon',  $body) ? ($body['icon']  === '' ? null : $body['icon'])  : null;
    $color = array_key_exists('color', $body) ? ($body['color'] === '' ? null : $body['color']) : null;
    $result = Group::setStyle((int)$sub, $user['id'], $icon, $color);
    http_response_code($result['ok'] ? 200 : 422);
    echo json_encode($result);
    exit;
}

// ── PUT /api/groups/{id} — rename ─────────────────────────────────────────────
if ($method === 'PUT' && $sub !== null && ctype_digit($sub) && $action === null) {
    CSRF::require();
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $name = trim($body['name'] ?? '');
    $result = Group::rename((int)$sub, $user['id'], $name);
    http_response_code($result['ok'] ? 200 : 422);
    echo json_encode($result);
    exit;
}

// ── DELETE /api/groups/{id} ───────────────────────────────────────────────────
if ($method === 'DELETE' && $sub !== null && ctype_digit($sub)) {
    CSRF::require();
    $result = Group::delete((int)$sub, $user['id']);
    http_response_code($result['ok'] ? 200 : 422);
    echo json_encode($result);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
