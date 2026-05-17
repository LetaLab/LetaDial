<?php
/**
 * LetaDial — Import API
 *
 * POST /api/import   — import JSON file
 *   Body: multipart/form-data with file field "file"
 *         OR raw JSON body (Content-Type: application/json)
 *   Returns: {"ok":true,"groups":N,"dials":N,"skipped":N,"format":"..."}
 *         or {"ok":false,"error":"..."}
 *
 * GET /api/import    — not allowed
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

header('Content-Type: application/json; charset=UTF-8');

$user = Auth::getUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated.']); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']); exit;
}

CSRF::require();

// ── Read JSON ─────────────────────────────────────────────────────────────────

$json = '';

// Option A: multipart file upload
if (isset($_FILES['file'])) {
    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        http_response_code(422);
        echo json_encode(['error' => 'Upload error: ' . $f['error']]); exit;
    }
    if ($f['size'] > 10 * 1024 * 1024) {
        http_response_code(422);
        echo json_encode(['error' => 'File too large (max 10MB).']); exit;
    }
    $json = file_get_contents($f['tmp_name']);
}

// Option B: raw JSON body (sent by JS fetch with Content-Type: application/json)
if (!$json) {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $json = file_get_contents('php://input');
    }
}

// Option C: form POST with 'json' field (base64 encoded, for browser compatibility)
if (!$json && isset($_POST['json'])) {
    $json = base64_decode($_POST['json']);
}

if (!$json) {
    http_response_code(422);
    echo json_encode(['error' => 'No file provided. Send as multipart file upload or JSON body.']); exit;
}

// ── Rate limit: max 10 imports per hour ───────────────────────────────────────
if (RateLimit::check('import', (string)$user['id'], 10, 3600, 3600)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many import requests. Try again later.']); exit;
}

// ── Import ────────────────────────────────────────────────────────────────────
$result = Import::fromJson($json, $user['id']);

http_response_code($result['ok'] ? 200 : 422);
echo json_encode($result);
