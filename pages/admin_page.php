<?php
/**
 * LetaDial — Admin Panel (sesja 065 + 066 + 067 + 068 + 069 + 074 + 078 + SEC-079 + SEC-105)
 *
 * Tabs:
 *   1. Blocked IPs    — rate_limits; unblock / export
 *   2. Users          — accounts; delete; force-reset password (step-up auth, SEC-105); invite (067); registration toggle (068); avatars (078)
 *   3. Sessions       — all active sessions; delete single / all for user
 *   4. Login History  — recent auth attempts; filter by IP
 *   5. Update         — git check vs github.com/LetaLab/LetaDial + git pull (password re-auth required, SEC-079)
 *   6. Install Check  — full health check
 *
 * sesja 078: avatar thumbnails shown next to login in the Users table, and the
 * logged-in admin's own avatar shown in the topbar instead of the 👤 emoji.
 * SEC-079: "Update now" requires re-entering the current password (step-up
 * auth) before /api/update/git-pull is called — a stolen session cookie
 * alone is no longer enough to trigger a git pull. fix_permissions.sh
 * references removed (file no longer exists — see README → Permissions).
 * SEC-105: the same step-up pattern now also guards Force Reset Password
 * and Create User — both are at least as consequential as git-pull (full
 * account takeover, or minting an immediately-active admin account). The
 * reauth modal + promptReauth() introduced by SEC-079 is now generalized
 * (accepts a per-caller message) and shared by all three flows instead of
 * being git-pull-specific.
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die();

// VI.3: no-store — this page embeds EVERY user, every active session (IP +
// user agent) and the full login history directly in inline JSON. Without
// this, a browser's back/forward cache (bfcache) can restore the fully
// rendered admin panel after sign-out on a shared/public machine, without
// any new request the server could block on. Sent as early as possible,
// before any other header or output.
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');

$user = Auth::requireAdmin();

$app_name   = htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8');
$user_login = htmlspecialchars($user['login'], ENT_QUOTES, 'UTF-8');
$csrf_token = CSRF::token();
$icon_url   = htmlspecialchars(APP_URL . '/assets/icons/icon-192.png', ENT_QUOTES, 'UTF-8');
$has_avatar = !empty($user['avatar_path']);   // sesja 078

$blocked       = Admin::getBlocked(3);
$users_list    = Admin::getUsers();
$sessions_list = Admin::getSessions();
$login_history = Admin::getLoginHistory(null, 100);
$install_check = Admin::installCheck();

$blocked_json  = json_encode($blocked,       JSON_HEX_TAG | JSON_HEX_QUOT);
$users_json    = json_encode($users_list,    JSON_HEX_TAG | JSON_HEX_QUOT);
$sessions_json = json_encode($sessions_list, JSON_HEX_TAG | JSON_HEX_QUOT);
$history_json  = json_encode($login_history, JSON_HEX_TAG | JSON_HEX_QUOT);
$checks_json   = json_encode($install_check, JSON_HEX_TAG | JSON_HEX_QUOT);

$checks_fail   = count(array_filter($install_check, fn($c) => $c['required'] && !$c['ok']));
$checks_warn   = count(array_filter($install_check, fn($c) => !$c['required'] && !$c['ok']));
$smtp_enabled  = defined('SMTP_ENABLED') && SMTP_ENABLED;
$my_session_id = Auth::getSessionId();
$pw_rules      = Password::jsRules();

$login_rl_max = 10;
$login_rl_win = 300;

// sesja 068: registration toggle
$registration_enabled = Admin::getRegistrationEnabled();
$registration_json    = $registration_enabled ? 'true' : 'false';

// ── sesja 074: Custom Colors per-theme (mirror of dashboard_page.php) ────────
$_valid_hex = '/^#[0-9A-Fa-f]{6}$/i';
$custom_colors = [
    'light'    => (preg_match($_valid_hex, $user['theme_light_primary']    ?? '') ? strtolower($user['theme_light_primary'])    : null),
    'dark'     => (preg_match($_valid_hex, $user['theme_dark_primary']     ?? '') ? strtolower($user['theme_dark_primary'])     : null),
    'midnight' => (preg_match($_valid_hex, $user['theme_midnight_primary'] ?? '') ? strtolower($user['theme_midnight_primary']) : null),
];
$custom_extras = [];
foreach (['light', 'dark', 'midnight'] as $_ctk) {
    $raw = $user['theme_' . $_ctk . '_extra'] ?? null;
    $custom_extras[$_ctk] = ($raw && is_string($raw)) ? json_decode($raw, true) : null;
}

// PHP color helpers — _adm_ prefix avoids conflicts with dashboard_page.php helpers
function _adm_hexToRgb(string $hex): array {
    return [hexdec(substr($hex,1,2)), hexdec(substr($hex,3,2)), hexdec(substr($hex,5,2))];
}
function _adm_darkenHex(string $hex, float $amt): string {
    [$r,$g,$b] = _adm_hexToRgb($hex);
    return sprintf('#%02x%02x%02x',
        max(0,min(255,(int)round($r*(1-$amt)))),
        max(0,min(255,(int)round($g*(1-$amt)))),
        max(0,min(255,(int)round($b*(1-$amt))))
    );
}
function _adm_lightenHex(string $hex, float $amt): string {
    [$r,$g,$b] = _adm_hexToRgb($hex);
    return sprintf('#%02x%02x%02x',
        min(255,(int)round($r + (255-$r)*$amt)),
        min(255,(int)round($g + (255-$g)*$amt)),
        min(255,(int)round($b + (255-$b)*$amt))
    );
}
function _adm_luminance(string $hex): float {
    [$r,$g,$b] = _adm_hexToRgb($hex);
    return (0.299*$r + 0.587*$g + 0.114*$b) / 255;
}
function _adm_contrastFg(string $hex): string {
    [$r,$g,$b] = _adm_hexToRgb($hex);
    return ((0.299*$r + 0.587*$g + 0.114*$b) / 255) > 0.55 ? '#000000' : '#ffffff';
}
function _adm_toRgba(string $hex, float $a): string {
    [$r,$g,$b] = _adm_hexToRgb($hex);
    return "rgba({$r},{$g},{$b},{$a})";
}

$_inline_css = [];
foreach ($custom_colors as $_ctk => $_cth) {
    if ($_cth) {
        $_inline_css[] = "[data-theme=\"{$_ctk}\"]{"
            . "--primary:{$_cth};"
            . "--primary-h:"     . _adm_darkenHex($_cth, 0.15) . ";"
            . "--primary-hover:" . _adm_darkenHex($_cth, 0.12) . ";"
            . "--primary-fg:"    . _adm_contrastFg($_cth) . ";"
            . "--primary-bg:"    . _adm_toRgba($_cth, 0.10) . ";"
            . "--primary-bdr:"   . _adm_toRgba($_cth, 0.30) . ";"
            . "--border-focus:{$_cth};"
            . "--info:{$_cth};"
            . "}";
    }
}
foreach ($custom_extras as $_ctk => $_extra) {
    if (!is_array($_extra)) continue;
    $_css = '';
    $_bg  = $_extra['bg']   ?? null;
    $_tx  = $_extra['text'] ?? null;
    if ($_bg && preg_match($_valid_hex, $_bg)) {
        $_lum  = _adm_luminance($_bg);
        $_css .= "--bg:{$_bg};";
        if ($_lum > 0.5) {
            $_css .= "--surface:"       . _adm_lightenHex($_bg, 0.55) . ";";
            $_css .= "--surface-alt:"   . _adm_darkenHex($_bg, 0.04)  . ";";
            $_css .= "--surface-hover:" . _adm_darkenHex($_bg, 0.07)  . ";";
            $_css .= "--border:"        . _adm_darkenHex($_bg, 0.14)  . ";";
            $_css .= "--border-light:"  . _adm_darkenHex($_bg, 0.08)  . ";";
        } else {
            $_css .= "--surface:"       . _adm_lightenHex($_bg, 0.08) . ";";
            $_css .= "--surface-alt:"   . _adm_lightenHex($_bg, 0.15) . ";";
            $_css .= "--surface-hover:" . _adm_lightenHex($_bg, 0.11) . ";";
            $_css .= "--border:"        . _adm_lightenHex($_bg, 0.24) . ";";
            $_css .= "--border-light:"  . _adm_lightenHex($_bg, 0.17) . ";";
        }
    }
    if ($_tx && preg_match($_valid_hex, $_tx)) {
        [$_r,$_g,$_b] = _adm_hexToRgb($_tx);
        $_css .= "--text:{$_tx};";
        $_css .= "--text-muted:rgba({$_r},{$_g},{$_b},0.65);";
        $_css .= "--text-faint:rgba({$_r},{$_g},{$_b},0.40);";
    }
    if ($_css) {
        $_inline_css[] = "[data-theme=\"{$_ctk}\"]{{$_css}}";
    }
}
$custom_colors_json = json_encode($custom_colors, JSON_HEX_TAG);
$custom_extras_json = json_encode($custom_extras, JSON_HEX_TAG);
// ─────────────────────────────────────────────────────────────────────────────

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Admin — <?= $app_name ?></title>
<link rel="shortcut icon" href="/assets/icons/favicon.png" type="image/png">
<link rel="icon" href="/assets/icons/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
<link rel="manifest" href="/assets/manifest.json">
<link rel="stylesheet" href="/assets/css/design-system.css">
<?php if ($_inline_css): ?>
<style nonce="<?= CSP::nonce() ?>"><?= implode('', $_inline_css) ?></style>
<?php endif; ?>
<script nonce="<?= CSP::nonce() ?>">(function(){const t=localStorage.getItem('dv-theme');if(t)document.documentElement.setAttribute('data-theme',t)})();</script>
<link rel="stylesheet" href="/assets/css/pages/admin.css">
</head>
<body>

<header class="admin-topbar">
    <a href="/" class="admin-brand">
        <img src="<?= $icon_url ?>" alt="<?= $app_name ?>">
        <?= $app_name ?>
    </a>
    <span class="admin-badge">ADMIN</span>
    <div class="admin-topbar-right">
        <span style="color:var(--text-muted);font-size:.875rem">
            <?php if ($has_avatar): ?>
            <img src="/api/avatars/<?= (int)$user['id'] ?>" alt="" class="admin-topbar-avatar-img" id="admin-topbar-avatar-img">
            <?php else: ?>
            👤
            <?php endif; ?>
            <?= $user_login ?>
        </span>
        <button class="theme-toggle" id="theme-btn">🌙 Dark</button>
        <a href="/settings" class="back-link">Settings</a>
        <a href="/" class="back-link">← Dashboard</a>
    </div>
</header>

<main class="admin-main">
    <div class="admin-title">⚙ Admin Panel</div>

    <nav class="admin-tabs">
        <button class="admin-tab active" data-tab="blocked">
            🚫 Blocked IPs
            <?php if (count($blocked) > 0): ?>
            <span class="tab-badge tb-error"><?= count($blocked) ?></span>
            <?php endif; ?>
        </button>
        <button class="admin-tab" data-tab="users">
            👥 Users
            <span class="tab-badge tb-info"><?= count($users_list) ?></span>
        </button>
        <button class="admin-tab" data-tab="sessions">
            🖥️ Sessions
            <span class="tab-badge tb-info"><?= count($sessions_list) ?></span>
        </button>
        <button class="admin-tab" data-tab="history">📋 Login History</button>
        <button class="admin-tab" data-tab="update">🔄 Update</button>
        <button class="admin-tab" data-tab="check">
            🔍 Install Check
            <?php if ($checks_fail > 0): ?>
            <span class="tab-badge tb-error"><?= $checks_fail ?> fail</span>
            <?php elseif ($checks_warn > 0): ?>
            <span class="tab-badge tb-warn"><?= $checks_warn ?> warn</span>
            <?php else: ?>
            <span class="tab-badge tb-ok">✓ ok</span>
            <?php endif; ?>
        </button>
    </nav>

    <!-- ═══ TAB 1: BLOCKED IPs ═══ -->
    <div class="tab-pane active" id="tab-blocked">
        <div class="admin-card" style="margin-bottom:1rem;background:var(--info-bg);border-color:var(--info-bdr)">
            <div class="admin-card-body" style="padding:.75rem 1.25rem;font-size:.82rem;color:var(--info)">
                ℹ Login rate limit: <strong><?= $login_rl_max ?> failed attempts</strong> within
                <strong><?= $login_rl_win ?>s</strong> triggers a block.
            </div>
        </div>
        <div class="panel-toolbar">
            <span style="font-size:.875rem;color:var(--text-muted)">
                Entries with <strong id="blocked-min-label">≥ 3</strong> attempts
            </span>
            <div class="panel-toolbar-right">
                <button class="btn btn-ghost btn-sm" id="btn-refresh-blocked">↻ Refresh</button>
                <button class="btn btn-ghost btn-sm" id="btn-export-csv">↓ CSV</button>
                <button class="btn btn-ghost btn-sm" id="btn-export-json">↓ JSON</button>
                <button class="btn btn-danger btn-sm" id="btn-unblock-all-global">🗑 Unblock all</button>
            </div>
        </div>
        <div class="filter-bar">
            <input type="number" id="blocked-min-input" class="filter-input" value="3" min="1" max="999" style="width:90px">
            <label style="font-size:.875rem;color:var(--text-muted);align-self:center">min attempts</label>
            <input type="text" id="blocked-filter" class="filter-input" placeholder="Filter by IP / action…" style="min-width:200px">
        </div>
        <div class="data-table-wrap">
            <table class="data-table">
                <thead><tr>
                    <th>Action</th><th>Key (IP / User)</th><th>Attempts</th>
                    <th>Since</th><th>Last Attempt</th><th class="ua">User Agent</th>
                    <th>History</th><th>Actions</th>
                </tr></thead>
                <tbody id="blocked-tbody"><tr><td colspan="8" class="table-empty">Loading…</td></tr></tbody>
            </table>
        </div>
    </div>

    <!-- ═══ TAB 2: USERS ═══ -->
    <div class="tab-pane" id="tab-users">
        <!-- Registration toggle card (sesja 068) -->
        <div class="admin-card" style="margin-bottom:1rem">
            <div class="admin-card-body" style="padding:.85rem 1.25rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
                <div style="flex:1;min-width:200px">
                    <div style="font-size:.9rem;font-weight:600;color:var(--text);margin-bottom:.2rem">
                        👤 Self-Registration
                    </div>
                    <div style="font-size:.78rem;color:var(--text-muted)" id="reg-status-desc">
                        <?= $registration_enabled
                            ? 'Open — anyone can create an account from the login page.'
                            : 'Disabled — only admin invites work (invite always works regardless).' ?>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:.75rem;flex-shrink:0">
                    <span id="reg-status-badge" class="status-badge <?= $registration_enabled ? 'on' : 'off' ?>">
                        <?= $registration_enabled ? '✓ Open' : '✗ Disabled' ?>
                    </span>
                    <button type="button" class="btn btn-ghost btn-sm" id="btn-toggle-registration"
                            style="<?= $registration_enabled ? 'border-color:var(--error-bdr);color:var(--error)' : 'border-color:var(--success-bdr);color:var(--success)' ?>">
                        <?= $registration_enabled ? '🔒 Disable registration' : '🔓 Enable registration' ?>
                    </button>
                </div>
            </div>
        </div>

        <div class="panel-toolbar">
            <span style="font-size:.875rem;color:var(--text-muted)">
                <strong id="users-count"><?= count($users_list) ?></strong> user(s)
            </span>
            <div class="panel-toolbar-right">
                <input type="text" id="users-filter" class="filter-input" placeholder="Filter by login / email…" style="min-width:200px">
                <button class="btn btn-ghost btn-sm" id="btn-refresh-users">↻ Refresh</button>
                <button class="btn btn-ghost btn-sm" id="btn-create-user">➕ Create user</button>
                <button class="btn btn-primary btn-sm" id="btn-invite-user">✉ Invite user</button>
            </div>
        </div>
        <?php if (!$smtp_enabled): ?>
        <div class="alert alert-warning" style="margin-bottom:1rem">
            <span class="alert-icon">⚠</span>
            <span>SMTP is not configured — invite emails cannot be sent. Configure SMTP in <code>config.php</code> to enable user invites.</span>
        </div>
        <?php endif; ?>
        <div class="data-table-wrap">
            <table class="data-table">
                <thead><tr>
                    <th>Login</th><th>Email</th><th>Role</th><th>2FA</th><th>Verified</th>
                    <th>Groups</th><th>Dials</th><th>Sessions</th>
                    <th>Last Login</th><th>Created</th><th>Actions</th>
                </tr></thead>
                <tbody id="users-tbody"><tr><td colspan="11" class="table-empty">Loading…</td></tr></tbody>
            </table>
        </div>
    </div>

    <!-- ═══ TAB 3: SESSIONS ═══ -->
    <div class="tab-pane" id="tab-sessions">
        <div class="panel-toolbar">
            <span style="font-size:.875rem;color:var(--text-muted)">
                <strong id="sessions-count"><?= count($sessions_list) ?></strong> active session(s)
            </span>
            <div class="panel-toolbar-right">
                <input type="text" id="sessions-filter" class="filter-input" placeholder="Filter by user / IP…" style="min-width:200px">
                <button class="btn btn-ghost btn-sm" id="btn-refresh-sessions">↻ Refresh</button>
            </div>
        </div>
        <div class="data-table-wrap">
            <table class="data-table">
                <thead><tr>
                    <th>User</th><th>Role</th><th>IP</th>
                    <th>Browser / OS</th><th>Last Active</th>
                    <th>Signed In</th><th>2FA</th><th>Actions</th>
                </tr></thead>
                <tbody id="sessions-tbody"><tr><td colspan="8" class="table-empty">Loading…</td></tr></tbody>
            </table>
        </div>
    </div>

    <!-- ═══ TAB 4: LOGIN HISTORY ═══ -->
    <div class="tab-pane" id="tab-history">
        <div class="panel-toolbar">
            <span style="font-size:.875rem;color:var(--text-muted)" id="history-count-label">Last 100 entries</span>
            <div class="panel-toolbar-right">
                <input type="text" id="history-ip-filter" class="filter-input" placeholder="Filter by IP…" style="width:160px">
                <select id="history-status-filter" class="filter-input">
                    <option value="">All statuses</option>
                    <option value="success">success</option>
                    <option value="fail_password">fail_password</option>
                    <option value="fail_2fa">fail_2fa</option>
                    <option value="fail_locked">fail_locked</option>
                    <option value="fail_token">fail_token</option>
                </select>
                <button class="btn btn-ghost btn-sm" id="btn-refresh-history">↻ Refresh</button>
            </div>
        </div>
        <div class="data-table-wrap">
            <table class="data-table">
                <thead><tr>
                    <th>Time</th><th>User</th><th>Login Attempt</th>
                    <th>IP</th><th>Status</th><th class="ua">User Agent</th>
                </tr></thead>
                <tbody id="history-tbody"><tr><td colspan="6" class="table-empty">Loading…</td></tr></tbody>
            </table>
        </div>
    </div>

    <!-- ═══ TAB 5: UPDATE ═══ -->
    <div class="tab-pane" id="tab-update">
        <div class="admin-card">
            <div class="admin-card-header">
                <h3>🔄 LetaDial Update</h3>
                <span style="font-size:.78rem;color:var(--text-faint)">
                    Checks: <a href="https://github.com/LetaLab/LetaDial" target="_blank" rel="noopener" style="color:var(--text-faint)">github.com/LetaLab/LetaDial</a>
                </span>
            </div>
            <div class="admin-card-body">
                <div id="update-idle" class="update-state">
                    <div class="update-icon">🔍</div>
                    <div class="update-title">Check for updates</div>
                    <div class="update-sub">Compares your local commit with the public GitHub repository.<br>No changes are made during the check.</div>
                    <button class="btn btn-primary" id="btn-check-update" style="min-width:180px">Check for updates</button>
                </div>
                <div id="update-checking" class="update-state" style="display:none">
                    <div class="spinner"></div>
                    <div class="update-sub">Comparing with github.com/LetaLab/LetaDial…</div>
                </div>
                <div id="update-current" class="update-state" style="display:none">
                    <div class="update-icon">✅</div>
                    <div class="update-title">You're up to date</div>
                    <div class="update-sha" id="update-current-sha"></div>
                    <button class="btn btn-ghost" id="btn-recheck-1" style="min-width:160px">↻ Check again</button>
                </div>
                <div id="update-available" class="update-state" style="display:none">
                    <div class="update-icon">🆕</div>
                    <div class="update-title">Update available!</div>
                    <div class="update-sub" id="update-available-sub"></div>
                    <div class="update-sha" id="update-available-sha"></div>
                    <div style="margin-bottom:.75rem;font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted)">What's new</div>
                    <div class="commit-list" id="update-commit-list"></div>
                    <div style="display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap">
                        <button class="btn btn-primary" id="btn-update-now" style="min-width:180px">⬆ Update now</button>
                        <button class="btn btn-ghost"   id="btn-recheck-2"  style="min-width:140px">↻ Re-check</button>
                    </div>
                </div>
                <div id="update-running" class="update-state" style="display:none">
                    <div class="spinner"></div>
                    <div class="update-title" style="font-size:1rem">Updating…</div>
                    <div class="update-sub">Running git pull origin main<br><strong>Do not close this page.</strong></div>
                </div>
                <div id="update-done" class="update-state" style="display:none">
                    <div class="update-icon" id="update-done-icon">✅</div>
                    <div class="update-title" id="update-done-title">Update complete!</div>
                    <div class="update-sub"  id="update-done-sub">Reload the page to use the new version.</div>
                    <div style="text-align:left;max-width:680px;margin:0 auto">
                        <div style="font-size:.75rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin:.75rem 0 .35rem">git pull output</div>
                        <div class="output-box" id="update-pull-output"></div>
                    </div>
                    <div style="font-size:.75rem;color:var(--text-faint);max-width:680px;margin:.5rem auto 0">
                        File permissions are maintained independently by a system cron
                        (<code>LetaDial_Permissions.sh</code>) — not part of this update.
                    </div>
                    <div style="display:flex;gap:.75rem;justify-content:center;margin-top:1rem;flex-wrap:wrap">
                        <button class="btn btn-primary" id="btn-reload-page">↻ Reload page</button>
                        <button class="btn btn-ghost" id="btn-recheck-3">Check again</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ TAB 6: INSTALL CHECK ═══ -->
    <div class="tab-pane" id="tab-check">
        <div class="panel-toolbar">
            <div id="check-summary-bar" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:center;font-size:.875rem"></div>
            <div class="panel-toolbar-right">
                <button class="btn btn-ghost btn-sm" id="btn-refresh-check">↻ Re-run checks</button>
            </div>
        </div>
        <div id="check-container"></div>
    </div>
</main>

<!-- Confirm dialog -->
<div class="confirm-overlay" id="confirm-overlay">
    <div class="confirm-box">
        <h3 id="confirm-title">Confirm</h3>
        <p id="confirm-msg"></p>
        <div class="confirm-actions">
            <button class="btn btn-ghost" id="confirm-cancel">Cancel</button>
            <button class="btn btn-danger" id="confirm-ok">OK</button>
        </div>
    </div>
</div>

<!-- Force Reset Password Modal -->
<div class="confirm-overlay" id="pw-reset-overlay">
    <div class="confirm-box" style="max-width:440px">
        <h3>🔑 Force Reset Password</h3>
        <p style="margin-bottom:.75rem">Setting a new password for: <strong id="pw-reset-login"></strong><br>
        All sessions for this user will be invalidated.</p>
        <input type="hidden" id="pw-reset-user-id">
        <div style="margin-bottom:.75rem">
            <label style="font-size:.78rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:.35rem">New Password</label>
            <input type="password" id="pw-reset-input" class="form-input" placeholder="Min. 12 characters" autocomplete="new-password">
            <div class="pw-strength-modal"><div class="pw-strength-modal-bar" id="pw-reset-strength-bar"></div></div>
            <div style="font-size:.75rem;margin-top:.25rem" id="pw-reset-strength-label"></div>
        </div>
        <div id="pw-reset-error" style="display:none;padding:.5rem .75rem;background:var(--error-bg);color:var(--error);border-radius:var(--radius-sm);font-size:.85rem;margin-bottom:.75rem"></div>
        <div class="confirm-actions">
            <button class="btn btn-ghost" id="pw-reset-cancel">Cancel</button>
            <button class="btn btn-danger" id="pw-reset-ok">Reset Password</button>
        </div>
    </div>
</div>

<!-- Invite User Modal (sesja 067) -->
<div class="confirm-overlay" id="invite-overlay">
    <div class="confirm-box" style="max-width:460px">
        <h3>✉ Invite User</h3>
        <p style="margin-bottom:1rem">
            An email with a setup link will be sent to the address below.<br>
            The link is valid for <strong>24 hours</strong>. Invite works regardless of registration settings.
        </p>
        <div style="margin-bottom:.75rem">
            <label style="font-size:.78rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:.35rem">Email address</label>
            <input type="email" id="invite-email" class="form-input" placeholder="user@example.com" autocomplete="off">
        </div>
        <div style="margin-bottom:.75rem">
            <label style="font-size:.78rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:.35rem">Login <span style="font-weight:400;text-transform:none">(letters, numbers, underscore, 3–50 chars)</span></label>
            <input type="text" id="invite-login" class="form-input" placeholder="username" autocomplete="off" maxlength="50">
        </div>
        <?php if (!$smtp_enabled): ?>
        <div style="padding:.5rem .75rem;background:var(--warning-bg);color:var(--warning);border-radius:var(--radius-sm);font-size:.82rem;margin-bottom:.75rem;border:1px solid var(--warning-bdr)">
            ⚠ SMTP not configured — the invite email will NOT be sent. The account will be created but the user won't receive the setup link.
        </div>
        <?php endif; ?>
        <div id="invite-error" style="display:none;padding:.5rem .75rem;background:var(--error-bg);color:var(--error);border-radius:var(--radius-sm);font-size:.85rem;margin-bottom:.75rem"></div>
        <div id="invite-success" style="display:none;padding:.5rem .75rem;background:var(--success-bg);color:var(--success);border-radius:var(--radius-sm);font-size:.85rem;margin-bottom:.75rem;border:1px solid var(--success-bdr)"></div>
        <div class="confirm-actions">
            <button class="btn btn-ghost" id="invite-cancel">Close</button>
            <button class="btn btn-primary" id="invite-ok">Send invite →</button>
        </div>
    </div>
</div>


<!-- Create User Modal (sesja 069) -->
<div class="confirm-overlay" id="create-user-overlay">
    <div class="confirm-box" style="max-width:480px">
        <h3>➕ Create User</h3>
        <p style="margin-bottom:1rem">
            Create an account immediately. The user can log in right away
            with the credentials you set here. No email required.
        </p>
        <div style="margin-bottom:.75rem">
            <label style="font-size:.78rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:.35rem">Login <span style="font-weight:400;text-transform:none">(letters, numbers, underscore, 3–50 chars)</span></label>
            <input type="text" id="cu-login" class="form-input" placeholder="username" autocomplete="off" maxlength="50">
        </div>
        <div style="margin-bottom:.75rem">
            <label style="font-size:.78rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:.35rem">Email address</label>
            <input type="email" id="cu-email" class="form-input" placeholder="user@example.com" autocomplete="off">
        </div>
        <div style="margin-bottom:.75rem">
            <label style="font-size:.78rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:.35rem">Password</label>
            <input type="password" id="cu-password" class="form-input" placeholder="Min. 12 characters" autocomplete="new-password">
            <div class="pw-strength-modal"><div class="pw-strength-modal-bar" id="cu-strength-bar"></div></div>
            <div style="font-size:.75rem;margin-top:.25rem" id="cu-strength-label"></div>
        </div>
        <div style="margin-bottom:.75rem">
            <label style="font-size:.78rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;display:block;margin-bottom:.35rem">Role</label>
            <div style="display:flex;gap:.75rem">
                <label style="display:flex;align-items:center;gap:.4rem;font-size:.9rem;cursor:pointer">
                    <input type="radio" name="cu-role" value="user" checked style="accent-color:var(--primary)"> User
                </label>
                <label style="display:flex;align-items:center;gap:.4rem;font-size:.9rem;cursor:pointer">
                    <input type="radio" name="cu-role" value="admin" style="accent-color:var(--primary)"> Admin
                </label>
            </div>
            <div style="font-size:.75rem;color:var(--text-faint);margin-top:.3rem">Admin accounts require 2FA setup on first login.</div>
        </div>
        <div id="cu-error" style="display:none;padding:.5rem .75rem;background:var(--error-bg);color:var(--error);border-radius:var(--radius-sm);font-size:.85rem;margin-bottom:.75rem"></div>
        <div id="cu-success" style="display:none;padding:.5rem .75rem;background:var(--success-bg);color:var(--success);border-radius:var(--radius-sm);font-size:.85rem;margin-bottom:.75rem;border:1px solid var(--success-bdr)"></div>
        <div class="confirm-actions">
            <button class="btn btn-ghost" id="cu-cancel">Close</button>
            <button class="btn btn-primary" id="cu-ok">Create account →</button>
        </div>
    </div>
</div>

<!-- Re-auth Modal (SEC-079, generalized for SEC-105) -->
<div class="confirm-overlay" id="reauth-overlay">
    <div class="confirm-box" style="max-width:400px">
        <h3>🔒 Confirm your password</h3>
        <p id="reauth-message" style="margin-bottom:.75rem">
            Updating pulls and runs new code from GitHub. Re-enter your
            password to confirm.
        </p>
        <input type="password" id="reauth-password" class="form-input"
               placeholder="Your current password" autocomplete="current-password">
        <div id="reauth-error" style="display:none;padding:.5rem .75rem;background:var(--error-bg);color:var(--error);border-radius:var(--radius-sm);font-size:.85rem;margin-top:.75rem"></div>
        <div class="confirm-actions" style="margin-top:1rem">
            <button class="btn btn-ghost" id="reauth-cancel">Cancel</button>
            <button class="btn btn-primary" id="reauth-ok">Confirm</button>
        </div>
    </div>
</div>

<div class="toast-container" id="toast-container"></div>

<script nonce="<?= CSP::nonce() ?>">
const CSRF       = <?= json_encode($csrf_token) ?>;
const ME_ID      = <?= (int)$user['id'] ?>;
const MY_SESSION = <?= json_encode($my_session_id) ?>;
const PW_RULES   = <?= $pw_rules ?>;
const SMTP_OK    = <?= $smtp_enabled ? 'true' : 'false' ?>;
let REGISTRATION_ENABLED = <?= $registration_json ?>;

let blocked  = <?= $blocked_json ?>;
let users    = <?= $users_json ?>;
let sessions = <?= $sessions_json ?>;
let history  = <?= $history_json ?>;
let checks   = <?= $checks_json ?>;

// sesja 074: custom colors
const ADMIN_COLORS = <?= $custom_colors_json ?>;
const ADMIN_EXTRAS = <?= $custom_extras_json ?>;

// ── Theme ─────────────────────────────────────────────────────────────────────
// sesja 071a+074: 3-theme cycle + custom primary/bg/text colors
(function(){
    // ── Color helpers ──────────────────────────────────────────────────────────
    function _hexToRgb(hex) {
        return [parseInt(hex.slice(1,3),16), parseInt(hex.slice(3,5),16), parseInt(hex.slice(5,7),16)];
    }
    function _darken(hex, amt) {
        const [r,g,b] = _hexToRgb(hex);
        return '#' + [r,g,b].map(v => Math.max(0,Math.min(255,Math.round(v*(1-amt)))).toString(16).padStart(2,'0')).join('');
    }
    function _lighten(hex, amt) {
        const [r,g,b] = _hexToRgb(hex);
        return '#' + [r,g,b].map(v => Math.min(255,Math.round(v+(255-v)*amt)).toString(16).padStart(2,'0')).join('');
    }
    function _luminance(hex) {
        const [r,g,b] = _hexToRgb(hex);
        return (0.299*r + 0.587*g + 0.114*b) / 255;
    }
    function _contrastFg(hex) {
        const [r,g,b] = _hexToRgb(hex);
        return (0.299*r + 0.587*g + 0.114*b)/255 > 0.55 ? '#000000' : '#ffffff';
    }
    function _toRgba(hex, a) {
        const [r,g,b] = _hexToRgb(hex);
        return `rgba(${r},${g},${b},${a})`;
    }
    function _setCssVars(hex) {
        const root = document.documentElement;
        root.style.setProperty('--primary',       hex);
        root.style.setProperty('--primary-h',     _darken(hex, 0.15));
        root.style.setProperty('--primary-hover', _darken(hex, 0.12));
        root.style.setProperty('--primary-fg',    _contrastFg(hex));
        root.style.setProperty('--primary-bg',    _toRgba(hex, 0.10));
        root.style.setProperty('--primary-bdr',   _toRgba(hex, 0.30));
        root.style.setProperty('--border-focus',  hex);
        root.style.setProperty('--info',          hex);
    }
    function _clearCssVars() {
        ['--primary','--primary-h','--primary-hover','--primary-fg',
         '--primary-bg','--primary-bdr','--border-focus','--info']
        .forEach(v => document.documentElement.style.removeProperty(v));
    }
    function _setExtraCssVars(bg, text) {
        const root = document.documentElement;
        if (bg && /^#[0-9A-Fa-f]{6}$/i.test(bg)) {
            const lum = _luminance(bg);
            root.style.setProperty('--bg', bg);
            if (lum > 0.5) {
                root.style.setProperty('--surface',       _lighten(bg, 0.55));
                root.style.setProperty('--surface-alt',   _darken(bg, 0.04));
                root.style.setProperty('--surface-hover', _darken(bg, 0.07));
                root.style.setProperty('--border',        _darken(bg, 0.14));
                root.style.setProperty('--border-light',  _darken(bg, 0.08));
            } else {
                root.style.setProperty('--surface',       _lighten(bg, 0.08));
                root.style.setProperty('--surface-alt',   _lighten(bg, 0.15));
                root.style.setProperty('--surface-hover', _lighten(bg, 0.11));
                root.style.setProperty('--border',        _lighten(bg, 0.24));
                root.style.setProperty('--border-light',  _lighten(bg, 0.17));
            }
        }
        if (text && /^#[0-9A-Fa-f]{6}$/i.test(text)) {
            const [r,g,b] = _hexToRgb(text);
            root.style.setProperty('--text',       text);
            root.style.setProperty('--text-muted', `rgba(${r},${g},${b},0.65)`);
            root.style.setProperty('--text-faint', `rgba(${r},${g},${b},0.40)`);
        }
    }
    function _clearExtraCssVars() {
        ['--bg','--surface','--surface-alt','--surface-hover','--border','--border-light',
         '--text','--text-muted','--text-faint']
        .forEach(v => document.documentElement.style.removeProperty(v));
    }
    function _applyCustomColor(t) {
        const hex   = ADMIN_COLORS[t];
        const extra = ADMIN_EXTRAS[t];
        if (hex && /^#[0-9A-Fa-f]{6}$/i.test(hex)) { _setCssVars(hex); }
        else { _clearCssVars(); }
        if (extra && (extra.bg || extra.text)) { _setExtraCssVars(extra.bg || null, extra.text || null); }
        else { _clearExtraCssVars(); }
    }

    // ── Theme cycle ───────────────────────────────────────────────────────────
    const THEMES_ORD  = ['light', 'dark', 'midnight'];
    const NEXT_LABELS = { light: '🌙 Dark', dark: '🌑 Midnight', midnight: '☀ Light' };
    function nextTh(t) {
        const i = THEMES_ORD.indexOf(t);
        return THEMES_ORD[(i + 1) % THEMES_ORD.length];
    }
    function applyTh(t, save) {
        if (!THEMES_ORD.includes(t)) t = 'light';
        document.documentElement.setAttribute('data-theme', t);
        _applyCustomColor(t);
        if (save) {
            localStorage.setItem('dv-theme', t);
            fetch('/api/settings/theme', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                credentials: 'same-origin',
                body: JSON.stringify({ theme: t })
            }).catch(() => {});
        }
        const btn = document.getElementById('theme-btn');
        if (btn) btn.textContent = NEXT_LABELS[t] || '🌙 Dark';
    }
    const t = localStorage.getItem('dv-theme') || 'light';
    applyTh(t, false);
    document.getElementById('theme-btn')?.addEventListener('click', () => {
        const c = document.documentElement.getAttribute('data-theme') || 'light';
        applyTh(nextTh(c), true);
    });
})();

// ── Helpers ───────────────────────────────────────────────────────────────────
function toast(msg, type = 'info', dur = 3500) {
    const icons = { success:'✓', error:'✗', info:'ℹ' };
    const el = document.createElement('div');
    el.className = `toast toast-${type}`;
    el.innerHTML = `<span class="toast-icon">${icons[type]||'ℹ'}</span><span>${esc(msg)}</span>`;
    document.getElementById('toast-container').appendChild(el);
    setTimeout(() => el.style.opacity = '0', dur - 300);
    setTimeout(() => el.remove(), dur);
}

let _cfResolve = null;
function cfm(title, msg) {
    return new Promise(resolve => {
        _cfResolve = resolve;
        document.getElementById('confirm-title').textContent = title;
        document.getElementById('confirm-msg').textContent   = msg;
        document.getElementById('confirm-overlay').classList.add('show');
    });
}
document.getElementById('confirm-cancel').addEventListener('click', () => {
    document.getElementById('confirm-overlay').classList.remove('show'); _cfResolve?.(false);
});
document.getElementById('confirm-ok').addEventListener('click', () => {
    document.getElementById('confirm-overlay').classList.remove('show'); _cfResolve?.(true);
});
document.getElementById('confirm-overlay').addEventListener('click', e => {
    if (e.target === e.currentTarget) { e.currentTarget.classList.remove('show'); _cfResolve?.(false); }
});

function esc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
async function api(method, url, body) {
    try {
        const opts = { method, headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, credentials:'same-origin' };
        if (body) opts.body = JSON.stringify(body);
        const res = await fetch(url, opts);
        return await res.json();
    } catch(e) { return {ok:false, error:'Network error.'}; }
}
function relTime(s) {
    if (!s) return '—';
    const d = new Date(s.replace(' ','T'));
    const diff = Math.floor((Date.now()-d.getTime())/1000);
    if (diff<60)    return `${diff}s ago`;
    if (diff<3600)  return `${Math.floor(diff/60)}m ago`;
    if (diff<86400) return `${Math.floor(diff/3600)}h ago`;
    return `${Math.floor(diff/86400)}d ago`;
}
function parseUA(ua) {
    if (!ua) return 'Unknown';
    let b='Unknown', o='Unknown';
    if (/EdgA?\//.test(ua)) b='Edge';
    else if (/OPR\//.test(ua)) b='Opera';
    else if (/Chrome\//.test(ua)) b='Chrome';
    else if (/Safari\//.test(ua)&&/Version\//.test(ua)) b='Safari';
    else if (/Firefox\//.test(ua)) b='Firefox';
    if      (/Windows NT/.test(ua)) o='Windows';
    else if (/Macintosh/.test(ua))  o='macOS';
    else if (/Android/.test(ua))    o='Android';
    else if (/iPhone|iPad/.test(ua))o='iOS';
    else if (/Linux/.test(ua))      o='Linux';
    return `${b} / ${o}`;
}

/**
 * Avatar thumbnail HTML for a user row (sesja 078).
 * Falls back to a 👤 placeholder circle if avatar_path is null, or if the
 * image 404s for any reason (file deleted out-of-band, etc).
 */
