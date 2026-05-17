<?php
/**
 * LetaDial — Settings Page (sesja 058)
 *
 * Sections:
 *   1. Change Password   → POST /api/settings/password
 *   2. Two-Factor Auth   → POST /api/settings/backup-codes  (id="2fa" for dashboard link)
 *   3. UI Preferences    → localStorage only (theme)
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die();

$user = Auth::requireLogin();

// Admin MUST have 2FA set up before accessing any page
$needs_2fa_setup = ($user['totp_required'] && !$user['totp_enabled'])
                || ($user['role'] === 'admin' && !$user['totp_enabled']);
if ($needs_2fa_setup) { header('Location: /setup-2fa'); exit; }

$app_name   = htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8');
$user_login = htmlspecialchars($user['login'], ENT_QUOTES, 'UTF-8');
$csrf_token = CSRF::token();
$pw_rules   = Password::jsRules(); // JSON for strength meter

// Unused backup codes count (shown in 2FA section)
$backup_count = 0;
if ($user['totp_enabled']) {
    $backup_count = (int)(DB::val(
        "SELECT COUNT(*) FROM totp_backup_codes WHERE user_id = ? AND used = 0",
        [$user['id']]
    ) ?? 0);
}

$totp_enabled = (bool)$user['totp_enabled'];
$is_admin     = ($user['role'] === 'admin');

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
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
body { padding: 0; min-height: 100vh; background: var(--bg); }

/* ── Topbar ── */
.settings-topbar {
    height: 56px; background: var(--surface); border-bottom: 1px solid var(--border);
    display: flex; align-items: center; padding: 0 1.5rem; gap: 1rem;
    position: sticky; top: 0; z-index: 100; box-shadow: var(--shadow-xs);
}
.settings-topbar-brand { display: flex; align-items: center; gap: .6rem; text-decoration: none; color: var(--text); font-weight: 700; font-size: 1rem; }
.settings-topbar-brand img { height: 32px; width: 32px; object-fit: contain; }
.settings-topbar-brand:hover { color: var(--primary); text-decoration: none; }
.settings-topbar-right { margin-left: auto; display: flex; align-items: center; gap: 1rem; font-size: .875rem; }
.back-link { color: var(--text-muted); text-decoration: none; display: inline-flex; align-items: center; gap: .3rem; transition: color .15s; }
.back-link:hover { color: var(--primary); text-decoration: none; }

/* ── Page layout ── */
.settings-main { max-width: 620px; margin: 0 auto; padding: 2rem 1.5rem 4rem; }
.settings-title { font-size: 1.5rem; font-weight: 700; margin-bottom: 1.75rem; }

/* ── Section cards ── */
.settings-section {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);
    overflow: hidden; margin-bottom: 1.5rem;
}
.settings-section-header {
    padding: 1.1rem 1.5rem; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: .75rem;
}
.settings-section-icon { font-size: 1.1rem; }
.settings-section-header h2 { font-size: 1rem; font-weight: 600; margin: 0; }
.settings-section-body { padding: 1.5rem; }

/* ── Form helpers ── */
.field-row { margin-bottom: 1.1rem; }
.field-row:last-child { margin-bottom: 0; }
.field-hint { font-size: .78rem; color: var(--text-muted); margin-top: .3rem; }

/* ── Password strength meter ── */
.pw-strength { height: 4px; border-radius: 9999px; background: var(--border); margin-top: .4rem; overflow: hidden; }
.pw-strength-bar { height: 100%; border-radius: 9999px; transition: width .3s ease, background .3s ease; width: 0; }

/* ── Inline alert ── */
.inline-alert {
    display: none; padding: .6rem .85rem; border-radius: var(--radius-md);
    font-size: .875rem; margin-bottom: 1rem; align-items: flex-start; gap: .5rem;
}
.inline-alert.show { display: flex; }
.inline-alert.success { background: var(--success-bg); border: 1px solid var(--success-bdr); color: var(--success); }
.inline-alert.error   { background: var(--error-bg);   border: 1px solid var(--error-bdr);   color: var(--error);   }
.inline-alert.info    { background: var(--info-bg);     border: 1px solid var(--info-bdr);     color: var(--info);    }

