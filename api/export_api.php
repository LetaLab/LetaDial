<?php
/**
 * LetaDial — Export API
 *
 * POST /api/export   — download JSON export file
 *   Returns: JSON file attachment (letadial_export_YYYY-MM-DD.json)
 *
 * SEC-102: was GET. A GET-only endpoint can be triggered cross-site by a
 * plain <img>/<a>/<form> pointed at this URL while the victim is logged
 * in — the response itself can't be READ cross-origin without CORS
 * (which this app never sends), so this was never a data-exfiltration
 * path. But it's the one read endpoint in the whole app that was
 * reachable without CSRF at all, which is an inconsistency in its own
 * right regardless of how small the practical risk was. Switched to
 * POST + CSRF::require() to close it and match every other endpoint's
 * baseline. The only caller (app.js import_export_module.doExport())
 * was updated in the same change to POST + fetch + Blob download,
 * since a plain <a href> can only ever issue a GET.
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

$user = Auth::getUser();
if (!$user) {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated.']); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']); exit;
}

CSRF::require();

Export::download($user['id']);