function avatarHtml(u) {
    if (u.avatar_path) {
        return `<img src="/api/avatars/${u.id}" alt="" class="admin-avatar-img">`;
    }
    return `<span class="admin-avatar-fallback">👤</span>`;
}

// ── Tabs ──────────────────────────────────────────────────────────────────────
document.querySelectorAll('.admin-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.admin-tab').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
    });
});

// ═══════════════════════════════════════════════════════════════════════════════
// TAB 1: BLOCKED
// ═══════════════════════════════════════════════════════════════════════════════
function attemptBadge(n) {
    n=parseInt(n)||0;
    const cls=n>=20?'ab-crit':n>=10?'ab-high':'ab-low';
    return `<span class="attempts-badge ${cls}">${n}</span>`;
}
function renderBlocked(data) {
    const tbody=document.getElementById('blocked-tbody');
    const q=document.getElementById('blocked-filter').value.trim().toLowerCase();
    const minVal=parseInt(document.getElementById('blocked-min-input').value)||3;
    const fil=data.filter(r=>(parseInt(r.attempts)||0)>=minVal
        &&(!q||(r.key_plain||'').toLowerCase().includes(q)||(r.action||'').toLowerCase().includes(q)));
    if(!fil.length){tbody.innerHTML='<tr><td colspan="8" class="table-empty">🎉 No blocked entries</td></tr>';return;}
    tbody.innerHTML=fil.map(r=>{
        const kd=r.key_plain?`<span class="mono">${esc(r.key_plain)}</span>`:`<span class="muted mono">hash:${esc((r.key_hash||'').slice(0,8))}…</span>`;
        const ua=r.last_ua?`<span class="ua" title="${esc(r.last_ua)}">${esc(r.last_ua)}</span>`:'<span class="muted">—</span>';
        const hb=r.key_plain?`<button class="btn btn-ghost btn-sm" data-action="show-history" data-key="${esc(r.key_plain)}">📋</button>`:'';
        return `<tr><td><code>${esc(r.action)}</code></td><td>${kd}</td><td>${attemptBadge(r.attempts)}</td>
            <td class="muted" style="font-size:.8rem">${relTime(r.window_start)}</td>
            <td class="mono muted">${esc(r.last_login_attempt||'—')}</td><td>${ua}</td><td>${hb}</td>
            <td style="white-space:nowrap">
                <button class="btn btn-ghost btn-sm" data-action="unblock" data-key-hash="${esc(r.key_hash)}" data-rl-action="${esc(r.action)}" data-key-plain="${esc(r.key_plain||'')}">✓ Unblock</button>
                ${r.key_plain?`<button class="btn btn-danger btn-sm" style="margin-left:.25rem" data-action="unblock-all" data-key-plain="${esc(r.key_plain)}">All</button>`:''}
            </td></tr>`;
    }).join('');
}
async function doUnblock(kh,action,kp) {
    if(!await cfm('Unblock',`Remove rate limit: ${action} for ${kp||kh.slice(0,8)+'…'}?`))return;
    const r=await api('POST','/api/admin/unblock',{key_hash:kh,action});
    if(!r.ok){toast(r.error||'Failed.','error');return;}
    toast('Entry unblocked.','success');
    blocked=blocked.filter(e=>!(e.key_hash===kh&&e.action===action));
    renderBlocked(blocked);
}
async function doUnblockAll(kp) {
    if(!await cfm('Unblock all',`Remove ALL rate limit entries for:\n${kp}?`))return;
    const r=await api('POST','/api/admin/unblock-all',{key_plain:kp});
    if(!r.ok){toast(r.error||'Failed.','error');return;}
    toast(`${r.deleted} entr${r.deleted!==1?'ies':'y'} removed.`,'success');
    blocked=blocked.filter(e=>e.key_plain!==kp);renderBlocked(blocked);
}
async function doUnblockAllGlobal() {
    if(!blocked.length){toast('Nothing to unblock.','info');return;}
    if(!await cfm('Unblock ALL',`Remove all ${blocked.length} rate limit entries?`))return;
    const keys=[...new Set(blocked.map(e=>e.key_plain).filter(Boolean))];
    let del=0;
    for(const k of keys){const r=await api('POST','/api/admin/unblock-all',{key_plain:k});if(r.ok)del+=r.deleted||0;}
    for(const e of blocked.filter(e=>!e.key_plain)){await api('POST','/api/admin/unblock',{key_hash:e.key_hash,action:e.action});del++;}
    toast(`All cleared (${del} entries).`,'success');
    blocked=[];renderBlocked(blocked);
}
function showHistoryFor(ip){document.querySelector('.admin-tab[data-tab="history"]').click();document.getElementById('history-ip-filter').value=ip;filterHistory();}
document.getElementById('blocked-filter').addEventListener('input',()=>renderBlocked(blocked));
document.getElementById('blocked-min-input').addEventListener('change',()=>{
    const v=parseInt(document.getElementById('blocked-min-input').value)||3;
    document.getElementById('blocked-min-label').textContent=`≥ ${v}`;
    renderBlocked(blocked);
});
document.getElementById('btn-refresh-blocked').addEventListener('click',async()=>{
    const min=parseInt(document.getElementById('blocked-min-input').value)||3;
    const r=await api('GET',`/api/admin/blocked?min=${min}`);
    if(!r.ok){toast('Refresh failed.','error');return;}
    blocked=r.entries;renderBlocked(blocked);toast('Refreshed.','success');
});
document.getElementById('btn-unblock-all-global').addEventListener('click',doUnblockAllGlobal);
document.getElementById('btn-export-csv').addEventListener('click',()=>{window.location.href='/api/admin/export-blocked?format=csv';});
document.getElementById('btn-export-json').addEventListener('click',()=>{window.location.href='/api/admin/export-blocked?format=json';});

