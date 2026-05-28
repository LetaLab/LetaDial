<?php
/**
 * LetaDial — Settings API (sesja 058 + 066)
 *
 * POST /api/settings/password      — change password
 * POST /api/settings/backup-codes  — regenerate 2FA backup codes
 * GET  /api/settings/backup-count  — unused backup codes count
 * POST /api/settings/recent        — toggle Recent tab {disabled}
 *
 * sesja 066:
 * GET  /api/settings/sessions           — list current user's active sessions
 * POST /api/settings/sessions/delete    — delete one session {session_id}
 * POST /api/settings/sessions/delete-all — delete all OTHER sessions (keep current)
 * POST /api/settings/email              — initiate email change {new_email}
 * POST /api/settings/email/cancel       — cancel pending email change
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

header('Content-Type: application/json; charset=UTF-8');

$user = Auth::getUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated.']); exit;
}

$method     = $_SERVER['REQUEST_METHOD'];
$path       = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$parts      = array_values(array_filter(explode('/', trim($path, '/'))));
$action     = $parts[2] ?? null;
$sub_action = $parts[3] ?? null;

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
// (GET sessions is exempt — read-only)

if ($method === 'GET' && $action === 'sessions') {
    // ── GET /api/settings/sessions — list this user's sessions ────────────────
    $currentSessionId = Auth::getSessionId();
    $sessions = DB::rows(
        "SELECT id, ip, user_agent, created_at, last_activity, expires_at, totp_verified
         FROM sessions
         WHERE user_id = ?
         ORDER BY last_activity DESC",
        [$user['id']]
    );
    // Mark the current session
    foreach ($sessions as &$s) {
        $s['is_current'] = ($s['id'] === $currentSessionId);
    }
    unset($s);
    echo json_encode(['ok' => true, 'sessions' => $sessions, 'current_id' => $currentSessionId]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']); exit;
}

CSRF::require();

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// ── POST /api/settings/password ───────────────────────────────────────────────
if ($action === 'password') {

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

    $row = DB::row("SELECT password_hash FROM users WHERE id = ?", [$user['id']]);
    if (!$row || !Password::verify($current, $row['password_hash'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Current password is incorrect.']); exit;
    }

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

    $hash = Password::hash($new);
    DB::run("UPDATE users SET password_hash = ? WHERE id = ?", [$hash, $user['id']]);
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

    if (RateLimit::check('settings_bc', (string)$user['id'], 5, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many attempts. Try again in an hour.']); exit;
    }

    $code = preg_replace('/\s/', '', $body['code'] ?? '');
    if (!$code) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => '2FA code is required.']); exit;
    }

    $secret = TOTP::decrypt($user['totp_secret']);
    $valid  = TOTP::verify($secret, $code);

    if (!$valid) {
        $codes = DB::rows(
            "SELECT * FROM totp_backup_codes WHERE user_id = ? AND used = 0",
            [$user['id']]
        );
        foreach ($codes as $bc) {
            if (password_verify($code, $bc['code_hash'])) {
                $valid = true;
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

    DB::run("DELETE FROM totp_backup_codes WHERE user_id = ?", [$user['id']]);

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

// ── POST /api/settings/recent ─────────────────────────────────────────────────
if ($action === 'recent') {
    $disabled = (bool)($body['disabled'] ?? false);
    DB::run(
        "UPDATE users SET recent_disabled = ? WHERE id = ?",
        [(int)$disabled, $user['id']]
    );
    echo json_encode(['ok' => true, 'disabled' => $disabled]);
    exit;
}

// ── sesja 066: Sessions ───────────────────────────────────────────────────────

// POST /api/settings/sessions/delete — {session_id}
if ($action === 'sessions' && $sub_action === 'delete') {
    $sessionId = trim($body['session_id'] ?? '');
    if (!$sessionId) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'session_id required.']); exit;
    }
    // Verify the session belongs to this user
    $sess = DB::row(
        "SELECT id FROM sessions WHERE id = ? AND user_id = ?",
        [$sessionId, $user['id']]
    );
    if (!$sess) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Session not found.']); exit;
    }
    // Cannot delete current session from here — use Sign out
    if ($sessionId === Auth::getSessionId()) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Cannot delete the current session. Use Sign out instead.']); exit;
    }
    DB::run("DELETE FROM sessions WHERE id = ? AND user_id = ?", [$sessionId, $user['id']]);
    echo json_encode(['ok' => true]);
    exit;
}

// POST /api/settings/sessions/delete-all — delete all OTHER sessions (keep current)
if ($action === 'sessions' && $sub_action === 'delete-all') {
    $currentId = Auth::getSessionId();
    $count = DB::run(
        "DELETE FROM sessions WHERE user_id = ? AND id != ?",
        [$user['id'], $currentId]
    );
    echo json_encode(['ok' => true, 'deleted' => $count]);
    exit;
}

// ── sesja 066: Email Change ───────────────────────────────────────────────────

// POST /api/settings/email — initiate change {new_email}
if ($action === 'email' && $sub_action === null) {

    // Rate limit: 3 email change requests per hour
    if (RateLimit::check('settings_email', (string)$user['id'], 3, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many requests. Try again in an hour.']); exit;
    }

    $newEmail = strtolower(trim($body['new_email'] ?? ''));

    if (!$newEmail) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'New email address is required.']); exit;
    }

    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid email address format.']); exit;
    }

    // Same as current email?
    if ($newEmail === strtolower($user['email'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'This is already your current email address.']); exit;
    }

    // Email already taken?
    $taken = DB::val(
        "SELECT id FROM users WHERE (email = ? OR email_pending = ?) AND id != ?",
        [$newEmail, $newEmail, $user['id']]
    );
    if ($taken) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'This email address is already in use.']); exit;
    }

    // Generate token
    $token   = bin2hex(random_bytes(32)); // 64-char hex
    $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour

    DB::run(
        "UPDATE users SET email_pending = ?, email_change_token = ?, email_change_expires = ? WHERE id = ?",
        [$newEmail, $token, $expires, $user['id']]
    );

    // Send confirmation email to the NEW address
    $sent = false;
    if (defined('SMTP_ENABLED') && SMTP_ENABLED) {
        $sent = Mailer::sendEmailChange($newEmail, $token);
    }

    echo json_encode([
        'ok'          => true,
        'email_sent'  => $sent,
        'smtp_enabled' => defined('SMTP_ENABLED') && SMTP_ENABLED,
    ]);
    exit;
}

// POST /api/settings/email/cancel — cancel pending change
if ($action === 'email' && $sub_action === 'cancel') {
    DB::run(
        "UPDATE users SET email_pending = NULL, email_change_token = NULL, email_change_expires = NULL WHERE id = ?",
        [$user['id']]
    );
    RateLimit::clear('settings_email', (string)$user['id']);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Unknown settings action.']);
