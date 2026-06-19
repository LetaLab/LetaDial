<?php
/**
 * LetaDial — Settings Page (sesja 058 + 066 + 067 + 071b + 072)
 *
 * Sections:
 *   1. Change Password
 *   2. Change Email      (sesja 066)
 *   3. Two-Factor Auth
 *   4. My Sessions       (sesja 066)
 *   5. UI Preferences
 *   6. Custom Colors     (sesja 071b + 072)
 *   7. About             (sesja 067)
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die();

$user = Auth::requireLogin();

$needs_2fa_setup = ($user['totp_required'] && !$user['totp_enabled'])
                || ($user['role'] === 'admin' && !$user['totp_enabled']);
if ($needs_2fa_setup) { header('Location: /setup-2fa'); exit; }

$app_name        = htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8');
$user_login      = htmlspecialchars($user['login'], ENT_QUOTES, 'UTF-8');
$csrf_token      = CSRF::token();
$pw_rules        = Password::jsRules();
$totp_enabled    = (bool)$user['totp_enabled'];
$is_admin        = ($user['role'] === 'admin');
$recent_disabled = (bool)($user['recent_disabled'] ?? false);
$email_pending   = $user['email_pending'] ?? null;
$has_pending     = !empty($email_pending);
$smtp_enabled    = defined('SMTP_ENABLED') && SMTP_ENABLED;
$current_session_id = Auth::getSessionId();
$app_version     = defined('APP_VERSION') ? APP_VERSION : '—';

// ── Custom Colors per-theme (sesja 071b) ──────────────────────────────────────
$_valid_hex = '/^#[0-9A-Fa-f]{6}$/i';
$custom_colors = [
    'light'    => (preg_match($_valid_hex, $user['theme_light_primary']    ?? '') ? strtolower($user['theme_light_primary'])    : null),
    'dark'     => (preg_match($_valid_hex, $user['theme_dark_primary']     ?? '') ? strtolower($user['theme_dark_primary'])     : null),
    'midnight' => (preg_match($_valid_hex, $user['theme_midnight_primary'] ?? '') ? strtolower($user['theme_midnight_primary']) : null),
];
$custom_colors_json = json_encode($custom_colors, JSON_HEX_TAG);

// ── Custom Extras per-theme (sesja 072) — bg + text colors ───────────────────
$_theme_defaults = [
    'light'    => ['bg' => '#F7F0E8', 'text' => '#2A1210'],
    'dark'     => ['bg' => '#3A2C22', 'text' => '#EEE4DC'],
    'midnight' => ['bg' => '#090B12', 'text' => '#E2E8F8'],
];
$custom_extras = [];
foreach (['light', 'dark', 'midnight'] as $_ctk) {
    $raw = $user['theme_' . $_ctk . '_extra'] ?? null;
    $custom_extras[$_ctk] = ($raw && is_string($raw)) ? json_decode($raw, true) : null;
}
$custom_extras_json = json_encode($custom_extras, JSON_HEX_TAG);

$backup_count = 0;
if ($totp_enabled) {
    $backup_count = (int)(DB::val(
        "SELECT COUNT(*) FROM totp_backup_codes WHERE user_id = ? AND used = 0",
        [$user['id']]
    ) ?? 0);
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function eyeSvg(bool $show): string {
    return $show
        ? '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>'
        : '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Settings — <?= $app_name ?></title>
<link rel="shortcut icon" href="/assets/icons/favicon.png" type="image/png">
<link rel="icon" href="/assets/icons/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
<link rel="manifest" href="/assets/manifest.json">
<link rel="stylesheet" href="/assets/css/design-system.css">
<script>(function(){const t=localStorage.getItem('dv-theme');if(t)document.documentElement.setAttribute('data-theme',t)})();</script>
<style>
body { padding:0; min-height:100vh; background:var(--bg); }
.settings-topbar {
    height:56px; background:var(--surface); border-bottom:1px solid var(--border);
    display:flex; align-items:center; padding:0 1.5rem; gap:1rem;
    position:sticky; top:0; z-index:100; box-shadow:var(--shadow-xs);
}
.settings-topbar-brand { display:flex; align-items:center; gap:.6rem; text-decoration:none; color:var(--text); font-weight:700; font-size:1rem; }
.settings-topbar-brand img { height:32px; width:32px; object-fit:contain; }
.settings-topbar-brand:hover { color:var(--primary); text-decoration:none; }
.settings-topbar-right { margin-left:auto; display:flex; align-items:center; gap:1rem; font-size:.875rem; }
.back-link { color:var(--text-muted); text-decoration:none; transition:color .15s; }
.back-link:hover { color:var(--primary); text-decoration:none; }
.settings-main { max-width:640px; margin:0 auto; padding:2rem 1.5rem 4rem; }
.settings-title { font-size:1.5rem; font-weight:700; margin-bottom:1.75rem; }
.settings-section {
    background:var(--surface); border:1px solid var(--border);
    border-radius:var(--radius-lg); box-shadow:var(--shadow-sm);
    overflow:hidden; margin-bottom:1.5rem;
}
.settings-section-header {
    padding:1.1rem 1.5rem; border-bottom:1px solid var(--border);
    display:flex; align-items:center; gap:.75rem;
}
.settings-section-icon { font-size:1.1rem; }
.settings-section-header h2 { font-size:1rem; font-weight:600; margin:0; flex:1; }
.settings-section-body { padding:1.5rem; }
.field-row { margin-bottom:1.1rem; }
.field-row:last-child { margin-bottom:0; }
.field-hint { font-size:.78rem; color:var(--text-muted); margin-top:.3rem; }
.pw-strength { height:4px; border-radius:9999px; background:var(--border); margin-top:.4rem; overflow:hidden; }
.pw-strength-bar { height:100%; border-radius:9999px; transition:width .3s ease, background .3s ease; width:0; }
.inline-alert { display:none; padding:.6rem .85rem; border-radius:var(--radius-md); font-size:.875rem; margin-bottom:1rem; align-items:flex-start; gap:.5rem; }
.inline-alert.show { display:flex; }
.inline-alert.success { background:var(--success-bg); border:1px solid var(--success-bdr); color:var(--success); }
.inline-alert.error   { background:var(--error-bg);   border:1px solid var(--error-bdr);   color:var(--error);   }
.inline-alert.info    { background:var(--info-bg);     border:1px solid var(--info-bdr);     color:var(--info);    }
.status-badge { display:inline-flex; align-items:center; gap:.35rem; padding:.2rem .65rem; border-radius:9999px; font-size:.78rem; font-weight:600; }
.status-badge.on  { background:var(--success-bg); color:var(--success); border:1px solid var(--success-bdr); }
.status-badge.off { background:var(--error-bg);   color:var(--error);   border:1px solid var(--error-bdr);   }
.backup-grid { display:grid; grid-template-columns:1fr 1fr; gap:.4rem; margin:1rem 0; }
.backup-code-item { background:var(--surface-alt); border:1px solid var(--border); border-radius:var(--radius-sm); padding:.5rem; text-align:center; font-family:var(--font-mono); font-size:.9rem; font-weight:700; color:var(--text); }
.pref-row { display:flex; align-items:center; justify-content:space-between; padding:.6rem 0; border-bottom:1px solid var(--border-light); }
.pref-row:last-child { border-bottom:none; padding-bottom:0; }
.pref-label { font-size:.9rem; color:var(--text); }
.pref-hint  { font-size:.78rem; color:var(--text-muted); margin-top:.15rem; }
.verify-form { display:none; background:var(--surface-alt); border:1px solid var(--border); border-radius:var(--radius-md); padding:1rem; margin-top:1rem; }
.verify-form.show { display:block; }
.code-input { text-align:center; letter-spacing:.25em; font-size:1.4rem; font-weight:700; font-family:var(--font-mono); padding:.75rem; }
.session-list { display:flex; flex-direction:column; gap:.6rem; }
.session-item { display:flex; align-items:flex-start; gap:.75rem; background:var(--surface-alt); border:1px solid var(--border); border-radius:var(--radius-md); padding:.75rem 1rem; }
.session-item.current-session { border-color:var(--primary); background:var(--primary-bg); }
.session-icon { font-size:1.4rem; flex-shrink:0; margin-top:.1rem; }
.session-info { flex:1; min-width:0; }
.session-title { font-size:.875rem; font-weight:600; color:var(--text); }
.session-meta  { font-size:.75rem; color:var(--text-muted); margin-top:.15rem; line-height:1.5; }
.session-badge { font-size:.7rem; background:var(--primary); color:var(--primary-fg); padding:.1rem .5rem; border-radius:9999px; font-weight:700; margin-left:.4rem; }
.session-actions { flex-shrink:0; display:flex; align-items:center; }
.sessions-loading { text-align:center; padding:1.5rem; color:var(--text-faint); font-size:.875rem; }
.email-pending-banner { background:var(--info-bg); border:1px solid var(--info-bdr); border-radius:var(--radius-md); padding:.75rem 1rem; font-size:.875rem; color:var(--info); display:flex; align-items:flex-start; gap:.6rem; margin-bottom:1rem; }
.email-locked { display:flex; align-items:center; gap:.5rem; background:var(--surface-alt); border:1.5px solid var(--border); border-radius:var(--radius-md); padding:.55rem .75rem; font-size:.9rem; color:var(--text-muted); }
.email-locked .email-val { flex:1; font-weight:500; color:var(--text); }
/* About section */
.about-links { display:flex; gap:.75rem; flex-wrap:wrap; margin-bottom:1rem; }
.about-link-btn { display:inline-flex; align-items:center; gap:.5rem; padding:.55rem 1rem; background:var(--surface-alt); border:1.5px solid var(--border); border-radius:var(--radius-md); font-size:.875rem; color:var(--text-muted); text-decoration:none; font-family:var(--font-sans); font-weight:500; transition:all var(--transition); cursor:pointer; }
.about-link-btn:hover { border-color:var(--primary); color:var(--primary); background:var(--primary-bg); text-decoration:none; }
.about-meta { font-size:.78rem; color:var(--text-faint); line-height:1.6; }
/* ── Custom Colors (sesja 071b + 072) ──────────────────────────────────────── */
.color-theme-tabs { display:flex; gap:4px; margin-bottom:1rem; border-bottom:1px solid var(--border); }
.color-theme-tab {
    padding:.45rem 1.1rem; font-size:.875rem; font-family:var(--font-sans); font-weight:500;
    color:var(--text-muted); background:none; border:none; border-bottom:3px solid transparent;
    margin-bottom:-1px; cursor:pointer; transition:color var(--transition),border-color var(--transition);
    white-space:nowrap;
}
.color-theme-tab:hover { color:var(--text); }
.color-theme-tab.active { color:var(--primary); border-bottom-color:var(--primary); font-weight:600; }
.color-tab-pane { display:none; }
.color-tab-pane.active { display:block; animation:colorTabIn .12s ease; }
@keyframes colorTabIn { from{opacity:0;transform:translateY(4px)} to{opacity:1;transform:translateY(0)} }
.color-status {
    display:flex; align-items:center; gap:.6rem; padding:.45rem .75rem;
    background:var(--surface-alt); border:1px solid var(--border); border-radius:var(--radius-sm);
    font-size:.82rem; color:var(--text-muted); margin-bottom:.75rem;
}
.color-status-dot {
    width:16px; height:16px; border-radius:50%; flex-shrink:0;
    border:2px solid rgba(0,0,0,.12); display:inline-block;
}
.color-suggestions { display:flex; flex-wrap:wrap; gap:8px; margin:.75rem 0; }
.color-swatch {
    width:34px; height:34px; border-radius:50%; cursor:pointer;
    border:3px solid transparent; transition:transform .15s,border-color .15s,box-shadow .15s;
    outline:none; flex-shrink:0;
}
.color-swatch:hover  { transform:scale(1.15); box-shadow:0 2px 8px rgba(0,0,0,.2); }
.color-swatch.active { border-color:var(--text); box-shadow:0 0 0 2px var(--text); }
.color-swatch-wrap { position:relative; }
.color-custom-row {
    display:flex; align-items:center; gap:.65rem; flex-wrap:wrap; margin:.75rem 0;
}
.color-picker-input {
    width:38px; height:36px; padding:2px; border-radius:var(--radius-sm);
    border:1.5px solid var(--border); background:var(--surface-alt);
    cursor:pointer; flex-shrink:0;
}
.color-picker-input::-webkit-color-swatch-wrapper { padding:0; }
.color-picker-input::-webkit-color-swatch { border:none; border-radius:3px; }
.color-hex-input { width:100px; font-family:var(--font-mono); font-size:.875rem; text-transform:uppercase; }
.color-preview-row {
    display:flex; align-items:center; gap:.75rem; padding:.6rem .85rem;
    background:var(--surface-alt); border:1px solid var(--border);
    border-radius:var(--radius-md); margin:.75rem 0;
}
.color-preview-label { font-size:.75rem; color:var(--text-faint); flex-shrink:0; }
.color-preview-btn {
    display:inline-flex; align-items:center; gap:.4rem; padding:.4rem .9rem;
    font-size:.8rem; font-weight:600; border-radius:var(--radius-md); border:none;
    cursor:default; transition:background .2s,color .2s;
}
.color-preview-tab {
    display:inline-flex; align-items:center; gap:.4rem; padding:.3rem .75rem;
    font-size:.8rem; font-weight:600; border-radius:var(--radius-sm);
    border-bottom:3px solid currentColor;
}
/* Page colors (bg + text) rows — sesja 072 */
.page-colors-section {
    margin-top:1rem; padding-top:.85rem; border-top:1px solid var(--border);
}
.page-colors-label {
    font-size:.72rem; font-weight:700; color:var(--text-faint);
    text-transform:uppercase; letter-spacing:.06em; margin-bottom:.55rem;
}
.page-color-row {
    display:flex; align-items:center; gap:.6rem; margin-bottom:.4rem;
}
.page-color-row:last-child { margin-bottom:0; }
.page-color-name {
    font-size:.82rem; color:var(--text-muted); min-width:78px; flex-shrink:0;
}
.page-color-hex {
    width:90px; font-family:var(--font-mono); font-size:.84rem;
    text-transform:uppercase;
}
@media (max-width:640px) {
    .settings-main { padding:1.25rem 1rem 3rem; }
    .settings-section-body { padding:1.1rem; }
    .session-item { flex-wrap:wrap; }
    .about-links { flex-direction:column; }
    .page-color-row { flex-wrap:wrap; }
    .page-color-name { min-width:60px; }
}
</style>
</head>
<body>