// ═══════════════════════════════════════════════════════════════════════════════
// TAB 2: USERS
// ═══════════════════════════════════════════════════════════════════════════════
function renderUsers(data) {
    const tbody=document.getElementById('users-tbody');
    const q=document.getElementById('users-filter').value.trim().toLowerCase();
    const fil=q?data.filter(u=>(u.login||'').toLowerCase().includes(q)||(u.email||'').toLowerCase().includes(q)):data;
    document.getElementById('users-count').textContent=fil.length;
    if(!fil.length){tbody.innerHTML='<tr><td colspan="11" class="table-empty">No users.</td></tr>';return;}
    tbody.innerHTML=fil.map(u=>{
        const roleDisp=u.role==='admin'?`<span class="status-ok">admin</span>`:`<span class="muted">user</span>`;
        const twofa=u.totp_enabled?`<span class="status-ok">✓</span>`:`<span class="status-fail">✗</span>`;
        const verified=parseInt(u.email_verified)?`<span class="status-ok">✓</span>`:`<span style="color:var(--warning)">pending</span>`;
        const isMe=parseInt(u.id)===ME_ID;
        const delBtn=isMe?`<span class="muted" title="Cannot delete own account">—</span>`
            :`<button class="btn btn-danger btn-sm" data-action="delete-user" data-user-id="${u.id}" data-login="${esc(u.login)}">🗑</button>`;
        const pwBtn=isMe?``:`<button class="btn btn-ghost btn-sm" style="margin-left:.25rem" data-action="force-reset" data-user-id="${u.id}" data-login="${esc(u.login)}">🔑</button>`;
        const sessBtn=`<button class="btn btn-ghost btn-sm" style="margin-left:.25rem" data-action="filter-sessions-to-user" data-user-id="${u.id}" data-login="${esc(u.login)}">🖥️ ${u.session_count||0}</button>`;
        return `<tr>
            <td><div class="admin-user-cell">${avatarHtml(u)}<strong>${esc(u.login)}</strong>${isMe?' <span class="muted">(you)</span>':''}</div></td>
            <td class="muted">${esc(u.email||'')}</td>
            <td>${roleDisp}</td><td>${twofa}</td><td>${verified}</td>
            <td class="muted">${u.group_count||0}</td><td class="muted">${u.dial_count||0}</td>
            <td class="muted">${sessBtn}</td>
            <td class="muted" style="font-size:.8rem">${relTime(u.last_login)}</td>
            <td class="muted" style="font-size:.8rem">${relTime(u.created_at)}</td>
            <td style="white-space:nowrap">${delBtn}${pwBtn}</td>
        </tr>`;
    }).join('');
}
async function doDeleteUser(userId,login) {
    if(!await cfm('Delete account',`Permanently delete "${login}"?\n\nAll groups, dials, sessions, avatar and thumbnails will be removed.\nCannot be undone.`))return;
    const r=await api('POST','/api/admin/delete-user',{user_id:userId});
    if(!r.ok){toast(r.error||'Could not delete.','error');return;}
    toast(`Account "${r.login}" deleted.`,'success');
    users=users.filter(u=>parseInt(u.id)!==userId);renderUsers(users);
}
document.getElementById('users-filter').addEventListener('input',()=>renderUsers(users));
document.getElementById('btn-refresh-users').addEventListener('click',async()=>{
    const r=await api('GET','/api/admin/users');
    if(!r.ok){toast('Refresh failed.','error');return;}
    users=r.users;renderUsers(users);toast('Refreshed.','success');
});

