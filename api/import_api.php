<?php
/**
 * LetaDial — Import API (SEC-107)
 *
 * POST /api/import   — import JSON file
 *   Body: multipart/form-data with file field "file"
 *         OR raw JSON body (Content-Type: application/json)
 *   Returns: {"ok":true,"groups":N,"dials":N,"skipped":N,"format":"..."}
 *         or {"ok":false,"error":"..."}
 *
 * GET /api/import    — not allowed
 *
 * SEC-107: the raw-JSON-body and base64-form-field read paths now enforce
 * the same 10MB ceiling the multipart upload path already had via
 * $_FILES metadata, instead of reading an unbounded amount into memory
 * before Import::fromJson()'s own size check gets a chance to run.
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

// SEC-107: shared ceiling for the two body-reading paths below, matching
// Import::MAX_FILE_SIZE (10MB) — the limit Option A (multipart upload)
// already enforces via $_FILES['file']['size'] before it ever reads the
// file into memory. Options B and C previously had no size awareness of
// their own at all; Import::fromJson()'s own strlen() check only runs
// AFTER the full string is already sitting in memory, which is too late
// to bound the memory an oversized request can make this endpoint use.
$importMaxBodyBytes = 10 * 1024 * 1024;

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
//
// SEC-107: the length argument below caps the actual read at
// $importMaxBodyBytes + 1 byte — reading one byte past the limit (rather
// than exactly at it) lets us tell "the body was exactly on the limit"
// apart from "the body was larger and got truncated here", so oversized
// requests get a clear 422 instead of a confusing "Invalid JSON file"
// error from a payload that was silently cut off mid-object.
if (!$json) {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $json = file_get_contents('php://input', false, null, 0, $importMaxBodyBytes + 1);
        if ($json !== false && strlen($json) > $importMaxBodyBytes) {
            http_response_code(422);
            echo json_encode(['error' => 'File too large (max 10MB).']); exit;
        }
    }
}

// Option C: form POST with 'json' field (base64 encoded, for browser compatibility)
//
// SEC-107: $_POST['json'] is already fully populated in memory by PHP
// itself before this code ever runs (bounded only by the server's own
// post_max_size php.ini setting, which this endpoint has no control over
// and which may be considerably larger than 10MB on a given host) — this
// check cannot reduce THAT memory use, but it does stop a second, equally
// large string being allocated by base64_decode() on top of it, and gives
// a clear error instead of Import::fromJson()'s generic size-rejection
// message. Base64 inflates size by ~4/3, so the encoded ceiling is scaled
// up accordingly rather than reusing the raw $importMaxBodyBytes value.
if (!$json && isset($_POST['json'])) {
    $maxEncodedBytes = (int)ceil($importMaxBodyBytes * 4 / 3) + 4;
    if (strlen($_POST['json']) > $maxEncodedBytes) {
        http_response_code(422);
        echo json_encode(['error' => 'File too large (max 10MB).']); exit;
    }
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