<header class="settings-topbar">
    <a href="/" class="settings-topbar-brand">
        <img src="/assets/icons/icon-192.png" alt="<?= $app_name ?>">
        <?= $app_name ?>
    </a>
    <div class="settings-topbar-right">
        <span style="color:var(--text-muted);font-size:.875rem">👤 <?= $user_login ?></span>
        <button class="theme-toggle" id="theme-btn">🌙 Dark</button>
        <a href="/" class="back-link">← Dashboard</a>
    </div>
</header>

<main class="settings-main">
    <h1 class="settings-title">Settings</h1>

    <!-- ══ 1: Change Password ══ -->
    <div class="settings-section">
        <div class="settings-section-header">
            <span class="settings-section-icon">🔑</span>
            <h2>Change Password</h2>
        </div>
        <div class="settings-section-body">
            <div class="inline-alert" id="pw-alert"><span id="pw-alert-msg"></span></div>
            <div class="field-row">
                <label class="form-label" for="current-pw">Current password</label>
                <div class="input-wrap">
                    <input type="password" id="current-pw" class="form-input" autocomplete="current-password" placeholder="Enter current password">
                    <button type="button" class="eye-btn" onclick="togglePw('current-pw',this)" aria-label="Show/hide"><?= eyeSvg(true) ?></button>
                </div>
            </div>
            <div class="field-row">
                <label class="form-label" for="new-pw">New password</label>
                <div class="input-wrap">
                    <input type="password" id="new-pw" class="form-input" autocomplete="new-password" placeholder="Min. 12 characters">
                    <button type="button" class="eye-btn" onclick="togglePw('new-pw',this)" aria-label="Show/hide"><?= eyeSvg(true) ?></button>
                </div>
                <div class="pw-strength"><div class="pw-strength-bar" id="pw-strength-bar"></div></div>
                <div class="field-hint" id="pw-strength-label"></div>
            </div>
            <div class="field-row">
                <label class="form-label" for="confirm-pw">Confirm new password</label>
                <div class="input-wrap">
                    <input type="password" id="confirm-pw" class="form-input" autocomplete="new-password" placeholder="Repeat new password">
                    <button type="button" class="eye-btn" onclick="togglePw('confirm-pw',this)" aria-label="Show/hide"><?= eyeSvg(true) ?></button>
                </div>
            </div>
            <button type="button" class="btn btn-primary" id="btn-change-pw">Change password</button>
        </div>
    </div>

    <!-- ══ 2: Change Email ══ -->
    <div class="settings-section">
        <div class="settings-section-header">
            <span class="settings-section-icon">✉️</span>
            <h2>Email Address</h2>
        </div>
        <div class="settings-section-body">
            <div class="inline-alert" id="email-alert"><span id="email-alert-msg"></span></div>
            <div class="field-row">
                <label class="form-label">Current email</label>
                <p style="font-size:.9rem;color:var(--text);padding:.4rem 0"><?= h($user['email']) ?></p>
            </div>
            <?php if ($has_pending): ?>
            <div class="email-pending-banner">
                <span style="flex-shrink:0;font-size:1rem">📨</span>
                <div>
                    <strong>Confirmation pending</strong> — a confirmation email was sent to
                    <strong><?= h($email_pending) ?></strong>.<br>
                    Click the link in that email to apply the change.
                    <?php if (!$smtp_enabled): ?>
                    <br><em style="color:var(--warning)">SMTP is disabled — email was NOT sent. Contact your admin.</em>
                    <?php endif; ?>
                </div>
            </div>
            <button type="button" class="btn btn-ghost btn-sm" id="btn-cancel-email-change" style="margin-bottom:.75rem">✕ Cancel email change</button>
            <?php endif; ?>
            <div id="email-change-form" <?= $has_pending ? 'style="display:none"' : '' ?>>
                <div class="field-row">
                    <label class="form-label" for="new-email">New email address</label>
                    <input type="email" id="new-email" class="form-input" autocomplete="email" placeholder="new@example.com">
                    <?php if (!$smtp_enabled): ?>
                    <div class="field-hint" style="color:var(--warning)">⚠ SMTP not configured — confirmation email cannot be sent.</div>
                    <?php else: ?>
                    <div class="field-hint">A confirmation link will be sent to the new address. Expires in 1 hour.</div>
                    <?php endif; ?>
                </div>
                <button type="button" class="btn btn-primary" id="btn-change-email">Send confirmation email</button>
            </div>
        </div>
    </div>

    <!-- ══ 3: Two-Factor Auth ══ -->
    <div class="settings-section" id="2fa">
        <div class="settings-section-header">
            <span class="settings-section-icon">🔐</span>
            <h2>Two-Factor Authentication</h2>
        </div>
        <div class="settings-section-body">
            <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1rem;flex-wrap:wrap">
                <span>Status:</span>
                <?php if ($totp_enabled): ?>
                <span class="status-badge on">✓ Enabled</span>
                <?php else: ?>
                <span class="status-badge off">✗ Not enabled</span>
                <?php endif; ?>
            </div>
            <?php if ($totp_enabled): ?>
            <p style="font-size:.875rem;color:var(--text-muted);margin-bottom:1rem">
                You have <strong id="backup-count-display"><?= $backup_count ?></strong> unused backup code<?= $backup_count !== 1 ? 's' : ''?> remaining.
                <?php if ($backup_count <= 2): ?><span style="color:var(--warning);font-weight:600">— Running low! Regenerate now.</span><?php endif; ?>
            </p>
            <div class="inline-alert" id="bc-alert"><span id="bc-alert-msg"></span></div>
            <button type="button" class="btn btn-outline" id="btn-regen-bc">↺ Regenerate backup codes</button>
            <div class="verify-form" id="verify-form">
                <p style="font-size:.875rem;color:var(--text-muted);margin-bottom:.75rem">Enter your current 6-digit authenticator code to confirm:</p>
                <div class="field-row">
                    <input type="text" id="bc-code" class="form-input code-input" inputmode="numeric" maxlength="9" placeholder="000000" autocomplete="one-time-code">
                </div>
                <div style="display:flex;gap:.75rem;flex-wrap:wrap">
                    <button type="button" class="btn btn-primary" id="btn-confirm-regen">Confirm regeneration</button>
                    <button type="button" class="btn btn-ghost" id="btn-cancel-regen">Cancel</button>
                </div>
            </div>
            <div id="new-codes-area" style="display:none;margin-top:1.25rem">
                <div class="alert alert-warning" style="margin-bottom:.75rem">
                    <span class="alert-icon">⚠</span>
                    <span>Save these codes now — they will <strong>not</strong> be shown again. Each code can only be used once.</span>
                </div>
                <div class="backup-grid" id="new-codes-grid"></div>
                <div style="margin-top:.75rem;display:flex;gap:.75rem;flex-wrap:wrap">
                    <button type="button" class="btn btn-ghost btn-sm" id="btn-download-codes">↓ Download .txt</button>
                    <button type="button" class="btn btn-ghost btn-sm" id="btn-copy-codes">📋 Copy all</button>
                </div>
            </div>
            <?php else: ?>
            <p style="font-size:.875rem;color:var(--text-muted);margin-bottom:1rem">
                Two-factor authentication adds an extra layer of security.
                <?php if ($is_admin): ?><strong>Admin accounts require 2FA.</strong><?php endif; ?>
            </p>
            <a href="/setup-2fa" class="btn btn-primary">Set up 2FA →</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══ 4: My Sessions ══ -->
    <div class="settings-section">
        <div class="settings-section-header">
            <span class="settings-section-icon">🖥️</span>
            <h2>Active Sessions</h2>
            <button type="button" class="btn btn-ghost btn-sm" id="btn-refresh-sessions" style="margin-left:auto">↻ Refresh</button>
        </div>
        <div class="settings-section-body">
            <div class="inline-alert" id="sess-alert"><span id="sess-alert-msg"></span></div>
            <div id="sessions-list"><div class="sessions-loading">Loading sessions…</div></div>
            <div style="margin-top:1rem;display:flex;justify-content:flex-end">
                <button type="button" class="btn btn-ghost btn-sm" id="btn-signout-all-others" style="border-color:var(--error-bdr);color:var(--error)">
                    Sign out all other devices
                </button>
            </div>
        </div>
    </div>

    <!-- ══ 5: UI Preferences ══ -->
    <div class="settings-section">
        <div class="settings-section-header">
            <span class="settings-section-icon">🎨</span>
            <h2>UI Preferences</h2>
        </div>
        <div class="settings-section-body">
            <div class="pref-row">
                <div>
                    <div class="pref-label">Theme</div>
                    <div class="pref-hint">Saved locally in your browser</div>
                </div>
                <button class="theme-toggle" id="theme-btn-pref">🌙 Dark</button>
            </div>
            <div class="pref-row">
                <div>
                    <div class="pref-label">🕐 Recent tab</div>
                    <div class="pref-hint">Hide the Recently Used tab from the groups bar</div>
                </div>
                <label style="display:inline-flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.875rem;color:var(--text-muted);user-select:none">
                    <input type="checkbox" id="pref-recent-disabled" <?= $recent_disabled ? 'checked' : '' ?> style="accent-color:var(--primary);width:16px;height:16px;cursor:pointer">
                    Hide Recent
                </label>
            </div>
            <div class="pref-row">
                <div>
                    <div class="pref-label">Account</div>
                    <div class="pref-hint">Logged in as <strong><?= $user_login ?></strong></div>
                </div>
                <form method="post" action="/logout" style="margin:0">
                    <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
                    <button type="submit" class="btn btn-ghost btn-sm" style="border-color:var(--error-bdr);color:var(--error)">Sign out</button>
                </form>
            </div>
        </div>
    </div>

    <!-- ══ 6: Custom Colors (sesja 071b + 072) ══ -->
    <div class="settings-section">
        <div class="settings-section-header">
            <span class="settings-section-icon">🎨</span>
            <h2>Custom Colors</h2>
        </div>
        <div class="settings-section-body">
            <p class="field-hint" style="margin-bottom:1rem">
                Customize accent, background, and text colors per theme.
                Changes apply immediately — save to make them permanent.
            </p>

            <!-- Theme tabs -->
            <div class="color-theme-tabs">
                <button class="color-theme-tab active" data-ctab="light">☀ Light</button>
                <button class="color-theme-tab" data-ctab="dark">🌙 Dark</button>
                <button class="color-theme-tab" data-ctab="midnight">🌑 Midnight</button>
            </div>

            <?php foreach (['light','dark','midnight'] as $_ct):
                $_pal = [
                    'light'    => [
                        ['#690b22','Burgundy (default)'],['#1e40af','Royal Blue'],
                        ['#047857','Emerald'],['#b45309','Amber'],
                        ['#6d28d9','Violet'],['#be185d','Rose'],
                    ],
                    'dark'     => [
                        ['#e05070','Rose (default)'],['#60a8f8','Sky Blue'],
                        ['#34d399','Emerald'],['#fbbf24','Amber'],
                        ['#a78bfa','Violet'],['#f472b6','Pink'],
                    ],
                    'midnight' => [
                        ['#ff6b8a','Rose (default)'],['#62b0ff','Sky Blue'],
                        ['#44d490','Emerald'],['#fbbf24','Amber'],
                        ['#c4b5fd','Lavender'],['#fca5a5','Salmon'],
                    ],
                ];
                $_cur    = $custom_colors[$_ct];
                $_has    = !empty($_cur);
                $_ext    = $custom_extras[$_ct];
                $_ext_bg = $_ext['bg']   ?? null;
                $_ext_tx = $_ext['text'] ?? null;
                $_def_bg = $_theme_defaults[$_ct]['bg'];
                $_def_tx = $_theme_defaults[$_ct]['text'];
            ?>
            <div class="color-tab-pane<?= $_ct === 'light' ? ' active' : '' ?>"
                 id="ctab-<?= $_ct ?>">

                <!-- Primary color status -->
                <div class="color-status">
                    <?php if ($_has): ?>
                    <span class="color-status-dot" style="background:<?= h($_cur) ?>"></span>
                    <span>Custom accent: <strong><?= h(strtoupper($_cur)) ?></strong></span>
                    <?php else: ?>
                    <span>Using default accent color for this theme</span>
                    <?php endif; ?>
                </div>

                <!-- Accent color label -->
                <div style="font-size:.72rem;font-weight:700;color:var(--text-faint);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.5rem">Accent Color</div>

                <!-- Palette suggestions -->
                <div class="color-suggestions" id="suggestions-<?= $_ct ?>">
                    <?php foreach ($_pal[$_ct] as [$_ph, $_pl]): ?>
                    <div class="color-swatch-wrap">
                        <button type="button"
                            class="color-swatch<?= ($_has && strtolower($_ph) === $_cur) ? ' active' : '' ?>"
                            data-cswatch="<?= $_ct ?>"
                            data-hex="<?= h($_ph) ?>"
                            title="<?= h($_pl) ?>"
                            style="background:<?= h($_ph) ?>"
                            aria-label="<?= h($_pl) ?>"></button>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Custom color row -->
                <div class="color-custom-row">
                    <input type="color" id="color-picker-<?= $_ct ?>"
                           class="color-picker-input"
                           value="<?= h($_has ? $_cur : '#690b22') ?>"
                           title="Open color picker">
                    <input type="text" id="color-hex-<?= $_ct ?>"
                           class="form-input color-hex-input"
                           maxlength="7"
                           placeholder="#rrggbb"
                           value="<?= h($_has ? strtoupper($_cur) : '') ?>"
                           autocomplete="off"
                           spellcheck="false">
                    <button type="button" class="btn btn-ghost btn-sm"
                            id="color-reset-<?= $_ct ?>"
                            title="Reset to theme default">↺ Reset</button>
                </div>

                <!-- Mini preview -->
                <div class="color-preview-row">
                    <span class="color-preview-label">Preview:</span>
                    <button type="button" class="color-preview-btn"
                            id="preview-btn-<?= $_ct ?>"
                            style="background:<?= h($_has ? $_cur : 'var(--primary)') ?>;color:<?php
                                if ($_has) {
                                    $r=hexdec(substr($_cur,1,2));$g=hexdec(substr($_cur,3,2));$b=hexdec(substr($_cur,5,2));
                                    echo ((0.299*$r+0.587*$g+0.114*$b)/255)>0.55?'#000000':'#ffffff';
                                } else { echo 'var(--primary-fg)'; }
                            ?>">Button</button>
                    <span class="color-preview-tab"
                          id="preview-tab-<?= $_ct ?>"
                          style="color:<?= h($_has ? $_cur : 'var(--primary)') ?>;border-bottom-color:<?= h($_has ? $_cur : 'var(--primary)') ?>">
                        Active Tab
                    </span>
                </div>

                <!-- Page Colors: BG + Text (sesja 072) — two clean rows -->
                <div class="page-colors-section">
                    <div class="page-colors-label">Page Colors</div>

                    <div class="page-color-row">
                        <span class="page-color-name">Background</span>
                        <input type="color" id="color-bg-picker-<?= $_ct ?>"
                               class="color-picker-input"
                               value="<?= h($_ext_bg ?? $_def_bg) ?>"
                               title="Background color">
                        <input type="text" id="color-bg-hex-<?= $_ct ?>"
                               class="form-input page-color-hex"
                               maxlength="7" placeholder="Default"
                               value="<?= h($_ext_bg ? strtoupper($_ext_bg) : '') ?>"
                               autocomplete="off" spellcheck="false">
                        <button type="button" id="color-bg-reset-<?= $_ct ?>"
                                class="btn btn-ghost btn-sm" title="Reset to default">Default</button>
                    </div>

                    <div class="page-color-row">
                        <span class="page-color-name">Text</span>
                        <input type="color" id="color-text-picker-<?= $_ct ?>"
                               class="color-picker-input"
                               value="<?= h($_ext_tx ?? $_def_tx) ?>"
                               title="Text color">
                        <input type="text" id="color-text-hex-<?= $_ct ?>"
                               class="form-input page-color-hex"
                               maxlength="7" placeholder="Default"
                               value="<?= h($_ext_tx ? strtoupper($_ext_tx) : '') ?>"
                               autocomplete="off" spellcheck="false">
                        <button type="button" id="color-text-reset-<?= $_ct ?>"
                                class="btn btn-ghost btn-sm" title="Reset to default">Default</button>
                    </div>
                </div>

            </div>
            <?php endforeach; ?>

            <div class="inline-alert" id="color-alert" style="margin-top:.75rem">
                <span id="color-alert-msg"></span>
            </div>
            <button type="button" class="btn btn-primary" id="btn-save-color" style="margin-top:.25rem">
                Save for Light theme
            </button>
        </div>
    </div>

    <!-- ══ 7: About ══ -->
    <div class="settings-section">
        <div class="settings-section-header">
            <span class="settings-section-icon">ℹ️</span>
            <h2>About LetaDial</h2>
        </div>
        <div class="settings-section-body">
            <div class="about-links">
                <a href="https://github.com/LetaLab/LetaDial/issues"
                   target="_blank" rel="noopener noreferrer"
                   class="about-link-btn">
                    🐛 Report a bug
                </a>
                <a href="https://LetaLab.eu"
                   target="_blank" rel="noopener noreferrer"
                   class="about-link-btn">
                    🌐 LetaLab Project Homepage
                </a>
            </div>
            <div class="about-meta">
                <strong><?= $app_name ?></strong> v<?= h($app_version) ?>
                &nbsp;·&nbsp; Open source, self-hosted speed dial
                &nbsp;·&nbsp; <a href="https://github.com/LetaLab/LetaDial" target="_blank" rel="noopener noreferrer" style="color:var(--text-faint)">github.com/LetaLab/LetaDial</a>
            </div>
        </div>
    </div>