// ── Force Reset Password ──────────────────────────────────────────────────────
const pwResetOverlay=document.getElementById('pw-reset-overlay');
const pwResetInput=document.getElementById('pw-reset-input');
const pwResetBar=document.getElementById('pw-reset-strength-bar');
const pwResetLabel=document.getElementById('pw-reset-strength-label');
const pwResetError=document.getElementById('pw-reset-error');
const levelClrs=['','#E53E3E','#D69E2E','#D69E2E','#1D5C42'];
const levelPct=['','25%','50%','75%','100%'];
const levelNames=['','Too short','Weak','Fair','Strong'];
function calcStrength(pw){
    if(!pw||pw.length<(PW_RULES.minLength||12))return 1;
    let s=0;
    if(/[A-Z]/.test(pw))s++;if(/[a-z]/.test(pw))s++;if(/[0-9]/.test(pw))s++;if(/[^A-Za-z0-9]/.test(pw))s++;
    if(pw.length>=16)s=Math.min(s+1,4);
    return Math.max(1,Math.min(s,4));
}
pwResetInput?.addEventListener('input',function(){
    const pw=this.value;
    if(!pw){pwResetBar.style.width='0';pwResetLabel.textContent='';return;}
    const lvl=calcStrength(pw);
    pwResetBar.style.width=levelPct[lvl];pwResetBar.style.background=levelClrs[lvl];
    pwResetLabel.textContent=levelNames[lvl];pwResetLabel.style.color=levelClrs[lvl];
});
function showForceReset(userId,login){
    document.getElementById('pw-reset-user-id').value=userId;
    document.getElementById('pw-reset-login').textContent=login;
    if(pwResetInput){pwResetInput.value='';pwResetInput.type='password';}
    if(pwResetBar)pwResetBar.style.width='0';
    if(pwResetLabel)pwResetLabel.textContent='';
    pwResetError.style.display='none';
    pwResetOverlay.classList.add('show');
    setTimeout(()=>pwResetInput?.focus(),80);
}
document.getElementById('pw-reset-cancel').addEventListener('click',()=>pwResetOverlay.classList.remove('show'));
pwResetOverlay.addEventListener('click',e=>{if(e.target===e.currentTarget)pwResetOverlay.classList.remove('show');});
document.getElementById('pw-reset-ok').addEventListener('click',async()=>{
    const userId=parseInt(document.getElementById('pw-reset-user-id').value);
    const pw=pwResetInput?.value||'';
    pwResetError.style.display='none';
    if(!pw){pwResetError.textContent='Please enter a new password.';pwResetError.style.display='';return;}

    // SEC-105: step-up re-auth — resetting another user's password is a
    // full account takeover if this session were ever hijacked, same class
    // of risk as git-pull (SEC-079). Modal is hidden while asking and
    // re-shown on cancel/failure so the admin's already-typed new password
    // is not lost.
    pwResetOverlay.classList.remove('show');
    const adminPw=await promptReauth("Resetting another user's password requires confirming your own. Re-enter your password to continue.");
    if(adminPw===null){ pwResetOverlay.classList.add('show'); return; }

    const btn=document.getElementById('pw-reset-ok');
    btn.disabled=true;btn.textContent='…';
    const r=await api('POST','/api/admin/force-password',{user_id:userId,password:pw,admin_password:adminPw});
    btn.disabled=false;btn.textContent='Reset Password';
    if(!r.ok){
        pwResetOverlay.classList.add('show');
        pwResetError.textContent=r.error||'Could not reset password.';pwResetError.style.display='';
        return;
    }
    toast(`Password reset for "${r.login}". All their sessions invalidated.`,'success',5000);
    const r2=await api('GET','/api/admin/sessions');
    if(r2.ok){sessions=r2.sessions;renderSessions(sessions);}
});

