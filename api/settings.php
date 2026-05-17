<?php
/**
 * LetaDial — Settings API (sesja 058)
 *
 * POST /api/settings/password      — change password
 *   Body: {current_password, new_password, confirm_password}
 *   Returns: {ok:true} — all sessions invalidated, client redirects to /login
 *         or {ok:false, error:"..."}
 *
 * POST /api/settings/backup-codes  — regenerate 2FA backup codes
 *   Body: {code}  — current TOTP code (required to confirm identity)
 *   Returns: {ok:true, backup_codes:["XXXX-XXXX", ...]}
 *         or {ok:false, error:"..."}
 *
 * GET  /api/settings/backup-count  — number of unused backup codes remaining
 *   Returns: {ok:true, count:N}
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
// /api/settings/password → ['api','settings','password']
$action = $parts[2] ?? null;

// ── GET /api/settings/backup-count ───────────────────────────────────────────
if ($method === 'GET' && $action === 'backup-count') {
    if (!$user['totp_enabled']) {
        echo json_encode(['ok' => true, 'count' => 0]); exit;
    }
    $count = (int)(DB::val(
        "SELECT COUNT(*) FROM totp_backup_codes WHERE user_id = ? AND used = 0",
        [$user['id']]
    ) ?? 0);
    echo json_encode(['ok' => true, 'count' => $count]);
    exit;
}

// ── All write actions require POST + CSRF ─────────────────────────────────────
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']); exit;
}

CSRF::require();

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// ── POST /api/settings/password ───────────────────────────────────────────────
if ($action === 'password') {

    // Rate limit: 5 attempts per hour (brute-force on current password)
    if (RateLimit::check('settings_pw', (string)$user['id'], 5, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many attempts. Try again in an hour.']); exit;
    }

    $current = $body['current_password'] ?? '';
    $new     = $body['new_password']     ?? '';
    $confirm = $body['confirm_password'] ?? '';

    if (!$current || !$new || !$confirm) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'All fields are required.']); exit;
    }

    // Verify current password
    $row = DB::row("SELECT password_hash FROM users WHERE id = ?", [$user['id']]);
    if (!$row || !Password::verify($current, $row['password_hash'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Current password is incorrect.']); exit;
    }

    // Validate new password strength
    $errors = Password::validate($new);
    if (!empty($errors)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => implode(' ', $errors)]); exit;
    }

    if ($new !== $confirm) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'New passwords do not match.']); exit;
    }

    if ($new === $current) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'New password must be different from the current one.']); exit;
    }

    // Update password
    $hash = Password::hash($new);
    DB::run("UPDATE users SET password_hash = ? WHERE id = ?", [$hash, $user['id']]);

    // Invalidate ALL sessions — prevents stolen session from remaining valid.
    // Response is already being assembled; sessions deleted here do not affect
    // this response since CSRF was already validated above.
    Auth::logoutAllSessions($user['id']);

    RateLimit::clear('settings_pw', (string)$user['id']);

    echo json_encode(['ok' => true, 'message' => 'Password changed. Please log in again.']);
    exit;
}

// ── POST /api/settings/backup-codes ──────────────────────────────────────────
if ($action === 'backup-codes') {

    if (!$user['totp_enabled'] || !$user['totp_secret']) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => '2FA is not enabled on this account.']); exit;
    }

    // Rate limit: 5 attempts per hour
    if (RateLimit::check('settings_bc', (string)$user['id'], 5, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many attempts. Try again in an hour.']); exit;
    }

    $code = preg_replace('/\s/', '', $body['code'] ?? '');
    if (!$code) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => '2FA code is required.']); exit;
    }

    // Verify TOTP code (or backup code — allow backup codes too)
    $secret  = TOTP::decrypt($user['totp_secret']);
    $valid   = TOTP::verify($secret, $code);

    if (!$valid) {
        // Try backup codes
        $codes = DB::rows(
            "SELECT * FROM totp_backup_codes WHERE user_id = ? AND used = 0",
            [$user['id']]
        );
        foreach ($codes as $bc) {
            if (password_verify($code, $bc['code_hash'])) {
                $valid = true;
                // Mark backup code as used
                DB::run(
                    "UPDATE totp_backup_codes SET used = 1, used_at = NOW() WHERE id = ?",
                    [$bc['id']]
                );
                break;
            }
        }
    }

    if (!$valid) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid 2FA code. Try again.']); exit;
    }

    // Delete all existing backup codes for this user
    DB::run("DELETE FROM totp_backup_codes WHERE user_id = ?", [$user['id']]);

    // Generate 10 fresh backup codes
    $new_codes = [];
    $stmt = DB::get()->prepare(
        "INSERT INTO totp_backup_codes (user_id, code_hash) VALUES (?, ?)"
    );
    for ($i = 0; $i < 10; $i++) {
        $raw         = strtoupper(bin2hex(random_bytes(4))) . '-' . strtoupper(bin2hex(random_bytes(4)));
        $new_codes[] = $raw;
        $stmt->execute([$user['id'], password_hash($raw, PASSWORD_BCRYPT, ['cost' => 10])]);
    }

    RateLimit::clear('settings_bc', (string)$user['id']);

    echo json_encode(['ok' => true, 'backup_codes' => $new_codes]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Unknown settings action.']);
