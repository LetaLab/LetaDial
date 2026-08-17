<?php
/**
 * LetaDial — Meta API (sesja 057)
 *
 * POST /api/meta
 *   Body: {"url": "https://example.com"}
 *   Returns: {"ok":true,"title":"...","description":"..."}
 *         or {"ok":false,"error":"..."}
 *
 * Auth required. CSRF required. Rate limited.
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

// Rate limit: max 30 meta fetches per hour per user
// (each "Add dial" auto-fetch = 1 request; user can also click manually)
if (RateLimit::check('meta_fetch', (string)$user['id'], 30, 3600, 3600)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Too many requests. Try again later.']); exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$url  = trim($body['url'] ?? '');

if (!$url) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'URL required.']); exit;
}

$result = Meta::fetch($url);

http_response_code($result['ok'] ? 200 : 422);
echo json_encode($result);