// ── Invite User (sesja 067) ───────────────────────────────────────────────────
const inviteOverlay=document.getElementById('invite-overlay');
const inviteEmail=document.getElementById('invite-email');
const inviteLogin=document.getElementById('invite-login');
const inviteError=document.getElementById('invite-error');
const inviteSuccess=document.getElementById('invite-success');

document.getElementById('btn-invite-user').addEventListener('click',()=>{
    inviteEmail.value='';inviteLogin.value='';
    inviteError.style.display='none';inviteSuccess.style.display='none';
    document.getElementById('invite-ok').disabled=false;
    document.getElementById('invite-ok').textContent='Send invite →';
    inviteOverlay.classList.add('show');
    setTimeout(()=>inviteEmail.focus(),80);
});
document.getElementById('invite-cancel').addEventListener('click',()=>{
    inviteOverlay.classList.remove('show');
    // Refresh user list to show newly invited (pending) accounts
    api('GET','/api/admin/users').then(r=>{if(r.ok){users=r.users;renderUsers(users);}});
});
inviteOverlay.addEventListener('click',e=>{
    if(e.target===e.currentTarget){
        inviteOverlay.classList.remove('show');
        api('GET','/api/admin/users').then(r=>{if(r.ok){users=r.users;renderUsers(users);}});
    }
});
[inviteEmail,inviteLogin].forEach(el=>el.addEventListener('keydown',e=>{
    if(e.key==='Enter')document.getElementById('invite-ok').click();
}));
document.getElementById('invite-ok').addEventListener('click',async()=>{
    inviteError.style.display='none';inviteSuccess.style.display='none';
    const email=inviteEmail.value.trim();
    const login=inviteLogin.value.trim();
    if(!email||!login){
        inviteError.textContent='Both email and login are required.';
        inviteError.style.display='';return;
    }
    const btn=document.getElementById('invite-ok');
    btn.disabled=true;btn.textContent='Sending…';
    const r=await api('POST','/api/admin/invite',{email,login});
    btn.disabled=false;btn.textContent='Send another →';
    if(!r.ok){
        inviteError.textContent=r.error||'Could not send invite.';
        inviteError.style.display='';
        btn.textContent='Send invite →';btn.disabled=false;
        return;
    }
    inviteEmail.value='';inviteLogin.value='';
    if(r.email_sent){
        inviteSuccess.textContent=`✓ Invite sent to ${email}. The link expires in 24 hours.`;
    } else if(!r.smtp_enabled){
        inviteSuccess.textContent=`✓ Account created for ${email} (login: ${login}), but SMTP is disabled — email not sent. Share the setup link manually.`;
    } else {
        inviteSuccess.textContent=`✓ Account created for ${email}, but the email could not be sent. Check SMTP settings.`;
    }
    inviteSuccess.style.display='';
    btn.textContent='Send invite →';
    users=[...users];// Will refresh on close
});

