<?php
/**
 * LetaDial — Group Icon API (sesja 052)
 *
 * GET    /api/group_icons/{groupId}          stream icon (auth required)
 * POST   /api/group_icons/{groupId}/upload   upload custom icon (CSRF + rate limit)
 * DELETE /api/group_icons/{groupId}          remove icon (CSRF)
 *
 * Accepted: JPEG, PNG, GIF, WebP — max 2 MB
 * Output:   32×32 WebP (GD re-encodes always — strips all metadata)
 * Storage:  storage/group_icons/u{userId}/{groupId}.webp — NOT web-accessible
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

$user = Auth::getUser();
if (!$user) {
    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => 'Not authenticated.']); exit;
}

$method  = $_SERVER['REQUEST_METHOD'];
$path    = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$parts   = array_values(array_filter(explode('/', trim($path, '/'))));
// parts[0]=api  parts[1]=group_icons  parts[2]=groupId  parts[3]=upload
$groupId = isset($parts[2]) && ctype_digit($parts[2]) ? (int)$parts[2] : null;
$action  = $parts[3] ?? null;

if (!$groupId) {
    http_response_code(404);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => 'Group ID required.']); exit;
}

// Verify ownership — users cannot access/modify other users' groups
$group = Group::getOne($groupId, $user['id']);
if (!$group) {
    http_response_code(404);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => 'Not found.']); exit;
}

// ── GET /api/group_icons/{groupId} — stream icon ──────────────────────────────
if ($method === 'GET' && $action === null) {
    GroupIcon::serve($groupId, $user['id']);
    exit;
}

// ── POST /api/group_icons/{groupId}/upload — upload custom icon ───────────────
// PHP only populates $_FILES for multipart POST.
// File field name: "icon"
if ($method === 'POST' && $action === 'upload') {
    CSRF::require();

    // Rate limit: 20 uploads per hour per user
    if (RateLimit::check('group_icon_upload', (string)$user['id'], 20, 3600, 3600)) {
        http_response_code(429);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => 'Too many upload requests. Try again later.']); exit;
    }

    $err = $_FILES['icon']['error'] ?? UPLOAD_ERR_NO_FILE;
    $tmp = $_FILES['icon']['tmp_name'] ?? '';
    $sz  = $_FILES['icon']['size'] ?? 0;

    if ($err !== UPLOAD_ERR_OK || !$tmp) {
        $errMsg = match($err) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File too large (max 2 MB).',
            UPLOAD_ERR_NO_FILE                        => 'No file uploaded.',
            default                                   => 'Upload error (code ' . $err . ').',
        };
        http_response_code(400);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => $errMsg]); exit;
    }

    if ($sz > 2 * 1024 * 1024) {
        http_response_code(422);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => 'File too large (max 2 MB).']); exit;
    }

    $ok = GroupIcon::processUpload($groupId, $user['id'], $tmp);
    header('Content-Type: application/json; charset=UTF-8');

    if (!$ok) {
        http_response_code(422);
        echo json_encode(['error' => 'Icon processing failed. Accepted: JPEG, PNG, GIF, WebP — max 2 MB.']); exit;
    }

    echo json_encode([
        'ok'  => true,
        'url' => GroupIcon::webUrl($groupId),
    ]);
    exit;
}

// ── DELETE /api/group_icons/{groupId} — remove icon ──────────────────────────
if ($method === 'DELETE' && $action === null) {
    CSRF::require();
    GroupIcon::delete($groupId, $user['id']);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['error' => 'Method not allowed.']);
