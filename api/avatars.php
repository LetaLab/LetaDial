<?php
/**
 * LetaDial — Avatar API (sesja 078)
 *
 * GET    /api/avatars/{userId}   stream avatar WebP (auth required — any logged-in user)
 * POST   /api/avatars/upload     upload/replace OWN avatar (CSRF + rate limit)
 * DELETE /api/avatars            remove OWN avatar (CSRF)
 *
 * Accepted: JPEG, PNG, GIF, WebP — max 5 MB
 * Output:   128×128 WebP (GD re-encodes always — strips all metadata)
 * Storage:  storage/avatars/u{userId}.webp — NOT web-accessible directly
 *           (blocked by both .htaccess and nginx `location ^~ /storage/ { deny all; }`)
 *
 * A user can only upload/delete their OWN avatar — there is no userId param on
 * POST/DELETE, it always targets Auth::getUser()['id']. GET is intentionally
 * open to any authenticated user for any userId, since avatars are displayed
 * cross-account in the admin Users panel.
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

$user = Auth::getUser();
if (!$user) {
    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => 'Not authenticated.']); exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$parts  = array_values(array_filter(explode('/', trim($path, '/'))));
// parts[0]=api  parts[1]=avatars  parts[2]=userId|upload
$sub    = $parts[2] ?? null;

// ── GET /api/avatars/{userId} — stream avatar ────────────────────────────────
if ($method === 'GET' && $sub !== null && ctype_digit($sub)) {
    Avatar::serve((int)$sub);
    exit;
}

// ── POST /api/avatars/upload — upload/replace OWN avatar ─────────────────────
// PHP only populates $_FILES for multipart POST — not PUT. File field: "avatar"
if ($method === 'POST' && $sub === 'upload') {
    CSRF::require();

    // Rate limit: 20 uploads per hour per user — matches Thumbnail/GroupIcon uploads
    if (RateLimit::check('avatar_upload', (string)$user['id'], 20, 3600, 3600)) {
        http_response_code(429);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => 'Too many upload requests. Try again later.']); exit;
    }

    $err = $_FILES['avatar']['error']    ?? UPLOAD_ERR_NO_FILE;
    $tmp = $_FILES['avatar']['tmp_name'] ?? '';
    $sz  = $_FILES['avatar']['size']     ?? 0;

    if ($err !== UPLOAD_ERR_OK || !$tmp) {
        $errMsg = match ($err) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File too large (max 5 MB).',
            UPLOAD_ERR_NO_FILE                        => 'No file uploaded.',
            default                                   => 'Upload error (code ' . $err . ').',
        };
        http_response_code(400);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => $errMsg]); exit;
    }

    if ($sz > 5 * 1024 * 1024) {
        http_response_code(422);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => 'File too large (max 5 MB).']); exit;
    }

    $ok = Avatar::processUpload($user['id'], $tmp);
    header('Content-Type: application/json; charset=UTF-8');

    if (!$ok) {
        http_response_code(422);
        echo json_encode(['error' => 'Avatar processing failed. Accepted: JPEG, PNG, GIF, WebP — max 5 MB.']); exit;
    }

    echo json_encode([
        'ok'  => true,
        'url' => Avatar::webUrl($user['id']),
    ]);
    exit;
}

// ── DELETE /api/avatars — remove OWN avatar ───────────────────────────────────
if ($method === 'DELETE' && $sub === null) {
    CSRF::require();
    Avatar::delete($user['id']);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['error' => 'Method not allowed.']);