// ═══════════════════════════════════════════════════════════════════════════════
// TAB 3: SESSIONS
// ═══════════════════════════════════════════════════════════════════════════════
function renderSessions(data) {
    const tbody=document.getElementById('sessions-tbody');
    const q=document.getElementById('sessions-filter').value.trim().toLowerCase();
    const fil=q?data.filter(s=>(s.login||'').toLowerCase().includes(q)||(s.ip||'').toLowerCase().includes(q)):data;
    document.getElementById('sessions-count').textContent=fil.length;
    if(!fil.length){tbody.innerHTML='<tr><td colspan="8" class="table-empty">No active sessions.</td></tr>';return;}
    tbody.innerHTML=fil.map(s=>{
        const isMine=s.id===MY_SESSION;
        const ua=parseUA(s.user_agent);
        const rc=isMine?' class="sess-this"':'';
        const badge=isMine?' <span style="font-size:.7rem;background:var(--primary);color:var(--primary-fg);padding:.1rem .4rem;border-radius:9999px;font-weight:700">you</span>':'';
        const twofa=s.totp_verified?'<span class="status-ok">✓</span>':'<span class="muted">—</span>';
        const roleDisp=s.role==='admin'?`<span class="status-ok" style="font-size:.78rem">admin</span>`:`<span class="muted" style="font-size:.78rem">user</span>`;
        const actions=isMine?`<span class="muted" style="font-size:.75rem">current</span>`
            :`<button class="btn btn-ghost btn-sm" style="border-color:var(--error-bdr);color:var(--error)"
                data-action="delete-session" data-session-id="${esc(s.id)}" data-login="${esc(s.login)}">Sign out</button>
               <button class="btn btn-ghost btn-sm" style="margin-left:.25rem"
                data-action="delete-user-sessions" data-user-id="${s.user_id}" data-login="${esc(s.login)}">All of user</button>`;
        return `<tr${rc}>
            <td><strong>${esc(s.login)}</strong>${badge}</td><td>${roleDisp}</td>
            <td><span class="mono">${esc(s.ip)}</span>
                <button class="btn btn-ghost btn-sm" style="margin-left:.3rem;padding:.15rem .4rem;font-size:.7rem" data-action="show-history" data-key="${esc(s.ip)}">📋</button></td>
            <td class="muted">${esc(ua)}</td>
            <td class="muted" style="font-size:.8rem">${relTime(s.last_activity)}</td>
            <td class="muted" style="font-size:.8rem">${relTime(s.created_at)}</td>
            <td>${twofa}</td><td style="white-space:nowrap">${actions}</td>
        </tr>`;
    }).join('');
}
async function doDeleteSession(sessionId,login,btn) {
    if(!await cfm('Sign out session',`Sign out this session for "${login}"?`))return;
    btn.disabled=true;btn.textContent='…';
    const r=await api('POST','/api/admin/sessions/delete',{session_id:sessionId});
    if(!r.ok){toast(r.error||'Could not sign out.','error');btn.disabled=false;btn.textContent='Sign out';return;}
    toast(`Session signed out for "${login}".`,'success');
    sessions=sessions.filter(s=>s.id!==sessionId);renderSessions(sessions);
}
async function doDeleteUserSessions(userId,login) {
    if(!await cfm('Sign out all sessions',`Sign out ALL sessions for "${login}"?`))return;
    const r=await api('POST','/api/admin/sessions/delete-user',{user_id:userId});
    if(!r.ok){toast(r.error||'Could not sign out.','error');return;}
    toast(`${r.deleted} session${r.deleted!==1?'s':''} signed out for "${login}".`,'success');
    sessions=sessions.filter(s=>s.user_id!==userId);renderSessions(sessions);
    const r2=await api('GET','/api/admin/users');
    if(r2.ok){users=r2.users;renderUsers(users);}
}
function filterSessionsToUser(userId,login){
    document.querySelector('.admin-tab[data-tab="sessions"]').click();
    document.getElementById('sessions-filter').value=login;renderSessions(sessions);
}
document.getElementById('sessions-filter').addEventListener('input',()=>renderSessions(sessions));
document.getElementById('btn-refresh-sessions').addEventListener('click',async()=>{
    const r=await api('GET','/api/admin/sessions');
    if(!r.ok){toast('Refresh failed.','error');return;}
    sessions=r.sessions;renderSessions(sessions);toast('Refreshed.','success');
});

