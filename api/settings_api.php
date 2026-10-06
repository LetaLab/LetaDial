<?php
/**
 * LetaDial - Settings API (sesja 058 + 066 + 071a + 071b + 072 + 079 + 080 + SEC-101)
 *
 * POST /api/settings/password      — change password (also revokes trusted devices, sesja 079)
 * POST /api/settings/backup-codes  — regenerate 2FA backup codes
 * GET  /api/settings/backup-count  — unused backup codes count
 * POST /api/settings/recent        — toggle Recent tab {disabled}
 * POST /api/settings/theme         — save theme to DB (sesja 071a)
 * POST /api/settings/primary-color — save custom primary color per theme (sesja 071b)
 * POST /api/settings/theme-extras  — save bg + text colors per theme (sesja 072)
 *
 * sesja 066:
 * GET  /api/settings/sessions           — list current user's active sessions
 * POST /api/settings/sessions/delete    — delete one session {session_id}
 * POST /api/settings/sessions/delete-all — delete all OTHER sessions (keep current)
 * POST /api/settings/email              — initiate email change {new_email}
 * POST /api/settings/email/cancel       — cancel pending email change
 *
 * sesja 079 (Trusted Device):
 * GET  /api/settings/trusted-devices        — list this user's trusted devices
 * POST /api/settings/trusted-devices/delete — revoke one device {id}
 * POST /api/settings/trusted-devices/delete-all — revoke every trusted device
 *
 * sesja 080 (GDPR personal data export):
 * POST /api/settings/data-export - download a JSON copy of the user's personal data
 *                                   {current_password}; see Export::buildPersonalData()
 *
 * SEC-101: password/backup-codes/email already had their own strict,
 * dedicated rate limits. Every OTHER mutating action below shares one
 * generous 'settings_mutate' bucket (500/h/user) — see original docblock
 * for full rationale, unchanged by sesja 079. Trusted-devices delete/
 * delete-all join that same shared bucket, same reasoning as sessions
 * delete/delete-all right next to them.
 *
 * sesja 080: 'data-export' hands out a full copy of the account's personal
 * data (e-mail, IP addresses, login history, avatar, every dial URL), so a
 * stolen session cookie alone must not be enough to trigger it. It uses the
 * same step-up pattern as the e-mail change: the current password is
 * verified first (Password::verifyAndRehash()). It has its own dedicated
 * rate limit bucket, 'settings_data_export' (5 requests/hour/user), checked
 * BEFORE the password so wrong-password guesses count against the same
 * budget as real exports, and it is deliberately NOT cleared after a
 * successful export: building the file is the expensive part, so the limit
 * must hold for successful requests as well.
 *
 * SEC-139 (11.09.2026): backup-codes' TOTP::decrypt() call is wrapped in
 * try/catch(RuntimeException).
 *
 * SEC-150 (SEC_AND_BUG_ANIH_PLAN.md, Czesc XVII): the 'email' action's
 * "already in use" branch shares one generic message and one floor-padded
 * response time with the "email change actually sent" branch.
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

if ($method === 'GET' && $action === 'sessions') {
    $currentSessionId = Auth::getSessionId();
    $sessions = DB::rows(
        "SELECT id, ip, user_agent, created_at, last_activity, expires_at, totp_verified
         FROM sessions
         WHERE user_id = ?
         ORDER BY last_activity DESC",
        [$user['id']]
    );
    foreach ($sessions as &$s) {
        $s['is_current'] = ($s['id'] === $currentSessionId);
    }
    unset($s);
    echo json_encode(['ok' => true, 'sessions' => $sessions, 'current_id' => $currentSessionId]);
    exit;
}

// ── GET /api/settings/trusted-devices (sesja 079) ─────────────────────────────
if ($method === 'GET' && $action === 'trusted-devices') {
    $devices = TrustedDevice::listForUser((int)$user['id']);
    $cookieRaw = $_COOKIE[TrustedDevice::COOKIE_NAME] ?? '';
    $thisSelector = $cookieRaw && str_contains($cookieRaw, ':') ? explode(':', $cookieRaw, 2)[0] : null;
    // Mark "this device" for the UI — selector is not a secret on its own
    // (it is not usable to authenticate without the matching verifier),
    // and it is already sitting in the request's own cookie header.
    foreach ($devices as &$d) {
        $d['is_this_device'] = false; // populated below only if selector matches
    }
    unset($d);
    if ($thisSelector) {
        $row = DB::row("SELECT id FROM trusted_devices WHERE selector = ? AND user_id = ?", [$thisSelector, $user['id']]);
        if ($row) {
            foreach ($devices as &$d) {
                if ((int)$d['id'] === (int)$row['id']) $d['is_this_device'] = true;
            }
            unset($d);
        }
    }
    echo json_encode(['ok' => true, 'devices' => $devices]);
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
    // Sesja 079: Auth::logoutAllSessions() now also revokes every trusted
    // device for this user — a device trusted to skip 2FA must not remain
    // trusted once the password behind it has just changed.
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
    $valid = false;
    try {
        $secret = TOTP::decrypt($user['totp_secret']);
        $valid  = TOTP::verifyAndConsume($secret, $code, $user['id']);
    } catch (RuntimeException $e) {
        error_log('[Settings] backup-codes TOTP::decrypt failed for user ' . $user['id'] . ': ' . $e->getMessage());
    }
    if (!$valid) {
        $valid = TOTP::useBackupCode($user['id'], $code);
    }
    if (!$valid) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid 2FA code. Try again.']); exit;
    }
    DB::run("DELETE FROM totp_backup_codes WHERE user_id = ?", [$user['id']]);
    $new_codes = [];
    $stmt = DB::get()->prepare("INSERT INTO totp_backup_codes (user_id, code_hash) VALUES (?, ?)");
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
    if (RateLimit::check('settings_mutate', (string)$user['id'], 500, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many requests. Try again later.']); exit;
    }
    $disabled = (bool)($body['disabled'] ?? false);
    DB::run("UPDATE users SET recent_disabled = ? WHERE id = ?", [(int)$disabled, $user['id']]);
    echo json_encode(['ok' => true, 'disabled' => $disabled]);
    exit;
}

// ── sesja 066: Sessions ───────────────────────────────────────────────────────
if ($action === 'sessions' && $sub_action === 'delete') {
    if (RateLimit::check('settings_mutate', (string)$user['id'], 500, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many requests. Try again later.']); exit;
    }
    $sessionId = trim($body['session_id'] ?? '');
    if (!$sessionId) { http_response_code(422); echo json_encode(['ok' => false, 'error' => 'session_id required.']); exit; }
    $sess = DB::row("SELECT id FROM sessions WHERE id = ? AND user_id = ?", [$sessionId, $user['id']]);
    if (!$sess) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'Session not found.']); exit; }
    if ($sessionId === Auth::getSessionId()) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Cannot delete the current session. Use Sign out instead.']); exit;
    }
    DB::run("DELETE FROM sessions WHERE id = ? AND user_id = ?", [$sessionId, $user['id']]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'sessions' && $sub_action === 'delete-all') {
    if (RateLimit::check('settings_mutate', (string)$user['id'], 500, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many requests. Try again later.']); exit;
    }
    $currentId = Auth::getSessionId();
    $count = DB::run("DELETE FROM sessions WHERE user_id = ? AND id != ?", [$user['id'], $currentId]);
    echo json_encode(['ok' => true, 'deleted' => $count]);
    exit;
}

// ── sesja 079: Trusted Devices ────────────────────────────────────────────────
if ($action === 'trusted-devices' && $sub_action === 'delete') {
    if (RateLimit::check('settings_mutate', (string)$user['id'], 500, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many requests. Try again later.']); exit;
    }
    $id = (int)($body['id'] ?? 0);
    if (!$id) { http_response_code(422); echo json_encode(['ok' => false, 'error' => 'id required.']); exit; }
    $ok = TrustedDevice::delete($id, (int)$user['id']);
    if (!$ok) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'Device not found.']); exit; }
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'trusted-devices' && $sub_action === 'delete-all') {
    if (RateLimit::check('settings_mutate', (string)$user['id'], 500, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many requests. Try again later.']); exit;
    }
    $count = TrustedDevice::deleteAllForUser((int)$user['id']);
    echo json_encode(['ok' => true, 'deleted' => $count]);
    exit;
}

// ── sesja 080: Personal data export (GDPR) ────────────────────────────────────
if ($action === 'data-export' && $sub_action === null) {
    if (RateLimit::check('settings_data_export', (string)$user['id'], 5, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many export requests. Try again in an hour.']); exit;
    }

    $currentPassword = $body['current_password'] ?? '';
    if (!is_string($currentPassword) || $currentPassword === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Please enter your current password to confirm this export.']); exit;
    }
    $authRow = DB::row("SELECT password_hash FROM users WHERE id = ?", [$user['id']]);
    if (!$authRow || !Password::verifyAndRehash($currentPassword, $authRow['password_hash'], (int)$user['id'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Current password is incorrect.']); exit;
    }

    // Export::downloadPersonalData() does all queries, file reads and JSON
    // encoding before it sends its first header, so on failure the response
    // is still untouched and a clean JSON error can be returned here.
    try {
        Export::downloadPersonalData((int)$user['id'], Auth::getSessionId());
    } catch (Throwable $e) {
        error_log('[Settings] data-export failed for user ' . $user['id'] . ': ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not build the export. Try again, or contact your administrator if it keeps failing.']);
    }
    exit;
}

function _settings_equalize_email_timing(float $t0): void
{
    $target  = 1.2;
    $elapsed = microtime(true) - $t0;
    if ($elapsed < $target) {
        usleep((int)(($target - $elapsed) * 1_000_000));
    }
}

// ── sesja 066: Email Change ───────────────────────────────────────────────────
if ($action === 'email' && $sub_action === null) {
    if (RateLimit::check('settings_email', (string)$user['id'], 3, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many requests. Try again in an hour.']); exit;
    }

    $currentPassword = $body['current_password'] ?? '';
    if ($currentPassword === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Please enter your current password to confirm this change.']); exit;
    }
    $authRow = DB::row("SELECT password_hash FROM users WHERE id = ?", [$user['id']]);
    if (!$authRow || !Password::verifyAndRehash($currentPassword, $authRow['password_hash'], (int)$user['id'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Current password is incorrect.']); exit;
    }

    $newEmail = strtolower(trim($body['new_email'] ?? ''));
    if (!$newEmail) { http_response_code(422); echo json_encode(['ok' => false, 'error' => 'New email address is required.']); exit; }
    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) { http_response_code(422); echo json_encode(['ok' => false, 'error' => 'Invalid email address format.']); exit; }
    if ($newEmail === strtolower($user['email'])) { http_response_code(422); echo json_encode(['ok' => false, 'error' => 'This is already your current email address.']); exit; }

    $_email_t0 = microtime(true);

    $taken = DB::val("SELECT id FROM users WHERE (email = ? OR email_pending = ?) AND id != ?", [$newEmail, $newEmail, $user['id']]);
    if ($taken) {
        _settings_equalize_email_timing($_email_t0);
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Could not update your email address with these details.']); exit;
    }

    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 3600);
    DB::run("UPDATE users SET email_pending = ?, email_change_token = ?, email_change_expires = ? WHERE id = ?", [$newEmail, $token, $expires, $user['id']]);
    $sent = false;
    if (defined('SMTP_ENABLED') && SMTP_ENABLED) { $sent = Mailer::sendEmailChange($newEmail, $token); }
    _settings_equalize_email_timing($_email_t0);
    echo json_encode(['ok' => true, 'email_sent' => $sent, 'smtp_enabled' => defined('SMTP_ENABLED') && SMTP_ENABLED]);
    exit;
}

if ($action === 'email' && $sub_action === 'cancel') {
    if (RateLimit::check('settings_mutate', (string)$user['id'], 500, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many requests. Try again later.']); exit;
    }
    DB::run("UPDATE users SET email_pending = NULL, email_change_token = NULL, email_change_expires = NULL WHERE id = ?", [$user['id']]);
    RateLimit::clear('settings_email', (string)$user['id']);
    echo json_encode(['ok' => true]);
    exit;
}

// ── POST /api/settings/theme (sesja 071a) ─────────────────────────────────────
if ($action === 'theme') {
    if (RateLimit::check('settings_mutate', (string)$user['id'], 500, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many requests. Try again later.']); exit;
    }
    $allowed = ['light', 'dark', 'midnight'];
    $theme   = trim($body['theme'] ?? '');
    if (!in_array($theme, $allowed, true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid theme. Use: light, dark, midnight.']); exit;
    }
    DB::run("UPDATE users SET theme = ? WHERE id = ?", [$theme, $user['id']]);
    echo json_encode(['ok' => true, 'theme' => $theme]);
    exit;
}

// ── POST /api/settings/primary-color (sesja 071b) ────────────────────────────
if ($action === 'primary-color') {
    if (RateLimit::check('settings_mutate', (string)$user['id'], 500, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many requests. Try again later.']); exit;
    }
    $allowedThemes = ['light', 'dark', 'midnight'];
    $theme = trim($body['theme'] ?? '');
    if (!in_array($theme, $allowedThemes, true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid theme. Use: light, dark, midnight.']); exit;
    }
    $rawColor = $body['color'] ?? null;
    if ($rawColor === null || $rawColor === '' || $rawColor === 'null') {
        $color = null;
    } else {
        $color = trim((string)$rawColor);
        if (strlen($color) === 6 && ctype_xdigit($color)) $color = '#' . $color;
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Invalid color format. Use #RRGGBB (7 characters).']); exit;
        }
        $color = strtolower($color);
    }
    $col = 'theme_' . $theme . '_primary';
    DB::run("UPDATE users SET {$col} = ? WHERE id = ?", [$color, $user['id']]);
    echo json_encode(['ok' => true, 'theme' => $theme, 'color' => $color]);
    exit;
}

// ── POST /api/settings/theme-extras — bg + text colors (sesja 072) ───────────
if ($action === 'theme-extras') {
    if (RateLimit::check('settings_mutate', (string)$user['id'], 500, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many requests. Try again later.']); exit;
    }
    $allowedThemes = ['light', 'dark', 'midnight'];
    $theme = trim($body['theme'] ?? '');
    if (!in_array($theme, $allowedThemes, true)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid theme. Use: light, dark, midnight.']); exit;
    }

    $parseColor = function (mixed $raw): string|null|false {
        if ($raw === null || $raw === '' || $raw === 'null') return null;
        $c = trim((string)$raw);
        if (strlen($c) === 6 && ctype_xdigit($c)) $c = '#' . $c;
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $c)) return false;
        return strtolower($c);
    };

    $bg   = $parseColor($body['bg']   ?? null);
    $text = $parseColor($body['text'] ?? null);

    if ($bg === false || $text === false) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid color format. Use #RRGGBB.']); exit;
    }

    $json = ($bg !== null || $text !== null)
        ? json_encode(['bg' => $bg, 'text' => $text])
        : null;

    $col = 'theme_' . $theme . '_extra';
    DB::run("UPDATE users SET {$col} = ? WHERE id = ?", [$json, $user['id']]);
    echo json_encode(['ok' => true, 'theme' => $theme, 'bg' => $bg, 'text' => $text]);
    exit;
}

// ── POST /api/settings/dial-width — dial card width (sesja 074) ───────────────
if ($action === 'dial-width') {
    if (RateLimit::check('settings_mutate', (string)$user['id'], 500, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many requests. Try again later.']); exit;
    }
    $width = max(120, min(280, (int)($body['width'] ?? 175)));
    DB::run("UPDATE users SET dial_width = ? WHERE id = ?", [$width, $user['id']]);
    echo json_encode(['ok' => true, 'width' => $width]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Unknown settings action.']);
