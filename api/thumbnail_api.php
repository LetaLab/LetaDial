<?php
/**
 * LetaDial — Thumbnail API (thumbnail_api.php)
 * ======================================
 * Named after src/thumbnail_src.php's class (Thumbnail), matching the
 * project-wide rule: every src/ class file ends in _src.php, every
 * pages/ template ends in _page.php, every api/ endpoint ends in
 * _api.php, and files about the same topic share the same stem
 * (thumbnail_src.php / thumbnail_api.php). This is unrelated to the
 * public URL, which stays /api/thumbs/... unchanged — the URL-to-file
 * mapping lives in index.php's $api_routes table, not in this filename.
 *
 * GET  /api/thumbs/{dialId}          stream thumbnail image (auth required)
 * POST /api/thumbs/{dialId}          refresh/regenerate thumbnail (CSRF + rate limit)
 * POST /api/thumbs/{dialId}/upload   upload custom thumbnail (multipart/form-data)
 *
 * Thumbnails are stored in storage/thumbnails/u{userId}/{dialId}.webp
 * — NOT web-accessible. All access goes through this endpoint.
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
// parts[0]=api  parts[1]=thumbs  parts[2]=dialId  parts[3]=upload
$dialId = isset($parts[2]) && ctype_digit($parts[2]) ? (int)$parts[2] : null;
$action = $parts[3] ?? null;

if (!$dialId) {
    http_response_code(404);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => 'Dial ID required.']); exit;
}

// Verify dial belongs to user before serving or generating
$dial = Dial::getOne($dialId, $user['id']);
if (!$dial) {
    http_response_code(404);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => 'Not found.']); exit;
}

// ── GET: stream thumbnail image ───────────────────────────────────────────────
if ($method === 'GET' && $action === null) {
    Thumbnail::serve($dialId, $user['id']);
    exit;
}

// ── POST /api/thumbs/{dialId}/upload — upload custom thumbnail ────────────────
// PHP only populates $_FILES for multipart POST — not PUT.
// File field name: "thumb"
if ($method === 'POST' && $action === 'upload') {
    CSRF::require();

    // Rate limit: 20 uploads per hour per user
    if (RateLimit::check('thumb_upload', (string)$user['id'], 20, 3600, 3600)) {
        http_response_code(429);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => 'Too many upload requests. Try again later.']); exit;
    }

    $err = $_FILES['thumb']['error'] ?? UPLOAD_ERR_NO_FILE;
    $tmp = $_FILES['thumb']['tmp_name'] ?? '';
    $sz  = $_FILES['thumb']['size'] ?? 0;

    if ($err !== UPLOAD_ERR_OK || !$tmp) {
        $errMsg = match($err) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File too large (max 5 MB).',
            UPLOAD_ERR_NO_FILE                        => 'No file uploaded.',
            default                                   => 'Upload error (code ' . $err . ').',
        };
        http_response_code(400);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => $errMsg]); exit;
    }

    // BUG-028: explicit pre-check, matching avatar_api.php / group_icon_api.php
    // — this endpoint was the one upload route without one. Not a new
    // security boundary: Thumbnail::processUpload() already rejects an
    // oversized file safely on its own (filesize() check before any Imagick
    // read touches the bytes) — this only upgrades a large file from a
    // generic 422 "processing failed" to the same clear "File too large"
    // message the other two upload endpoints already give.
    if ($sz > 5 * 1024 * 1024) {
        http_response_code(422);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => 'File too large (max 5 MB).']); exit;
    }

    $ok = Thumbnail::processUpload($dialId, $user['id'], $tmp);
    header('Content-Type: application/json; charset=UTF-8');
    if (!$ok) {
        http_response_code(422);
        echo json_encode(['error' => 'Thumbnail processing failed. Accepted: JPEG, PNG, GIF, WebP — max 5 MB.']); exit;
    }

    echo json_encode([
        'ok'  => true,
        'url' => Thumbnail::webUrl($dialId, $user['id']),
    ]);
    exit;
}

// ── POST: generate / refresh thumbnail ────────────────────────────────────────
if ($method === 'POST' && $action === null) {
    CSRF::require();

    // Rate limit: 60 refreshes per hour per user
    if (RateLimit::check('thumb_refresh', (string)$user['id'], 60, 3600, 3600)) {
        http_response_code(429);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => 'Too many thumbnail requests. Try again later.']); exit;
    }

    $ok = Thumbnail::generate($dialId, $user['id'], $dial['url']);
    header('Content-Type: application/json; charset=UTF-8');
    if (!$ok) {
        http_response_code(500);
        echo json_encode(['error' => 'Thumbnail generation failed. GD+WebP support required.']); exit;
    }

    echo json_encode([
        'ok'  => true,
        'url' => Thumbnail::webUrl($dialId, $user['id']),
    ]);
    exit;
}

http_response_code(405);
header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['error' => 'Method not allowed.']);