/* ── 2FA status badge ── */
.status-badge {
    display: inline-flex; align-items: center; gap: .35rem;
    padding: .2rem .65rem; border-radius: 9999px;
    font-size: .78rem; font-weight: 600;
}
.status-badge.on  { background: var(--success-bg); color: var(--success); border: 1px solid var(--success-bdr); }
.status-badge.off { background: var(--error-bg);   color: var(--error);   border: 1px solid var(--error-bdr);   }

/* ── Backup codes grid ── */
.backup-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .4rem; margin: 1rem 0; }
.backup-code-item {
    background: var(--surface-alt); border: 1px solid var(--border);
    border-radius: var(--radius-sm); padding: .5rem; text-align: center;
    font-family: var(--font-mono); font-size: .9rem; font-weight: 700; color: var(--text);
}

/* ── Theme toggle row ── */
.pref-row { display: flex; align-items: center; justify-content: space-between; padding: .6rem 0; border-bottom: 1px solid var(--border-light); }
.pref-row:last-child { border-bottom: none; padding-bottom: 0; }
.pref-label { font-size: .9rem; color: var(--text); }
.pref-hint  { font-size: .78rem; color: var(--text-muted); margin-top: .15rem; }

/* ── Separator ── */
.section-sep { height: 1px; background: var(--border); margin: 1.25rem 0; }

/* ── 2FA verify mini form ── */
.verify-form { display: none; background: var(--surface-alt); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 1rem; margin-top: 1rem; }
.verify-form.show { display: block; }
.code-input {
    text-align: center; letter-spacing: .25em; font-size: 1.4rem;
    font-weight: 700; font-family: var(--font-mono); padding: .75rem;
}