// ═══════════════════════════════════════════════════════════════════════════════
// TAB 4: LOGIN HISTORY
// ═══════════════════════════════════════════════════════════════════════════════
function filterHistory(){
    const q=document.getElementById('history-ip-filter').value.trim().toLowerCase();
    const st=document.getElementById('history-status-filter').value;
    renderHistory(history.filter(h=>(!q||(h.ip||'').toLowerCase().includes(q))&&(!st||h.status===st)));
}
function renderHistory(data){
    const tbody=document.getElementById('history-tbody');
    document.getElementById('history-count-label').textContent=`${data.length} entries`;
    if(!data.length){tbody.innerHTML='<tr><td colspan="6" class="table-empty">No entries.</td></tr>';return;}
    tbody.innerHTML=data.map(h=>{
        const cls=h.status==='success'?'hist-success':'hist-fail';
        const u=h.resolved_login||(h.user_id?`#${h.user_id}`:'—');
        return `<tr>
            <td class="muted mono" style="font-size:.78rem">${esc(h.created_at||'')}</td>
            <td>${esc(u)}</td><td class="muted">${esc(h.login_attempt||'—')}</td>
            <td><span class="mono">${esc(h.ip||'')}</span>
                <button class="btn btn-ghost btn-sm" style="margin-left:.3rem;padding:.15rem .4rem" data-action="filter-to-ip" data-ip="${esc(h.ip||'')}">🔍</button></td>
            <td class="${cls}">${esc(h.status||'')}</td>
            <td class="ua" title="${esc(h.user_agent||'')}">${esc((h.user_agent||'').slice(0,80))}</td>
        </tr>`;
    }).join('');
}
function filterToIp(ip){document.getElementById('history-ip-filter').value=ip;filterHistory();}
document.getElementById('history-ip-filter').addEventListener('input',filterHistory);
document.getElementById('history-status-filter').addEventListener('change',filterHistory);
document.getElementById('btn-refresh-history').addEventListener('click',async()=>{
    const ip=document.getElementById('history-ip-filter').value.trim()||null;
    const url=ip?`/api/admin/login-history?ip=${encodeURIComponent(ip)}&limit=100`:'/api/admin/login-history?limit=100';
    const r=await api('GET',url);
    if(!r.ok){toast('Refresh failed.','error');return;}
    history=r.history;filterHistory();toast('Refreshed.','success');
});

// ═══════════════════════════════════════════════════════════════════════════════
// TAB 5: UPDATE
// ═══════════════════════════════════════════════════════════════════════════════
function updateShow(id){
    ['update-idle','update-checking','update-current','update-available','update-running','update-done']
        .forEach(s=>{const el=document.getElementById(s);if(el)el.style.display=s===id?'':'none';});
}
async function doGitCheck(){
    updateShow('update-checking');
    const r=await api('GET','/api/update/git-check');
    if(!r.ok){toast(r.error||'Check failed.','error');updateShow('update-idle');return;}
    if(!r.update_available){
        document.getElementById('update-current-sha').textContent=`Local: ${r.local_sha}`;
        updateShow('update-current');return;
    }
    document.getElementById('update-available-sub').textContent=`${r.commit_count} new commit${r.commit_count!==1?'s':''} available on GitHub.`;
    document.getElementById('update-available-sha').textContent=`Local: ${r.local_sha}  →  GitHub main: ${r.remote_sha}`;
    const cl=document.getElementById('update-commit-list');
    if(r.commits&&r.commits.length){
        cl.innerHTML=r.commits.map(c=>`<div class="commit-item"><span class="commit-sha">${esc(c.sha)}</span><span class="commit-msg">${esc(c.msg)}</span></div>`).join('');
        cl.style.display='';
    }else{cl.style.display='none';}
    updateShow('update-available');
}
// ── Re-auth (SEC-079, generalized for SEC-105) ──────────────────────────────
let _reauthResolve = null;
function promptReauth(message){
    return new Promise(resolve=>{
        _reauthResolve=resolve;
        const pw=document.getElementById('reauth-password');
        const err=document.getElementById('reauth-error');
        const msg=document.getElementById('reauth-message');
        if(msg)msg.textContent=message||'This action requires confirming your password. Re-enter your password to continue.';
        if(pw)pw.value='';
        if(err)err.style.display='none';
        document.getElementById('reauth-overlay').classList.add('show');
        setTimeout(()=>pw?.focus(),80);
    });
}
document.getElementById('reauth-cancel')?.addEventListener('click',()=>{
    document.getElementById('reauth-overlay').classList.remove('show');
    _reauthResolve?.(null);
});
document.getElementById('reauth-overlay')?.addEventListener('click',e=>{
    if(e.target===e.currentTarget){e.currentTarget.classList.remove('show');_reauthResolve?.(null);}
});
document.getElementById('reauth-ok')?.addEventListener('click',()=>{
    const pw=document.getElementById('reauth-password')?.value||'';
    if(!pw){
        const err=document.getElementById('reauth-error');
        err.textContent='Please enter your password.';err.style.display='';
        return;
    }
    document.getElementById('reauth-overlay').classList.remove('show');
    _reauthResolve?.(pw);
});
document.getElementById('reauth-password')?.addEventListener('keydown',e=>{
    if(e.key==='Enter')document.getElementById('reauth-ok').click();
});

async function doGitPull(){
    if(!await cfm('Update LetaDial','This will run:\n  git pull origin main\n\nDo not close this page.'))return;
    const password=await promptReauth('Updating pulls and runs new code from GitHub. Re-enter your password to confirm.');
    if(password===null)return;
    updateShow('update-running');
    const r=await api('POST','/api/update/git-pull',{password});
    if(!r.ok && /password/i.test(r.error||'')){
        updateShow('update-available');
        toast(r.error,'error');
        return;
    }
    document.getElementById('update-pull-output').textContent=r.pull_output||'(no output)';
    if(r.ok){document.getElementById('update-done-icon').textContent='✅';document.getElementById('update-done-title').textContent='Update complete!';}
    else{document.getElementById('update-done-icon').textContent='❌';document.getElementById('update-done-title').textContent='Update failed';document.getElementById('update-done-sub').textContent=r.error||'See output below.';}
    updateShow('update-done');
}
document.getElementById('btn-check-update').addEventListener('click',doGitCheck);
document.getElementById('btn-recheck-1').addEventListener('click',doGitCheck);
document.getElementById('btn-recheck-2').addEventListener('click',doGitCheck);
document.getElementById('btn-recheck-3').addEventListener('click',doGitCheck);
document.getElementById('btn-update-now').addEventListener('click',doGitPull);

// ═══════════════════════════════════════════════════════════════════════════════
// TAB 6: INSTALL CHECK
// ═══════════════════════════════════════════════════════════════════════════════
function renderChecks(data){
    const total=data.length;
    const passing=data.filter(c=>c.ok).length;
    const failing=data.filter(c=>!c.ok&&c.required).length;
    const warning=data.filter(c=>!c.ok&&!c.required).length;
    document.getElementById('check-summary-bar').innerHTML=`
        <span style="display:flex;align-items:center;gap:.4rem"><span style="color:var(--success);font-size:1.1rem">✓</span><strong>${passing}</strong> passing</span>
        ${failing>0?`<span style="display:flex;align-items:center;gap:.4rem"><span style="color:var(--error);font-size:1.1rem">✗</span><strong>${failing}</strong> required failing</span>`:''}
        ${warning>0?`<span style="display:flex;align-items:center;gap:.4rem"><span style="color:var(--warning);font-size:1.1rem">⚠</span><strong>${warning}</strong> warnings</span>`:''}
        <span style="color:var(--text-muted);font-size:.8rem">${total} checks total</span>`;
    const groups={};
    data.forEach(c=>{const g=c.group||'General';if(!groups[g])groups[g]=[];groups[g].push(c);});
    const gicons={'PHP':'🐘','Database':'🗄','Configuration':'⚙','Security':'🔒','Filesystem':'📁','File Integrity':'🔍','General':'📋'};
    document.getElementById('check-container').innerHTML=Object.entries(groups).map(([group,items])=>{
        const gf=items.filter(i=>!i.ok&&i.required).length;
        const gw=items.filter(i=>!i.ok&&!i.required).length;
        const gs=gf>0?`<span style="color:var(--error);font-size:.8rem">✗ ${gf} fail${gf!==1?'s':''}</span>`
            :gw>0?`<span style="color:var(--warning);font-size:.8rem">⚠ ${gw} warn${gw!==1?'s':''}</span>`
            :`<span style="color:var(--success);font-size:.8rem">✓ all OK</span>`;
        const rows=items.map(c=>{
            const ic=c.ok?'✓':(c.required?'✗':'⚠');
            const icls=c.ok?'check-icon-ok':(c.required?'check-icon-fail':'check-icon-warn');
            const vcls=c.ok?'check-value-ok':(c.required?'check-value-fail':'');
            const note=c.note?`<div class="check-note-text">${esc(c.note)}</div>`:'';
            const req=!c.ok&&c.required?' <span style="font-size:.7rem;color:var(--error);font-weight:600">REQUIRED</span>':'';
            return `<div class="check-row"><div class="check-icon-col ${icls}">${ic}</div>
                <div class="check-label-col"><div class="check-label">${esc(c.label||'')}${req}</div>${note}</div>
                <div class="check-value-col ${vcls}">${esc(String(c.value||''))}</div></div>`;
        }).join('');
        return `<div class="admin-card"><div class="admin-card-header"><h3>${gicons[group]||'📋'} ${esc(group)}</h3>${gs}</div><div>${rows}</div></div>`;
    }).join('');
}
document.getElementById('btn-refresh-check').addEventListener('click',async()=>{
    const r=await api('GET','/api/admin/install-check');
    if(!r.ok){toast('Check failed.','error');return;}
    checks=r.checks;renderChecks(checks);toast('Checks re-run.','success');
});

