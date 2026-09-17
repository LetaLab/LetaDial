<?php
/**
 * LetaDial — Admin API (sesja 065 + 066 + 067 + 068 + 069 + SEC-105)
 *
 * GET  /api/admin/blocked          — list blocked rate_limit entries
 * POST /api/admin/unblock          — unblock one entry  {key_hash, action}
 * POST /api/admin/unblock-all      — unblock all for IP {key_plain}
 * GET  /api/admin/users            — list all users
 * POST /api/admin/delete-user      — delete user        {user_id}
 * GET  /api/admin/login-history    — recent history     [?ip=x.x.x.x] [?limit=N]
 * GET  /api/admin/install-check    — system check
 * POST /api/admin/export-blocked   — export             {format: json|csv} (SEC-138: was GET, no CSRF)
 *
 * sesja 066:
 * GET  /api/admin/sessions              — list all active sessions [?user_id=N]
 * POST /api/admin/sessions/delete       — delete one session  {session_id}
 * POST /api/admin/sessions/delete-user  — delete all for user {user_id}
 * POST /api/admin/force-password        — force reset password {user_id, password, admin_password}
 *
 * sesja 067:
 * POST /api/admin/invite                — invite user {email, login}
 *
 * sesja 068:
 * GET  /api/admin/registration          — get registration_enabled status
 * POST /api/admin/registration          — set registration_enabled {enabled: bool}
 *
 * sesja 069:
 * POST /api/admin/create-user           — create user directly {login, email, password, role, admin_password}
 *
 * All endpoints: admin role required.
 * All POST endpoints: CSRF required.
 *
 * SEC-105: force-password and create-user additionally require
 * `admin_password` — the CALLING admin's own current password, verified
 * via Password::verifyAndRehash() before the action runs. Same step-up
 * pattern updater_api.php already uses for git-pull (SEC-079): both actions are
 * at least as consequential (full account takeover, or minting an
 * immediately-active account with an attacker-chosen role) — a
 * stolen/hijacked admin session alone must not be enough to trigger them.
 *
 * SEC-113: unblock, unblock-all, delete-user, sessions/delete,
 * sessions/delete-user and the registration toggle previously had CSRF +
 * the admin role check but NO rate limit at all — every other mutating
 * action in this file already has one (force-password/invite/create-user
 * below, plus the SEC-095/SEC-101 pattern used across dial_api.php,
 * group_api.php, settings_api.php). Worst case with a stolen/hijacked
 * admin session: an unthrottled loop over delete-user could erase every
 * account in the instance in seconds, with nothing to slow it down beyond
 * the CSRF token the attacker already holds. All six now share one
 * 'admin_mutate' bucket (200/h/admin) — generous enough that no normal
 * admin workflow will ever notice it, tight enough to turn "instant mass
 * deletion" into "throttled, noticeable, and logged as repeated 429s."
 * delete-user additionally requires SEC-105's re-auth step as of
 * 24.08.2026 (see immediately below) — the two together mean a stolen
 * session alone can neither loop the action nor trigger it even once
 * without the account owner's own current password.
 *
 * SEC-113 extension (24.08.2026, per Andrzej): delete-user now ALSO
 * requires `admin_password`, identical to force-password/create-user
 * below. Deleting an account is irreversible and cascades every dial,
 * group, session, avatar and thumbnail that user owns (Admin::deleteUser())
 * — at least as consequential as those two actions, so it gets the same
 * step-up guarantee: a stolen/hijacked admin session alone is not enough,
 * the request must also carry the calling admin's own current password.
 *
 * SEC-138 (11.09.2026): export-blocked was GET with no CSRF — the one
 * remaining read endpoint in this file reachable without CSRF at all,
 * the exact same class of inconsistency SEC-102 already closed for the
 * regular, non-admin /api/export. Switched to POST + CSRF::require();
 * admin_page.php's CSV/JSON export buttons now go through fetch()+Blob
 * instead of window.location.href, mirroring app.js's doExport().
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

header('Content-Type: application/json; charset=UTF-8');

$user = Auth::getUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']); exit;
}
if ($user['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin only.']); exit;
}

$method     = $_SERVER['REQUEST_METHOD'];
$path       = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$parts      = array_values(array_filter(explode('/', trim($path, '/'))));
$action     = $parts[2] ?? null;
$sub_action = $parts[3] ?? null;

// ── GET /api/admin/blocked ────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'blocked') {
    $min = max(1, (int)($_GET['min'] ?? 3));
    echo json_encode(['ok' => true, 'entries' => Admin::getBlocked($min)]);
    exit;
}

// ── POST /api/admin/unblock ───────────────────────────────────────────────────
if ($method === 'POST' && $action === 'unblock') {
    CSRF::require();
    // SEC-113
    if (RateLimit::check('admin_mutate', (string)$user['id'], 200, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many requests. Try again later.']); exit;
    }
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $keyHash = trim($body['key_hash'] ?? '');
    $act     = trim($body['action']   ?? '');
    if (!$keyHash || !$act) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'key_hash and action required.']); exit;
    }
    echo json_encode(['ok' => Admin::unblock($keyHash, $act)]);
    exit;
}

// ── POST /api/admin/unblock-all ──────────────────────────────────────────────
if ($method === 'POST' && $action === 'unblock-all') {
    CSRF::require();
    // SEC-113
    if (RateLimit::check('admin_mutate', (string)$user['id'], 200, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many requests. Try again later.']); exit;
    }
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $keyPlain = trim($body['key_plain'] ?? '');
    if (!$keyPlain) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'key_plain required.']); exit;
    }
    echo json_encode(['ok' => true, 'deleted' => Admin::unblockByKey($keyPlain)]);
    exit;
}

// ── GET /api/admin/users ──────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'users') {
    echo json_encode(['ok' => true, 'users' => Admin::getUsers()]);
    exit;
}

// ── POST /api/admin/delete-user ───────────────────────────────────────────────
if ($method === 'POST' && $action === 'delete-user') {
    CSRF::require();
    // SEC-113
    if (RateLimit::check('admin_mutate', (string)$user['id'], 200, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many requests. Try again later.']); exit;
    }
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $userId = (int)($body['user_id'] ?? 0);
    if (!$userId) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'user_id required.']); exit;
    }

    // SEC-124: dedicated, tight bucket guarding the admin_password re-auth
    // check below, matching force-password's admin_force_pw (10/h) and
    // create-user's admin_create_user (20/h). Until now, delete-user's
    // re-auth relied only on the general-purpose admin_mutate bucket
    // above (200/h, shared across six unrelated actions: unblock,
    // unblock-all, delete-user, sessions/delete, sessions/delete-user,
    // registration toggle) to bound repeated wrong-password guesses
    // against a hijacked admin session - 20x looser than the other two
    // equally-sensitive re-auth checks, for an action (irreversible
    // account deletion) that is at least as consequential as either of
    // them. Checked unconditionally here, before the password itself is
    // even read, mirroring force-password's placement below.
    if (RateLimit::check('admin_delete_user', (string)$user['id'], 10, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many requests. Try again in an hour.']); exit;
    }

    // SEC-113 extension (24.08.2026, per Andrzej): re-auth, same pattern as
    // force-password/create-user below. Deleting an account is irreversible
    // and cascades every dial/group/session/avatar/thumbnail that user
    // owns — a stolen/hijacked admin session alone must not be enough to
    // trigger it.
    $adminPassword = $body['admin_password'] ?? '';
    $adminRow      = DB::row("SELECT password_hash FROM users WHERE id = ?", [$user['id']]);
    if ($adminPassword === '' || !$adminRow
        || !Password::verifyAndRehash($adminPassword, $adminRow['password_hash'], (int)$user['id'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Incorrect password. Re-enter your password to confirm this action.']); exit;
    }

    $result = Admin::deleteUser($userId, $user['id']);
    http_response_code($result['ok'] ? 200 : 422);
    echo json_encode($result);
    exit;
}


// ── GET /api/admin/login-history ──────────────────────────────────────────────
if ($method === 'GET' && $action === 'login-history') {
    $ip    = trim($_GET['ip']    ?? '') ?: null;
    $limit = max(10, min(500, (int)($_GET['limit'] ?? 100)));
    echo json_encode(['ok' => true, 'history' => Admin::getLoginHistory($ip, $limit)]);
    exit;
}

// ── GET /api/admin/install-check ─────────────────────────────────────────────
if ($method === 'GET' && $action === 'install-check') {
    echo json_encode(['ok' => true, 'checks' => Admin::installCheck()]);
    exit;
}

// ── POST /api/admin/export-blocked ───────────────────────────────────────────
// SEC-138 (SEC_AND_BUG_ANIH_PLAN.md, Czesc XV): was GET with no CSRF - the
// exact same class of problem SEC-102 already closed for the regular,
// non-admin /api/export (a GET-triggerable file download, reachable
// without CSRF, in a file that otherwise holds every other mutating admin
// action to a stricter standard - SEC-113/SEC-117/SEC-122/SEC-124).
// Practical risk was always low (no CORS anywhere in this app means the
// response body can't be read cross-origin), same reasoning SEC-102 itself
// gives - this closes the inconsistency regardless of how small that risk
// is. Client updated in admin_page.php to POST via fetch()+Blob instead of
// window.location.href, mirroring app.js's doExport() after SEC-102.
//
// SEC-144 (SEC_AND_BUG_ANIH_PLAN.md, Czesc XVI): the SEC-138 fix above
// added CSRF but no rate limit at all, unlike its non-admin sibling
// /api/export (SEC-133, 'export' bucket, 30/h) and unlike every other
// mutating admin action in this file (the shared 'admin_mutate' bucket,
// SEC-113). Admin::exportBlocked() runs
// "SELECT ... FROM rate_limits ORDER BY attempts DESC" with no LIMIT at
// all, unlike getBlocked() (used by the regular Blocked IPs tab), which
// at least filters by a minimum attempts threshold - a genuinely
// unbounded query with no rate limit behind it. Own dedicated bucket
// (not the shared admin_mutate) for direct parity with SEC-133's own
// 'export' bucket rather than folding it into an unrelated 200/h pool.
if ($method === 'POST' && $action === 'export-blocked') {
    CSRF::require();
    if (RateLimit::check('admin_export', (string)$user['id'], 30, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many export requests. Try again later.']); exit;
    }
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $format = in_array($body['format'] ?? '', ['json', 'csv'], true) ? $body['format'] : 'json';
    $data   = Admin::exportBlocked($format);
    $date   = date('Y-m-d');
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"letadial_blocked_{$date}.csv\"");
    } else {
        header('Content-Type: application/json; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"letadial_blocked_{$date}.json\"");
    }
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo $data;
    exit;
}

// ── sesja 066: Sessions ───────────────────────────────────────────────────────

if ($method === 'GET' && $action === 'sessions' && $sub_action === null) {
    $filterUserId = isset($_GET['user_id']) && ctype_digit($_GET['user_id'])
        ? (int)$_GET['user_id'] : null;
    echo json_encode(['ok' => true, 'sessions' => Admin::getSessions($filterUserId)]);
    exit;
}

if ($method === 'POST' && $action === 'sessions' && $sub_action === 'delete') {
    CSRF::require();
    // SEC-113
    if (RateLimit::check('admin_mutate', (string)$user['id'], 200, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many requests. Try again later.']); exit;
    }
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $sessionId = trim($body['session_id'] ?? '');
    if (!$sessionId) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'session_id required.']); exit;
    }
    if ($sessionId === Auth::getSessionId()) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Cannot delete your own current session.']); exit;
    }
    $ok = Admin::deleteSession($sessionId);
    echo json_encode(['ok' => $ok, 'error' => $ok ? null : 'Session not found.']);
    exit;
}

if ($method === 'POST' && $action === 'sessions' && $sub_action === 'delete-user') {
    CSRF::require();
    // SEC-113
    if (RateLimit::check('admin_mutate', (string)$user['id'], 200, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many requests. Try again later.']); exit;
    }
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $userId = (int)($body['user_id'] ?? 0);
    if (!$userId) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'user_id required.']); exit;
    }
    echo json_encode(['ok' => true, 'deleted' => Admin::deleteUserSessions($userId)]);
    exit;
}

if ($method === 'POST' && $action === 'force-password') {
    CSRF::require();
    if (RateLimit::check('admin_force_pw', (string)$user['id'], 10, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many requests. Try again in an hour.']); exit;
    }
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $targetId = (int)($body['user_id']  ?? 0);
    $password = $body['password'] ?? '';
    if (!$targetId || !$password) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'user_id and password required.']); exit;
    }

    // SEC-105: re-auth, same pattern as updater_api.php's git-pull (SEC-079).
    // A successful call here is a full takeover of ANY account in the
    // system (Admin::forcePasswordReset() only checks $targetId !==
    // $adminId, not the target's role) — at least as severe as git-pull's
    // "RCE if origin repo is ever compromised", which already requires
    // re-entering the admin's own password. A stolen/hijacked admin
    // session alone must not be enough to trigger it.
    $adminPassword = $body['admin_password'] ?? '';
    $adminRow      = DB::row("SELECT password_hash FROM users WHERE id = ?", [$user['id']]);
    if ($adminPassword === '' || !$adminRow
        || !Password::verifyAndRehash($adminPassword, $adminRow['password_hash'], (int)$user['id'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Incorrect password. Re-enter your password to confirm this action.']); exit;
    }

    $result = Admin::forcePasswordReset($targetId, $password, $user['id']);
    http_response_code($result['ok'] ? 200 : 422);
    echo json_encode($result);
    exit;
}

// ── sesja 067: Invite User ────────────────────────────────────────────────────

if ($method === 'POST' && $action === 'invite') {
    CSRF::require();

    if (RateLimit::check('admin_invite', (string)$user['id'], 10, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many invite requests. Try again in an hour.']); exit;
    }

    $body  = json_decode(file_get_contents('php://input'), true) ?? [];
    $email = trim($body['email'] ?? '');
    $login = trim($body['login'] ?? '');

    if (!$email || !$login) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Email and login are required.']); exit;
    }

    $result = Admin::inviteUser($email, $login, $user['id']);
    http_response_code($result['ok'] ? 201 : 422);
    echo json_encode($result);
    exit;
}

// ── sesja 068: Registration Toggle ───────────────────────────────────────────

if ($method === 'GET' && $action === 'registration') {
    echo json_encode([
        'ok'      => true,
        'enabled' => Admin::getRegistrationEnabled(),
    ]);
    exit;
}

if ($method === 'POST' && $action === 'registration') {
    CSRF::require();
    // SEC-113
    if (RateLimit::check('admin_mutate', (string)$user['id'], 200, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many requests. Try again later.']); exit;
    }
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    if (!array_key_exists('enabled', $body)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'enabled (bool) required.']); exit;
    }
    $enabled = (bool)$body['enabled'];
    $result  = Admin::setRegistrationEnabled($enabled);
    echo json_encode(['ok' => true, 'enabled' => $result]);
    exit;
}

// ── sesja 069: Direct User Creation ──────────────────────────────────────────

if ($method === 'POST' && $action === 'create-user') {
    CSRF::require();

    // Rate limit: 20 bezposrednich tworzen kont na admina na godzine
    if (RateLimit::check('admin_create_user', (string)$user['id'], 20, 3600, 3600)) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many user creation requests. Try again in an hour.']); exit;
    }

    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $login    = trim($body['login']    ?? '');
    $email    = trim($body['email']    ?? '');
    $password = $body['password']      ?? '';
    $role     = trim($body['role']     ?? 'user');

    if (!$login || !$email || !$password) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Login, email and password are required.']); exit;
    }

    // SEC-105: re-auth, same pattern as force-password above and
    // updater_api.php's git-pull (SEC-079). This endpoint creates an
    // IMMEDIATELY active account (email_verified = 1, no confirmation
    // email) with an attacker-chosen role — including 'admin' — in a
    // single request. A stolen/hijacked admin session alone must not be
    // enough to mint a persistent backdoor account.
    $adminPassword = $body['admin_password'] ?? '';
    $adminRow      = DB::row("SELECT password_hash FROM users WHERE id = ?", [$user['id']]);
    if ($adminPassword === '' || !$adminRow
        || !Password::verifyAndRehash($adminPassword, $adminRow['password_hash'], (int)$user['id'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Incorrect password. Re-enter your password to confirm this action.']); exit;
    }

    $result = Admin::createUser($login, $email, $password, $role, $user['id']);
    http_response_code($result['ok'] ? 201 : 422);
    echo json_encode($result);
    exit;
}

http_response_code(404);
echo json_encode(['ok' => false, 'error' => 'Unknown admin action.']);