@media (max-width: 640px) {
    .settings-main { padding: 1.25rem 1rem 3rem; }
    .backup-grid { grid-template-columns: 1fr 1fr; }
    .settings-section-body { padding: 1.1rem; }
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

    <!-- ══════════════════════════════════════════════════════════════
         SECTION 1: Change Password
         ══════════════════════════════════════════════════════════════ -->
    <div class="settings-section">
        <div class="settings-section-header">
            <span class="settings-section-icon">🔑</span>
            <h2>Change Password</h2>
        </div>
        <div class="settings-section-body">

            <div class="inline-alert" id="pw-alert">
                <span id="pw-alert-msg"></span>
            </div>

            <div class="field-row">
                <label class="form-label" for="current-pw">Current password</label>
                <div class="input-wrap">
                    <input type="password" id="current-pw" class="form-input"
                           autocomplete="current-password" placeholder="Enter current password">
                    <button type="button" class="eye-btn" onclick="togglePw('current-pw', this)" aria-label="Show/hide">
                        <?= eyeSvg(true) ?>
                    </button>
                </div>
            </div>

            <div class="field-row">
                <label class="form-label" for="new-pw">New password</label>
                <div class="input-wrap">
                    <input type="password" id="new-pw" class="form-input"
                           autocomplete="new-password" placeholder="Min. 12 characters">
                    <button type="button" class="eye-btn" onclick="togglePw('new-pw', this)" aria-label="Show/hide">
                        <?= eyeSvg(true) ?>
                    </button>
                </div>
                <div class="pw-strength" id="pw-strength-bar-wrap">
                    <div class="pw-strength-bar" id="pw-strength-bar"></div>
                </div>
                <div class="field-hint" id="pw-strength-label"></div>
            </div>

            <div class="field-row">
                <label class="form-label" for="confirm-pw">Confirm new password</label>
                <div class="input-wrap">
                    <input type="password" id="confirm-pw" class="form-input"
                           autocomplete="new-password" placeholder="Repeat new password">
                    <button type="button" class="eye-btn" onclick="togglePw('confirm-pw', this)" aria-label="Show/hide">
                        <?= eyeSvg(true) ?>
                    </button>
                </div>
            </div>

            <button type="button" class="btn btn-primary" id="btn-change-pw" style="margin-top:.25rem">
                Change password
            </button>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         SECTION 2: Two-Factor Authentication
         ══════════════════════════════════════════════════════════════ -->
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
                You have <strong id="backup-count-display"><?= $backup_count ?></strong> unused backup
                code<?= $backup_count !== 1 ? 's' : ''?> remaining.
                <?php if ($backup_count <= 2): ?>
                <span style="color:var(--warning);font-weight:600">— Running low! Regenerate now.</span>
                <?php endif; ?>
            </p>

            <div class="inline-alert" id="bc-alert">
                <span id="bc-alert-msg"></span>
            </div>

            <button type="button" class="btn btn-outline" id="btn-regen-bc">
                ↺ Regenerate backup codes
            </button>

            <!-- Verify form — appears after clicking Regenerate -->
            <div class="verify-form" id="verify-form">
                <p style="font-size:.875rem;color:var(--text-muted);margin-bottom:.75rem">
                    Enter your current 6-digit authenticator code (or an unused backup code) to confirm:
                </p>
                <div class="field-row">
                    <input type="text" id="bc-code" class="form-input code-input"
                           inputmode="numeric" maxlength="9"
                           placeholder="000000" autocomplete="one-time-code">
                </div>
                <div style="display:flex;gap:.75rem;flex-wrap:wrap">
                    <button type="button" class="btn btn-primary" id="btn-confirm-regen">Confirm regeneration</button>
                    <button type="button" class="btn btn-ghost" id="btn-cancel-regen">Cancel</button>
                </div>
            </div>

            <!-- New backup codes display area -->
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
                Two-factor authentication adds an extra layer of security to your account.
                <?php if ($is_admin): ?>
                <strong>Admin accounts require 2FA.</strong>
                <?php endif; ?>
            </p>
            <a href="/setup-2fa" class="btn btn-primary">Set up 2FA →</a>

            <?php endif; ?>

        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         SECTION 3: UI Preferences
         ══════════════════════════════════════════════════════════════ -->
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
                    <div class="pref-label">Account</div>
                    <div class="pref-hint">Logged in as <strong><?= $user_login ?></strong></div>
                </div>
                <form method="post" action="/logout" style="margin:0">
                    <input type="hidden" name="_csrf" value="<?= h($csrf_token) ?>">
                    <button type="submit" class="btn btn-ghost btn-sm" style="border-color:var(--error-bdr);color:var(--error)">
                        Sign out
                    </button>
                </form>
            </div>

        </div>
    </div>

</main>

<script>
(function(){ const t=localStorage.getItem('dv-theme'); if(t) document.documentElement.setAttribute('data-theme',t); })();

const CSRF_TOKEN = <?= json_encode($csrf_token) ?>;
const PW_RULES   = <?= $pw_rules ?>;

// ── Theme ─────────────────────────────────────────────────────────────────────
function applyTheme(t, save) {
    document.documentElement.setAttribute('data-theme', t);
    if (save) localStorage.setItem('dv-theme', t);
    const label = t === 'dark' ? '☀ Light' : '🌙 Dark';
    document.querySelectorAll('#theme-btn, #theme-btn-pref').forEach(b => b.textContent = label);
}
applyTheme(localStorage.getItem('dv-theme') || 'light', false);
document.querySelectorAll('#theme-btn, #theme-btn-pref').forEach(btn =>
    btn.addEventListener('click', () => {
        const cur = document.documentElement.getAttribute('data-theme') || 'light';
        applyTheme(cur === 'dark' ? 'light' : 'dark', true);
    })
);

// ── Show/hide password ────────────────────────────────────────────────────────
function eyeSvgShow() {
    return '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
}
function eyeSvgHide() {
    return '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
}
function togglePw(id, btn) {
    const i = document.getElementById(id);
    const showing = i.type === 'text';
    i.type = showing ? 'password' : 'text';
    btn.innerHTML = showing ? eyeSvgShow() : eyeSvgHide();
}

// ── Password strength meter ───────────────────────────────────────────────────
const pwInput   = document.getElementById('new-pw');
const pwBar     = document.getElementById('pw-strength-bar');
const pwLabel   = document.getElementById('pw-strength-label');
const levels    = ['', 'Too short', 'Weak', 'Fair', 'Strong'];
const levelClrs = ['', '#E53E3E', '#D69E2E', '#D69E2E', '#1D5C42'];
const levelPct  = ['', '25%', '50%', '75%', '100%'];

function calcStrength(pw) {
    if (!pw || pw.length < PW_RULES.minLength) return 1;
    let score = 0;
    if (/[A-Z]/.test(pw)) score++;
    if (/[a-z]/.test(pw)) score++;
    if (/[0-9]/.test(pw)) score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;
    if (pw.length >= 16) score = Math.min(score + 1, 4);
    return Math.max(1, Math.min(score, 4));
}

pwInput?.addEventListener('input', function() {
    const pw = this.value;
    if (!pw) { pwBar.style.width = '0'; pwLabel.textContent = ''; return; }
    const lvl = calcStrength(pw);
    pwBar.style.width = levelPct[lvl];
    pwBar.style.background = levelClrs[lvl];
    pwLabel.textContent = levels[lvl];
    pwLabel.style.color = levelClrs[lvl];
});

// ── API helper ────────────────────────────────────────────────────────────────
async function apiPost(path, body) {
    try {
        const res  = await fetch(path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        });
        return await res.json();
    } catch (e) {
        return { ok: false, error: 'Network error.' };
    }
}