</main>

<script>
(function(){const t=localStorage.getItem('dv-theme');if(t)document.documentElement.setAttribute('data-theme',t)})();

const CSRF_TOKEN      = <?= json_encode($csrf_token) ?>;
const PW_RULES        = <?= $pw_rules ?>;
const CURRENT_SESSION = <?= json_encode($current_session_id) ?>;

// ── Color state — outer scope (shared by theme switcher + color picker IIFE) ──
let customColors = <?= $custom_colors_json ?>;
let customExtras = <?= $custom_extras_json ?>;

// ── Color manipulation helpers ────────────────────────────────────────────────
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
function _applyCustomColorForTheme(t) {
    const hex = customColors[t];
    if (hex && /^#[0-9A-Fa-f]{6}$/.test(hex)) { _setCssVars(hex); }
    else { _clearCssVars(); }
    const extra = customExtras[t];
    if (extra && (extra.bg || extra.text)) { _setExtraCssVars(extra.bg || null, extra.text || null); }
    else { _clearExtraCssVars(); }
}

// ── sesja 071a: 3-theme cycle Light → Dark → Midnight → Light ────────────────
const THEMES_ORDER  = ['light', 'dark', 'midnight'];
const THEME_LABELS  = { light: '🌙 Dark', dark: '🌑 Midnight', midnight: '☀ Light' };
function nextTheme(t) {
    const idx = THEMES_ORDER.indexOf(t);
    return THEMES_ORDER[(idx + 1) % THEMES_ORDER.length];
}
async function applyTheme(t, save) {
    if (!THEMES_ORDER.includes(t)) t = 'light';
    document.documentElement.setAttribute('data-theme', t);
    _applyCustomColorForTheme(t);
    if (save) {
        localStorage.setItem('dv-theme', t);
        try {
            await fetch('/api/settings/theme', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                credentials: 'same-origin',
                body: JSON.stringify({ theme: t })
            });
        } catch {}
    }
    const label = THEME_LABELS[t] || '🌙 Dark';
    document.querySelectorAll('#theme-btn, #theme-btn-pref').forEach(b => b.textContent = label);
}
applyTheme(localStorage.getItem('dv-theme') || 'light', false);
document.querySelectorAll('#theme-btn, #theme-btn-pref').forEach(btn =>
    btn.addEventListener('click', () => {
        const cur = document.documentElement.getAttribute('data-theme') || 'light';
        applyTheme(nextTheme(cur), true);
    })
);

