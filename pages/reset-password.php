<?php
/**
 * LetaDial — Reset Password (sesja 063)
 *
 * GET  /reset-password?token=XXX  — validate token, show new password form
 * POST /reset-password             — validate token + set new password
 *
 * Security:
 *   - Token validated on every request (GET + POST)
 *   - Token expires in 1 hour (checked against reset_expires in DB)
 *   - Rate limit: 5 attempts per token per hour (brute-force on token)
 *   - Password strength enforced via Password::validate()
 *   - All existing sessions invalidated after successful reset
 *   - Token single-use: cleared immediately after use
 *   - CSRF required on POST
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die();

if (Auth::isLoggedIn()) { header('Location: /'); exit; }

// Pre-warm CSRF before any HTML output
$_csrf_prewarm = CSRF::token();

$token = trim($_GET['token'] ?? '');
$done  = false;
$error = '';

// ── Validate token format ─────────────────────────────────────────────────────
if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    $token_valid = false;
    $user        = null;
} else {
    $user = DB::row(
        "SELECT id, login, reset_token, reset_expires FROM users
         WHERE reset_token = ? AND reset_expires > NOW()
         LIMIT 1",
        [$token]
    );
    $token_valid = (bool)$user;
}

// ── POST: apply new password ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token_valid && $user) {
    CSRF::require();

    // Rate limit per token (protects against brute-force if token was partially leaked)
    if (RateLimit::check('reset_pw', $token, 5, 3600, 3600)) {
        $error = 'Too many attempts. Please request a new reset link.';
    } else {
        $new     = $_POST['password']        ?? '';
        $confirm = $_POST['password_confirm'] ?? '';

        $errors = Password::validate($new);

        if (!empty($errors)) {
            $error = implode(' ', $errors);
        } elseif ($new !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            // Update password + clear token
            $hash = Password::hash($new);
            DB::run(
                "UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?",
                [$hash, $user['id']]
            );

            // Invalidate all existing sessions (including remember-me tokens)
            Auth::logoutAllSessions($user['id']);

            RateLimit::clear('reset_pw', $token);

            $done = true;
        }
    }
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function eyeSvg(bool $show): string {
    return $show
        ? '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>'
        : '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
}
$app_name = h(APP_NAME);
$icon_url = h(APP_URL . '/assets/icons/icon-192.png');
$pw_rules = Password::jsRules();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Reset password — <?= $app_name ?></title>
<link rel="shortcut icon" href="/assets/icons/favicon.png" type="image/png">
<link rel="icon" href="/assets/icons/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
<link rel="manifest" href="/assets/manifest.json">
<link rel="stylesheet" href="/assets/css/design-system.css">
<style>
body { display:flex; align-items:center; justify-content:center; min-height:100vh; padding:1.5rem; }
.login-card {
    width:100%; max-width:420px;
    background:var(--surface); border:1px solid var(--border);
    border-radius:var(--radius-lg); box-shadow:var(--shadow-xl);
    padding:2.25rem 2rem 2rem;
}
.logo { text-align:center; margin-bottom:2rem; }
.logo-img { width:72px; height:72px; object-fit:contain; filter:drop-shadow(0 2px 10px rgba(0,0,0,.18)); margin-bottom:.75rem; transition:transform .25s ease; }
.logo-img:hover { transform:scale(1.06) rotate(-2deg); }
.logo h1 { font-size:1.35rem; font-weight:700; }
.logo p  { color:var(--text-muted); font-size:.875rem; margin:.15rem 0 0; }
.back-link { display:block; text-align:center; margin-top:1.25rem; font-size:.85rem; color:var(--text-muted); text-decoration:none; transition:color .15s; }
.back-link:hover { color:var(--primary); }
.success-box { text-align:center; padding:.5rem 0; }
.success-icon { font-size:3rem; margin-bottom:1rem; }
.success-box h2 { font-size:1.1rem; font-weight:600; margin-bottom:.75rem; }
.success-box p { color:var(--text-muted); font-size:.875rem; line-height:1.6; }
.error-box { text-align:center; padding:.5rem 0; }
.error-icon { font-size:3rem; margin-bottom:1rem; }
.error-box h2 { font-size:1.1rem; font-weight:600; margin-bottom:.75rem; }
.error-box p { color:var(--text-muted); font-size:.875rem; line-height:1.6; }
</style>
<script>(function(){ var t=localStorage.getItem('dv-theme'); if(t) document.documentElement.setAttribute('data-theme',t); })();</script>
</head>
<body>

<div class="login-card">
    <div class="logo">
        <img src="<?= $icon_url ?>" alt="<?= $app_name ?>" class="logo-img">
        <h1><?= $app_name ?></h1>
        <p>Set a new password</p>
    </div>

    <?php if ($done): ?>

    <div class="success-box">
        <div class="success-icon">✅</div>
        <h2>Password updated!</h2>
        <p>
            Your password has been changed successfully.<br>
            All existing sessions have been signed out.
        </p>
    </div>
    <a href="/login" class="btn btn-primary btn-block btn-lg" style="margin-top:1.5rem;text-align:center">
        Sign in with new password →
    </a>

    <?php elseif (!$token_valid): ?>

    <div class="error-box">
        <div class="error-icon">⏰</div>
        <h2>Link expired or invalid</h2>
        <p>
            This password reset link has expired or already been used.
            Reset links are valid for <strong>1 hour</strong>.
        </p>
    </div>
    <a href="/forgot-password" class="btn btn-primary btn-block btn-lg" style="margin-top:1.5rem;text-align:center">
        Request a new link →
    </a>
    <a href="/login" class="back-link">← Back to sign in</a>

    <?php else: ?>

    <?php if ($error): ?>
    <div class="alert alert-error" style="margin-bottom:1.25rem">
        <span class="alert-icon">&#9888;</span><span><?= h($error) ?></span>
    </div>
    <?php endif; ?>

    <p style="font-size:.875rem;color:var(--text-muted);margin-bottom:1.5rem;line-height:1.6">
        Resetting password for <strong><?= h($user['login']) ?></strong>.
        Choose a strong password of at least 12 characters.
    </p>

    <form method="post" autocomplete="new-password">
        <?= CSRF::field() ?>
        <input type="hidden" name="token" value="<?= h($token) ?>">

        <div class="form-group">
            <label class="form-label" for="password">New password</label>
            <div class="input-wrap">
                <input type="password" id="password" name="password" class="form-input"
                       autocomplete="new-password" placeholder="Min. 12 characters"
                       autofocus required>
                <button type="button" class="eye-btn" onclick="togglePw('password',this)" aria-label="Show/hide">
                    <?= eyeSvg(true) ?>
                </button>
            </div>
            <div class="pw-strength">
                <div class="pw-strength-bar" id="pw-strength-bar"></div>
            </div>
            <div class="form-hint" id="pw-strength-label"></div>
        </div>

        <div class="form-group">
            <label class="form-label" for="password_confirm">Confirm new password</label>
            <div class="input-wrap">
                <input type="password" id="password_confirm" name="password_confirm" class="form-input"
                       autocomplete="new-password" placeholder="Repeat new password" required>
                <button type="button" class="eye-btn" onclick="togglePw('password_confirm',this)" aria-label="Show/hide">
                    <?= eyeSvg(true) ?>
                </button>
            </div>
            <div class="form-hint" id="confirm-hint" style="display:none;color:var(--error)">Passwords do not match.</div>
        </div>

        <button type="submit" class="btn btn-primary btn-block btn-lg" id="btn-submit" style="margin-top:.25rem">
            Set new password →
        </button>
    </form>

    <a href="/login" class="back-link">← Back to sign in</a>

    <script>
    const PW_RULES = <?= $pw_rules ?>;
    const levels   = ['', 'Too short', 'Weak', 'Fair', 'Strong'];
    const colors   = ['', '#E53E3E', '#D69E2E', '#D69E2E', '#1D5C42'];
    const widths   = ['', '25%', '50%', '75%', '100%'];

    function calcStrength(pw) {
        if (!pw || pw.length < PW_RULES.minLength) return 1;
        let s = 0;
        if (/[A-Z]/.test(pw)) s++;
        if (/[a-z]/.test(pw)) s++;
        if (/[0-9]/.test(pw)) s++;
        if (/[^A-Za-z0-9]/.test(pw)) s++;
        if (pw.length >= 16) s = Math.min(s + 1, 4);
        return Math.max(1, Math.min(s, 4));
    }

    const pwInput  = document.getElementById('password');
    const pwBar    = document.getElementById('pw-strength-bar');
    const pwLabel  = document.getElementById('pw-strength-label');
    const cfInput  = document.getElementById('password_confirm');
    const cfHint   = document.getElementById('confirm-hint');
    const submitBtn = document.getElementById('btn-submit');

    pwInput.addEventListener('input', function() {
        const pw = this.value;
        if (!pw) { pwBar.style.width = '0'; pwLabel.textContent = ''; return; }
        const lvl = calcStrength(pw);
        pwBar.style.width = widths[lvl];
        pwBar.style.background = colors[lvl];
        pwLabel.textContent = levels[lvl];
        pwLabel.style.color = colors[lvl];
        checkMatch();
    });

    cfInput.addEventListener('input', checkMatch);

    function checkMatch() {
        if (!cfInput.value) { cfHint.style.display = 'none'; return; }
        const match = pwInput.value === cfInput.value;
        cfHint.style.display = match ? 'none' : '';
    }

    function togglePw(id, btn) {
        const i = document.getElementById(id);
        const showing = i.type === 'text';
        i.type = showing ? 'password' : 'text';
        btn.innerHTML = showing
            ? '<?= addslashes(eyeSvg(true)) ?>'
            : '<?= addslashes(eyeSvg(false)) ?>';
    }
    </script>

    <?php endif; ?>
</div>

</body>
</html>