// ── Inline alert helper ───────────────────────────────────────────────────────
function showAlert(id, msgId, type, msg) {
    const el  = document.getElementById(id);
    const mel = document.getElementById(msgId);
    if (!el || !mel) return;
    el.className = 'inline-alert show ' + type;
    mel.textContent = msg;
    el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}
function hideAlert(id) {
    const el = document.getElementById(id);
    if (el) el.className = 'inline-alert';
}

// ── Change Password ───────────────────────────────────────────────────────────
document.getElementById('btn-change-pw')?.addEventListener('click', async () => {
    hideAlert('pw-alert');
    const current = document.getElementById('current-pw')?.value;
    const newPw   = document.getElementById('new-pw')?.value;
    const confirm = document.getElementById('confirm-pw')?.value;

    if (!current || !newPw || !confirm) {
        showAlert('pw-alert', 'pw-alert-msg', 'error', 'All fields are required.'); return;
    }
    if (newPw !== confirm) {
        showAlert('pw-alert', 'pw-alert-msg', 'error', 'New passwords do not match.'); return;
    }

    const btn = document.getElementById('btn-change-pw');
    btn.disabled = true; btn.textContent = '…';

    const r = await apiPost('/api/settings/password', {
        current_password: current,
        new_password:     newPw,
        confirm_password: confirm,
    });

    btn.disabled = false; btn.textContent = 'Change password';

    if (!r.ok) {
        showAlert('pw-alert', 'pw-alert-msg', 'error', r.error || 'Could not change password.');
        return;
    }

    showAlert('pw-alert', 'pw-alert-msg', 'success',
        'Password changed successfully. Redirecting to login…');

    // Clear inputs
    ['current-pw','new-pw','confirm-pw'].forEach(id => {
        const el = document.getElementById(id); if (el) el.value = '';
    });
    document.getElementById('pw-strength-bar').style.width = '0';
    document.getElementById('pw-strength-label').textContent = '';

    // All sessions invalidated — redirect to login after short delay
    setTimeout(() => { window.location.href = '/login'; }, 2500);
});

// ── Backup Codes Regeneration ─────────────────────────────────────────────────
const regenBtn    = document.getElementById('btn-regen-bc');
const verifyForm  = document.getElementById('verify-form');
const cancelBtn   = document.getElementById('btn-cancel-regen');
const confirmBtn  = document.getElementById('btn-confirm-regen');
const bcCodeInput = document.getElementById('bc-code');
const newCodesArea = document.getElementById('new-codes-area');