// ── Shared utilities ──────────────────────────────────────────────────────────
function togglePw(id, btn) {
    const i = document.getElementById(id);
    const showing = i.type === 'text';
    i.type = showing ? 'password' : 'text';
    btn.innerHTML = showing ? '<?= addslashes(eyeSvg(true)) ?>' : '<?= addslashes(eyeSvg(false)) ?>';
}

const pwInput = document.getElementById('new-pw');
const pwBar   = document.getElementById('pw-strength-bar');
const pwLabel = document.getElementById('pw-strength-label');
const levels    = ['','Too short','Weak','Fair','Strong'];
const levelClrs = ['','#E53E3E','#D69E2E','#D69E2E','#1D5C42'];
const levelPct  = ['','25%','50%','75%','100%'];

function calcStrength(pw) {
    if (!pw || pw.length < PW_RULES.minLength) return 1;
    let s = 0;
    if (/[A-Z]/.test(pw)) s++; if (/[a-z]/.test(pw)) s++;
    if (/[0-9]/.test(pw)) s++; if (/[^A-Za-z0-9]/.test(pw)) s++;
    if (pw.length >= 16) s = Math.min(s+1, 4);
    return Math.max(1, Math.min(s, 4));
}
pwInput?.addEventListener('input', function() {
    const pw = this.value;
    if (!pw) { pwBar.style.width='0'; pwLabel.textContent=''; return; }
    const lvl = calcStrength(pw);
    pwBar.style.width = levelPct[lvl]; pwBar.style.background = levelClrs[lvl];
    pwLabel.textContent = levels[lvl]; pwLabel.style.color = levelClrs[lvl];
});

