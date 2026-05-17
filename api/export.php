<?php
/**
 * LetaDial — Export API
 *
 * GET /api/export   — download JSON export file
 *   Returns: JSON file attachment (letadial_export_YYYY-MM-DD.json)
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

$user = Auth::getUser();
if (!$user) {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated.']); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']); exit;
}

Export::download($user['id']);