regenBtn?.addEventListener('click', () => {
    verifyForm?.classList.add('show');
    newCodesArea.style.display = 'none';
    hideAlert('bc-alert');
    bcCodeInput?.focus();
    regenBtn.style.display = 'none';
});

cancelBtn?.addEventListener('click', () => {
    verifyForm?.classList.remove('show');
    if (bcCodeInput) bcCodeInput.value = '';
    regenBtn.style.display = '';
    hideAlert('bc-alert');
});

// Auto-submit on 6 digits
bcCodeInput?.addEventListener('input', function() {
    this.value = this.value.replace(/[^0-9A-Fa-f\-]/g, '');
    // TOTP = 6 digits; backup codes = 9 chars (XXXX-XXXX)
    if (this.value.replace(/\D/g,'').length === 6 || this.value.length === 9) {
        confirmBtn?.click();
    }
});

confirmBtn?.addEventListener('click', async () => {
    hideAlert('bc-alert');
    const code = bcCodeInput?.value?.trim();
    if (!code) {
        showAlert('bc-alert', 'bc-alert-msg', 'error', 'Please enter your 2FA code.'); return;
    }

    confirmBtn.disabled = true; confirmBtn.textContent = '…';

    const r = await apiPost('/api/settings/backup-codes', { code });

    confirmBtn.disabled = false; confirmBtn.textContent = 'Confirm regeneration';

    if (!r.ok) {
        showAlert('bc-alert', 'bc-alert-msg', 'error', r.error || 'Could not regenerate codes.');
        if (bcCodeInput) bcCodeInput.value = '';
        return;
    }

    // Success — show new codes
    verifyForm?.classList.remove('show');
    if (bcCodeInput) bcCodeInput.value = '';

    const grid = document.getElementById('new-codes-grid');
    if (grid) {
        grid.innerHTML = '';
        (r.backup_codes || []).forEach(c => {
            const el = document.createElement('div');
            el.className = 'backup-code-item';
            el.textContent = c;
            grid.appendChild(el);
        });
    }

    newCodesArea.style.display = '';
    regenBtn.style.display = '';

    // Update displayed count
    const countEl = document.getElementById('backup-count-display');
    if (countEl) countEl.textContent = (r.backup_codes || []).length;

    showAlert('bc-alert', 'bc-alert-msg', 'success', '10 new backup codes generated.');
    newCodesArea.scrollIntoView({ block: 'nearest', behavior: 'smooth' });

    // Download button
    document.getElementById('btn-download-codes')?.addEventListener('click', () => {
        downloadCodes(r.backup_codes);
    });

    // Copy all button
    document.getElementById('btn-copy-codes')?.addEventListener('click', () => {
        const text = (r.backup_codes || []).join('\n');
        navigator.clipboard?.writeText(text).then(() => {
            const btn = document.getElementById('btn-copy-codes');
            const orig = btn.textContent;
            btn.textContent = '✓ Copied!';
            setTimeout(() => btn.textContent = orig, 2000);
        });
    });
});

function downloadCodes(codes) {
    const appName = <?= json_encode(APP_NAME) ?>;
    const now = new Date().toISOString().slice(0, 19).replace('T', ' ');
    const lines = (codes || []).map((c, i) => '  ' + String(i+1).padStart(2,' ') + '.  ' + c);
    const content = [
        '===========================================',
        '  ' + appName + ' — 2FA Backup Codes',
        '  Generated: ' + now,
        '===========================================',
        'IMPORTANT: Each code can only be used ONCE.',
        'Store this file in a password manager.',
        '===========================================',
        '',
        ...lines,
        '',
        '===========================================',
        'After using all codes, regenerate them in',
        'Settings → Two-Factor Authentication.',
    ].join('\n');

    const blob = new Blob([content], { type: 'text/plain' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url;
    a.download = appName.replace(/[^a-z0-9_-]/gi, '_') + '_backup_codes.txt';
    document.body.appendChild(a); a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}
</script>
</body>
</html>
<?php
// ── SVG helpers (same as login.php) ──────────────────────────────────────────
function eyeSvg(bool $show): string {
    return $show
        ? '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>'
        : '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
}