// ═══════════════════════════════════════════════════════════════════════════════
// Registration Toggle (sesja 068)
// ═══════════════════════════════════════════════════════════════════════════════
function updateRegUI(enabled) {
    REGISTRATION_ENABLED = enabled;
    const badge   = document.getElementById('reg-status-badge');
    const desc    = document.getElementById('reg-status-desc');
    const btn     = document.getElementById('btn-toggle-registration');
    if (!badge || !btn) return;

    if (enabled) {
        badge.className   = 'status-badge on';
        badge.textContent = '✓ Open';
        desc.textContent  = 'Open — anyone can create an account from the login page.';
        btn.textContent   = '🔒 Disable registration';
        btn.style.borderColor = 'var(--error-bdr)';
        btn.style.color       = 'var(--error)';
    } else {
        badge.className   = 'status-badge off';
        badge.textContent = '✗ Disabled';
        desc.textContent  = 'Disabled — only admin invites work (invite always works regardless).';
        btn.textContent   = '🔓 Enable registration';
        btn.style.borderColor = 'var(--success-bdr)';
        btn.style.color       = 'var(--success)';
    }
}

document.getElementById('btn-toggle-registration')?.addEventListener('click', async () => {
    const newState = !REGISTRATION_ENABLED;
    const label    = newState ? 'enable' : 'disable';
    if (!await cfm(
        (newState ? 'Enable' : 'Disable') + ' self-registration',
        newState
            ? 'Allow anyone to create an account from the login page?'
            : 'Prevent new self-registrations? Existing accounts and admin invites are not affected.'
    )) return;

    const btn = document.getElementById('btn-toggle-registration');
    btn.disabled = true;
    const r = await api('POST', '/api/admin/registration', { enabled: newState });
    btn.disabled = false;

    if (!r.ok) { toast(r.error || 'Could not update setting.', 'error'); return; }
    updateRegUI(r.enabled);
    toast(r.enabled ? 'Registration enabled.' : 'Registration disabled.', 'success');
});


// ═══════════════════════════════════════════════════════════════════════════════
// Create User Modal (sesja 069)
// ═══════════════════════════════════════════════════════════════════════════════
const cuOverlay   = document.getElementById('create-user-overlay');
const cuLogin     = document.getElementById('cu-login');
const cuEmail     = document.getElementById('cu-email');
const cuPassword  = document.getElementById('cu-password');
const cuStrBar    = document.getElementById('cu-strength-bar');
const cuStrLabel  = document.getElementById('cu-strength-label');
const cuError     = document.getElementById('cu-error');
const cuSuccess   = document.getElementById('cu-success');

cuPassword?.addEventListener('input', function() {
    const pw = this.value;
    if (!pw) { cuStrBar.style.width='0'; cuStrLabel.textContent=''; return; }
    const lvl = calcStrength(pw);
    cuStrBar.style.width   = levelPct[lvl];
    cuStrBar.style.background = levelClrs[lvl];
    cuStrLabel.textContent = levelNames[lvl];
    cuStrLabel.style.color = levelClrs[lvl];
});

function cuReset(keepOpen) {
    cuLogin.value = ''; cuEmail.value = ''; cuPassword.value = '';
    cuStrBar.style.width = '0'; cuStrLabel.textContent = '';
    cuError.style.display = 'none'; cuSuccess.style.display = 'none';
    document.querySelector('input[name="cu-role"][value="user"]').checked = true;
    document.getElementById('cu-ok').disabled = false;
    document.getElementById('cu-ok').textContent = 'Create account →';
}

document.getElementById('btn-create-user')?.addEventListener('click', () => {
    cuReset(false);
    cuOverlay.classList.add('show');
    setTimeout(() => cuLogin.focus(), 80);
});

document.getElementById('cu-cancel').addEventListener('click', () => {
    cuOverlay.classList.remove('show');
    api('GET', '/api/admin/users').then(r => { if (r.ok) { users = r.users; renderUsers(users); } });
});
cuOverlay.addEventListener('click', e => {
    if (e.target === e.currentTarget) {
        cuOverlay.classList.remove('show');
        api('GET', '/api/admin/users').then(r => { if (r.ok) { users = r.users; renderUsers(users); } });
    }
});

[cuLogin, cuEmail, cuPassword].forEach(el => el?.addEventListener('keydown', e => {
    if (e.key === 'Enter') document.getElementById('cu-ok').click();
}));

document.getElementById('cu-ok').addEventListener('click', async () => {
    cuError.style.display = 'none';
    cuSuccess.style.display = 'none';

    const login    = cuLogin.value.trim();
    const email    = cuEmail.value.trim();
    const password = cuPassword.value;
    const role     = document.querySelector('input[name="cu-role"]:checked')?.value || 'user';

    if (!login || !email || !password) {
        cuError.textContent = 'Login, email and password are all required.';
        cuError.style.display = '';
        return;
    }

    // SEC-105: step-up re-auth — this endpoint creates an immediately
    // active account, with an attacker-chosen role (including 'admin'), in
    // one request. Modal is hidden while asking and re-shown on
    // cancel/failure so the already-typed fields above are not lost.
    cuOverlay.classList.remove('show');
    const adminPw = await promptReauth('Creating a new account requires confirming your own password. Re-enter your password to continue.');
    if (adminPw === null) { cuOverlay.classList.add('show'); return; }

    const btn = document.getElementById('cu-ok');
    btn.disabled = true; btn.textContent = 'Creating…';

    const r = await api('POST', '/api/admin/create-user', { login, email, password, role, admin_password: adminPw });
    btn.disabled = false; btn.textContent = 'Create another →';

    if (!r.ok) {
        cuOverlay.classList.add('show');
        cuError.textContent = r.error || 'Could not create account.';
        cuError.style.display = '';
        btn.textContent = 'Create account →';
        return;
    }

    cuLogin.value = ''; cuEmail.value = ''; cuPassword.value = '';
    cuStrBar.style.width = '0'; cuStrLabel.textContent = '';
    document.querySelector('input[name="cu-role"][value="user"]').checked = true;

    cuSuccess.textContent = `✓ Account "${r.login}" created (${r.role}). They can log in immediately.`;
    cuSuccess.style.display = '';
    btn.textContent = 'Create account →';
    toast(`Account "${r.login}" created.`, 'success');
});

// ═══════════════════════════════════════════════════════════════════════════════
// CSP Krok 4b — delegated listeners (replaces all inline onXXX= attributes above)
// ═══════════════════════════════════════════════════════════════════════════════

// Static, single-occurrence elements (own avatar + reload button) — simple id-based,
// same pattern as Krok 4a (no list, no re-render, one listener is enough).
document.getElementById('admin-topbar-avatar-img')?.addEventListener('error', function() { this.remove(); });
document.getElementById('btn-reload-page')?.addEventListener('click', () => location.reload());

// avatarHtml() image broken-load fallback — 'error' does NOT bubble, so this MUST be
// attached with useCapture=true on an ancestor to work as delegation (unlike 'click').
// Scoped to #users-tbody since that's the only place avatarHtml() is used.
document.getElementById('users-tbody')?.addEventListener('error', e => {
    const img = e.target.closest('img.admin-avatar-img');
    if (img) img.outerHTML = '<span class="admin-avatar-fallback">👤</span>';
}, true);

// Single global delegated click dispatcher for every dynamically-rendered
// data-action button across Blocked / Users / Sessions / History tables.
// Survives re-renders (tbody.innerHTML replacement) without re-attaching —
// attached once here, on document, regardless of which tab is active.
document.addEventListener('click', e => {
    const btn = e.target.closest('[data-action]');
    if (!btn) return;
    switch (btn.dataset.action) {
        case 'show-history':
            showHistoryFor(btn.dataset.key);
            break;
        case 'unblock':
            doUnblock(btn.dataset.keyHash, btn.dataset.rlAction, btn.dataset.keyPlain);
            break;
        case 'unblock-all':
            doUnblockAll(btn.dataset.keyPlain);
            break;
        case 'delete-user':
            doDeleteUser(parseInt(btn.dataset.userId), btn.dataset.login);
            break;
        case 'force-reset':
            showForceReset(parseInt(btn.dataset.userId), btn.dataset.login);
            break;
        case 'filter-sessions-to-user':
            filterSessionsToUser(parseInt(btn.dataset.userId), btn.dataset.login);
            break;
        case 'delete-session':
            doDeleteSession(btn.dataset.sessionId, btn.dataset.login, btn);
            break;
        case 'delete-user-sessions':
            doDeleteUserSessions(parseInt(btn.dataset.userId), btn.dataset.login);
            break;
        case 'filter-to-ip':
            filterToIp(btn.dataset.ip);
            break;
    }
});

// ── INIT ──────────────────────────────────────────────────────────────────────
renderBlocked(blocked);
renderUsers(users);
renderSessions(sessions);
filterHistory();
renderChecks(checks);
</script>
</body>
</html>