async function apiPost(path, body) {
    try {
        const res = await fetch(path, {
            method:'POST',
            headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF_TOKEN},
            credentials:'same-origin',
            body:JSON.stringify(body)
        });
        return await res.json();
    } catch(e) { return {ok:false,error:'Network error.'}; }
}
async function apiGet(path) {
    try {
        const res = await fetch(path, {
            headers:{'X-CSRF-Token':CSRF_TOKEN},
            credentials:'same-origin'
        });
        return await res.json();
    } catch(e) { return {ok:false,error:'Network error.'}; }
}

function showAlert(id, msgId, type, msg) {
    const el=document.getElementById(id); const mel=document.getElementById(msgId);
    if(!el||!mel) return;
    el.className='inline-alert show '+type; mel.textContent=msg;
    el.scrollIntoView({block:'nearest',behavior:'smooth'});
}
function hideAlert(id) { const el=document.getElementById(id); if(el) el.className='inline-alert'; }

// ── Change Password ───────────────────────────────────────────────────────────
document.getElementById('btn-change-pw')?.addEventListener('click', async () => {
    hideAlert('pw-alert');
    const current=document.getElementById('current-pw')?.value;
    const newPw=document.getElementById('new-pw')?.value;
    const confirm=document.getElementById('confirm-pw')?.value;
    if(!current||!newPw||!confirm){showAlert('pw-alert','pw-alert-msg','error','All fields are required.');return;}
    if(newPw!==confirm){showAlert('pw-alert','pw-alert-msg','error','New passwords do not match.');return;}
    const btn=document.getElementById('btn-change-pw');
    btn.disabled=true;btn.textContent='…';
    const r=await apiPost('/api/settings/password',{current_password:current,new_password:newPw,confirm_password:confirm});
    btn.disabled=false;btn.textContent='Change password';
    if(!r.ok){showAlert('pw-alert','pw-alert-msg','error',r.error||'Could not change password.');return;}
    showAlert('pw-alert','pw-alert-msg','success','Password changed. Redirecting to login…');
    ['current-pw','new-pw','confirm-pw'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
    document.getElementById('pw-strength-bar').style.width='0';
    document.getElementById('pw-strength-label').textContent='';
    setTimeout(()=>{window.location.href='/login';},2500);
});

// ── Change Email ──────────────────────────────────────────────────────────────
document.getElementById('btn-change-email')?.addEventListener('click', async () => {
    hideAlert('email-alert');
    const newEmail=document.getElementById('new-email')?.value?.trim();
    if(!newEmail){showAlert('email-alert','email-alert-msg','error','Please enter a new email address.');return;}
    const btn=document.getElementById('btn-change-email');
    btn.disabled=true;btn.textContent='…';
    const r=await apiPost('/api/settings/email',{new_email:newEmail});
    btn.disabled=false;btn.textContent='Send confirmation email';
    if(!r.ok){showAlert('email-alert','email-alert-msg','error',r.error||'Could not send confirmation email.');return;}
    if(r.smtp_enabled&&r.email_sent){
        showAlert('email-alert','email-alert-msg','success','Confirmation email sent to '+newEmail+'.');
    }else{
        showAlert('email-alert','email-alert-msg','info','Email change recorded, but SMTP is disabled. Contact your admin.');
    }
    setTimeout(()=>location.reload(),3000);
});
document.getElementById('btn-cancel-email-change')?.addEventListener('click',async()=>{
    const r=await apiPost('/api/settings/email/cancel',{});
    if(!r.ok){showAlert('email-alert','email-alert-msg','error',r.error||'Could not cancel.');return;}
    location.reload();
});

// ── Recent tab toggle ─────────────────────────────────────────────────────────
document.getElementById('pref-recent-disabled')?.addEventListener('change',async function(){
    const disabled=this.checked;
    const r=await apiPost('/api/settings/recent',{disabled});
    if(!r.ok){showAlert('pw-alert','pw-alert-msg','error',r.error||'Could not update.');this.checked=!disabled;return;}
    showAlert('pw-alert','pw-alert-msg','success',disabled?'Recent tab hidden. Reload the dashboard to apply.':'Recent tab restored. Reload the dashboard to apply.');
});

// ── 2FA Backup codes ──────────────────────────────────────────────────────────
const regenBtn=document.getElementById('btn-regen-bc');
const verifyForm=document.getElementById('verify-form');
const cancelBtn=document.getElementById('btn-cancel-regen');
const confirmBtn=document.getElementById('btn-confirm-regen');
const bcCodeInput=document.getElementById('bc-code');
const newCodesArea=document.getElementById('new-codes-area');

regenBtn?.addEventListener('click',()=>{verifyForm?.classList.add('show');if(newCodesArea)newCodesArea.style.display='none';hideAlert('bc-alert');bcCodeInput?.focus();regenBtn.style.display='none';});
cancelBtn?.addEventListener('click',()=>{verifyForm?.classList.remove('show');if(bcCodeInput)bcCodeInput.value='';regenBtn.style.display='';hideAlert('bc-alert');});
bcCodeInput?.addEventListener('input',function(){this.value=this.value.replace(/[^0-9A-Fa-f\-]/g,'');if(this.value.replace(/\D/g,'').length===6||this.value.length===9)confirmBtn?.click();});
confirmBtn?.addEventListener('click',async()=>{
    hideAlert('bc-alert');
    const code=bcCodeInput?.value?.trim();
    if(!code){showAlert('bc-alert','bc-alert-msg','error','Please enter your 2FA code.');return;}
    confirmBtn.disabled=true;confirmBtn.textContent='…';
    const r=await apiPost('/api/settings/backup-codes',{code});
    confirmBtn.disabled=false;confirmBtn.textContent='Confirm regeneration';
    if(!r.ok){showAlert('bc-alert','bc-alert-msg','error',r.error||'Could not regenerate codes.');if(bcCodeInput)bcCodeInput.value='';return;}
    verifyForm?.classList.remove('show');if(bcCodeInput)bcCodeInput.value='';
    const grid=document.getElementById('new-codes-grid');
    if(grid){grid.innerHTML='';(r.backup_codes||[]).forEach(c=>{const el=document.createElement('div');el.className='backup-code-item';el.textContent=c;grid.appendChild(el);});}
    if(newCodesArea)newCodesArea.style.display='';
    regenBtn.style.display='';
    const countEl=document.getElementById('backup-count-display');
    if(countEl)countEl.textContent=(r.backup_codes||[]).length;
    showAlert('bc-alert','bc-alert-msg','success','10 new backup codes generated.');
    if(newCodesArea)newCodesArea.scrollIntoView({block:'nearest',behavior:'smooth'});
    document.getElementById('btn-download-codes')?.addEventListener('click',()=>downloadCodes(r.backup_codes));
    document.getElementById('btn-copy-codes')?.addEventListener('click',()=>{
        navigator.clipboard?.writeText((r.backup_codes||[]).join('\n')).then(()=>{
            const btn=document.getElementById('btn-copy-codes');const orig=btn.textContent;btn.textContent='✓ Copied!';setTimeout(()=>btn.textContent=orig,2000);
        });
    });
});

function downloadCodes(codes) {
    const appName=<?= json_encode(APP_NAME) ?>;
    const now=new Date().toISOString().slice(0,19).replace('T',' ');
    const lines=(codes||[]).map((c,i)=>'  '+String(i+1).padStart(2,' ')+'.  '+c);
    const content=['===========================================','  '+appName+' — 2FA Backup Codes','  Generated: '+now,'===========================================','IMPORTANT: Each code can only be used ONCE.','','', ...lines,''].join('\n');
    const blob=new Blob([content],{type:'text/plain'});
    const url=URL.createObjectURL(blob);
    const a=document.createElement('a');a.href=url;a.download=appName.replace(/[^a-z0-9_-]/gi,'_')+'_backup_codes.txt';
    document.body.appendChild(a);a.click();document.body.removeChild(a);URL.revokeObjectURL(url);
}

// ── Sessions ──────────────────────────────────────────────────────────────────
function parseUA(ua) {
    if(!ua)return{browser:'Unknown',os:'Unknown',icon:'🖥️'};
    let b='Unknown',o='Unknown',icon='🖥️';
    if(/EdgA?\//.test(ua))b='Edge';else if(/OPR\//.test(ua))b='Opera';else if(/Chrome\//.test(ua))b='Chrome';else if(/Safari\//.test(ua)&&/Version\//.test(ua))b='Safari';else if(/Firefox\//.test(ua))b='Firefox';
    if(/Windows NT/.test(ua)){o='Windows';icon='🖥️';}else if(/Macintosh/.test(ua)){o='macOS';icon='🍎';}else if(/Android/.test(ua)){o='Android';icon='📱';}else if(/iPhone|iPad/.test(ua)){o='iOS';icon='📱';}else if(/Linux/.test(ua)){o='Linux';icon='🐧';}
    return{browser:b,os:o,icon};
}
function relTime(s){
    if(!s)return'—';
    const d=new Date(s.replace(' ','T'));const diff=Math.floor((Date.now()-d.getTime())/1000);
    if(diff<60)return`${diff}s ago`;if(diff<3600)return`${Math.floor(diff/60)}m ago`;if(diff<86400)return`${Math.floor(diff/3600)}h ago`;return`${Math.floor(diff/86400)}d ago`;
}
function esc(s){return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

async function loadSessions(){
    const list=document.getElementById('sessions-list');
    if(!list)return;
    list.innerHTML='<div class="sessions-loading">Loading…</div>';
    const r=await apiGet('/api/settings/sessions');
    if(!r.ok||!r.sessions){list.innerHTML='<div class="sessions-loading">Could not load sessions.</div>';return;}
    if(!r.sessions.length){list.innerHTML='<div class="sessions-loading">No active sessions found.</div>';return;}
    const html=r.sessions.map(s=>{
        const ua=parseUA(s.user_agent);
        const isCur=s.id===CURRENT_SESSION;
        const badge=isCur?'<span class="session-badge">This device</span>':'';
        const delBtn=isCur?`<span style="font-size:.75rem;color:var(--text-faint)">current</span>`
            :`<button type="button" class="btn btn-ghost btn-sm" style="border-color:var(--error-bdr);color:var(--error)" onclick="deleteSession('${esc(s.id)}',this)">Sign out</button>`;
        return `<div class="session-item${isCur?' current-session':''}">
            <div class="session-icon">${esc(ua.icon)}</div>
            <div class="session-info">
                <div class="session-title">${esc(ua.browser)} on ${esc(ua.os)}${badge}</div>
                <div class="session-meta">IP: <strong>${esc(s.ip)}</strong> · Last active: ${relTime(s.last_activity)} · Signed in: ${relTime(s.created_at)}</div>
            </div>
            <div class="session-actions">${delBtn}</div>
        </div>`;
    }).join('');
    list.innerHTML=`<div class="session-list">${html}</div>`;
}

async function deleteSession(sessionId, btn){
    btn.disabled=true;btn.textContent='…';
    const r=await apiPost('/api/settings/sessions/delete',{session_id:sessionId});
    if(!r.ok){btn.disabled=false;btn.textContent='Sign out';showAlert('sess-alert','sess-alert-msg','error',r.error||'Could not sign out session.');return;}
    showAlert('sess-alert','sess-alert-msg','success','Session signed out.');loadSessions();
}

document.getElementById('btn-refresh-sessions')?.addEventListener('click',()=>{hideAlert('sess-alert');loadSessions();});
document.getElementById('btn-signout-all-others')?.addEventListener('click',async()=>{
    const btn=document.getElementById('btn-signout-all-others');
    btn.disabled=true;btn.textContent='…';
    const r=await apiPost('/api/settings/sessions/delete-all',{});
    btn.disabled=false;btn.textContent='Sign out all other devices';
    if(!r.ok){showAlert('sess-alert','sess-alert-msg','error',r.error||'Could not sign out other sessions.');return;}
    const n=r.deleted||0;
    showAlert('sess-alert','sess-alert-msg','success',n===0?'No other sessions to sign out.':`${n} other session${n!==1?'s':''} signed out.`);
    loadSessions();
});

loadSessions();

// ═══════════════════════════════════════════════════════════════════════════════
// COLOR PICKER (sesja 071b + 072)
// ═══════════════════════════════════════════════════════════════════════════════
(function() {
    const TABS = ['light', 'dark', 'midnight'];
    const TAB_LABELS = { light: 'Light', dark: 'Dark', midnight: 'Midnight' };

    let activeTab = 'light';

    // Pending state — changes visible immediately, persisted only on Save
    let pendingColors = { ...customColors };
    let pendingBg   = {
        light:    customExtras?.light?.bg     ?? null,
        dark:     customExtras?.dark?.bg      ?? null,
        midnight: customExtras?.midnight?.bg  ?? null,
    };
    let pendingText = {
        light:    customExtras?.light?.text    ?? null,
        dark:     customExtras?.dark?.text     ?? null,
        midnight: customExtras?.midnight?.text ?? null,
    };

    // ── Tab switching ─────────────────────────────────────────────────────────
    document.querySelectorAll('.color-theme-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            activeTab = btn.dataset.ctab;
            document.querySelectorAll('.color-theme-tab').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            document.querySelectorAll('.color-tab-pane').forEach(p => p.classList.remove('active'));
            document.getElementById('ctab-' + activeTab)?.classList.add('active');
            updateSaveBtn();
        });
    });

    // ── Per-tab event listeners ───────────────────────────────────────────────
    TABS.forEach(tab => {

        // Accent: palette swatches
        document.querySelectorAll(`[data-cswatch="${tab}"]`).forEach(btn => {
            btn.addEventListener('click', () => setPendingColor(tab, btn.dataset.hex));
        });

        // Accent: color picker
        const picker = document.getElementById('color-picker-' + tab);
        picker?.addEventListener('input', function() { setPendingColor(tab, this.value.toLowerCase()); });

        // Accent: hex input
        const hexInput = document.getElementById('color-hex-' + tab);
        hexInput?.addEventListener('input', function() {
            let val = this.value.trim();
            if (!val.startsWith('#')) val = '#' + val;
            if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
                setPendingColor(tab, val.toLowerCase(), false);
                if (picker) picker.value = val.toLowerCase();
                updateSwatchSelection(tab, val.toLowerCase());
                applyPreview(tab, val.toLowerCase());
            }
        });
        hexInput?.addEventListener('blur', function() {
            this.value = pendingColors[tab] ? pendingColors[tab].toUpperCase() : '';
        });

        // Accent: reset
        document.getElementById('color-reset-' + tab)?.addEventListener('click', () => setPendingColor(tab, null));

        // BG: color picker
        const bgPicker = document.getElementById('color-bg-picker-' + tab);
        bgPicker?.addEventListener('input', function() { setPendingBg(tab, this.value.toLowerCase()); });

        // BG: hex input
        const bgHex = document.getElementById('color-bg-hex-' + tab);
        bgHex?.addEventListener('input', function() {
            let val = this.value.trim();
            if (val && !val.startsWith('#')) val = '#' + val;
            if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
                if (bgPicker) bgPicker.value = val.toLowerCase();
                setPendingBg(tab, val.toLowerCase(), false);
            } else if (!val) {
                setPendingBg(tab, null, false);
            }
        });
        bgHex?.addEventListener('blur', function() {
            this.value = pendingBg[tab] ? pendingBg[tab].toUpperCase() : '';
        });

        // BG: reset
        document.getElementById('color-bg-reset-' + tab)?.addEventListener('click', () => setPendingBg(tab, null));

        // Text: color picker
        const textPicker = document.getElementById('color-text-picker-' + tab);
        textPicker?.addEventListener('input', function() { setPendingText(tab, this.value.toLowerCase()); });

        // Text: hex input
        const textHex = document.getElementById('color-text-hex-' + tab);
        textHex?.addEventListener('input', function() {
            let val = this.value.trim();
            if (val && !val.startsWith('#')) val = '#' + val;
            if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
                if (textPicker) textPicker.value = val.toLowerCase();
                setPendingText(tab, val.toLowerCase(), false);
            } else if (!val) {
                setPendingText(tab, null, false);
            }
        });
        textHex?.addEventListener('blur', function() {
            this.value = pendingText[tab] ? pendingText[tab].toUpperCase() : '';
        });

        // Text: reset
        document.getElementById('color-text-reset-' + tab)?.addEventListener('click', () => setPendingText(tab, null));
    });

    // ── Pending color setters ─────────────────────────────────────────────────
    function setPendingColor(tab, hex, updateHexInput = true) {
        pendingColors[tab] = hex;
        if (updateHexInput) {
            const el = document.getElementById('color-hex-' + tab);
            if (el) el.value = hex ? hex.toUpperCase() : '';
        }
        if (hex) {
            const p = document.getElementById('color-picker-' + tab);
            if (p) p.value = hex;
        }
        updateSwatchSelection(tab, hex);
        applyPreview(tab, hex);
        const cur = document.documentElement.getAttribute('data-theme') || 'light';
        if (tab === cur) {
            customColors[tab] = hex;
            _setCssVars(hex || getDefaultPrimary(tab));
            if (!hex) _clearCssVars();
        }
    }

    function setPendingBg(tab, hex, updateInput = true) {
        pendingBg[tab] = hex || null;
        if (updateInput) {
            const el = document.getElementById('color-bg-hex-' + tab);
            if (el) el.value = hex ? hex.toUpperCase() : '';
        }
        if (hex) {
            const p = document.getElementById('color-bg-picker-' + tab);
            if (p) p.value = hex;
        }
        _applyExtrasLive(tab);
    }

    function setPendingText(tab, hex, updateInput = true) {
        pendingText[tab] = hex || null;
        if (updateInput) {
            const el = document.getElementById('color-text-hex-' + tab);
            if (el) el.value = hex ? hex.toUpperCase() : '';
        }
        if (hex) {
            const p = document.getElementById('color-text-picker-' + tab);
            if (p) p.value = hex;
        }
        _applyExtrasLive(tab);
    }

    // Apply pending bg/text to CSS vars — only if this tab is the current theme
    function _applyExtrasLive(tab) {
        const cur = document.documentElement.getAttribute('data-theme') || 'light';
        if (tab !== cur) return;
        const bg   = pendingBg[tab];
        const text = pendingText[tab];
        if (bg || text) { _setExtraCssVars(bg, text); }
        else            { _clearExtraCssVars(); }
    }

    // ── Preview helpers ───────────────────────────────────────────────────────
    function updateSwatchSelection(tab, hex) {
        document.querySelectorAll(`[data-cswatch="${tab}"]`).forEach(btn => {
            btn.classList.toggle('active', !!(hex && btn.dataset.hex.toLowerCase() === hex.toLowerCase()));
        });
    }

    function applyPreview(tab, hex) {
        const previewBtn = document.getElementById('preview-btn-' + tab);
        const previewTab = document.getElementById('preview-tab-' + tab);
        if (!previewBtn && !previewTab) return;
        const resolvedHex = hex || getDefaultPrimary(tab);
        const fg = _contrastFg(resolvedHex);
        if (previewBtn) { previewBtn.style.background = resolvedHex; previewBtn.style.color = fg; }
        if (previewTab) { previewTab.style.color = resolvedHex; previewTab.style.borderBottomColor = resolvedHex; }
    }

    function getDefaultPrimary(tab) {
        return { light: '#690b22', dark: '#e05070', midnight: '#ff6b8a' }[tab] || '#690b22';
    }

    function updateSaveBtn() {
        const btn = document.getElementById('btn-save-color');
        if (btn) btn.textContent = 'Save for ' + TAB_LABELS[activeTab] + ' theme';
    }

    // ── Save ──────────────────────────────────────────────────────────────────
    document.getElementById('btn-save-color')?.addEventListener('click', async () => {
        const tab   = activeTab;
        const color = pendingColors[tab] || null;
        const bg    = pendingBg[tab]    || null;
        const text  = pendingText[tab]  || null;

        const btn = document.getElementById('btn-save-color');
        btn.disabled = true; btn.textContent = '…';

        const r  = await apiPost('/api/settings/primary-color', { theme: tab, color });
        const r2 = await apiPost('/api/settings/theme-extras',  { theme: tab, bg, text });

        btn.disabled = false; updateSaveBtn();

        if (!r.ok || !r2.ok) {
            showAlert('color-alert', 'color-alert-msg', 'error', r.error || r2.error || 'Could not save colors.');
            return;
        }

        // Commit saved values to state
        customColors[tab]  = r.color;
        pendingColors[tab] = r.color;
        customExtras[tab]  = { bg: r2.bg, text: r2.text };
        pendingBg[tab]     = r2.bg;
        pendingText[tab]   = r2.text;

        const cur = document.documentElement.getAttribute('data-theme') || 'light';
        if (tab === cur) _applyCustomColorForTheme(tab);

        showAlert('color-alert', 'color-alert-msg', 'success', `Colors saved for ${TAB_LABELS[tab]} theme.`);

        // Update status bar
        const statusEl = document.querySelector(`#ctab-${tab} .color-status`);
        if (statusEl) {
            if (r.color) {
                statusEl.innerHTML = `<span class="color-status-dot" style="background:${r.color}"></span><span>Custom accent: <strong>${r.color.toUpperCase()}</strong></span>`;
            } else {
                statusEl.innerHTML = `<span>Using default accent color for this theme</span>`;
            }
        }
    });

    // ── Init ──────────────────────────────────────────────────────────────────
    TABS.forEach(tab => applyPreview(tab, pendingColors[tab]));
    updateSaveBtn();
    const ct = document.documentElement.getAttribute('data-theme') || 'light';
    _applyCustomColorForTheme(ct);

})();
</script>
</body>
</html>
