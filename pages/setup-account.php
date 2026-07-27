<?php
/**
 * LetaDial — Setup Account (sesja 067)
 *
 * GET  /setup-account?token=XXX  — validate invite token, show password form
 * POST /setup-account             — set password, activate account
 *
 * Invite flow:
 *   1. Admin sends invite via Admin → Users → Invite
 *   2. User receives email with /setup-account?token=XXX (valid 24h)
 *   3. Email is pre-filled and READ-ONLY — user only sets password
 *   4. On submit: password set, email_verified=1, token cleared
 *   5. User redirected to /login — no separate email confirmation needed
 *      (clicking the emailed link already proves email ownership)
 *
 * If user later changes email in Settings → must confirm via confirmation email.
 *
 * Security:
 *   - Token: 64 hex chars (32 random bytes) — cannot be brute-forced
 *   - Expiry: 24 hours from account creation (checked via created_at)
 *   - Token single-use: cleared after successful activation
 *   - Email cannot be changed on this form (readonly)
 *   - Rate limit: 5 attempts per token per hour
 *   - CSRF required on POST
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die();

if (Auth::isLoggedIn()) { header('Location: /'); exit; }

$_csrf_prewarm = CSRF::token();

$token = trim($_GET['token'] ?? '');
$done  = false;
$error = '';

// ── Validate token ─────────────────────────────────────────────────────────────
if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    $token_valid = false;
    $user        = null;
} else {
    // Token valid if: matches, not yet verified, created within the last 24 hours
    $user = DB::row(
        "SELECT id, login, email, activation_token FROM users
         WHERE activation_token = ?
           AND email_verified = 0
           AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
         LIMIT 1",
        [$token]
    );
    $token_valid = (bool)$user;
}

// ── POST: set password and activate ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token_valid && $user) {
    CSRF::require();

    if (RateLimit::check('setup_account', $token, 5, 3600, 3600)) {
        $error = 'Too many attempts. Please ask your administrator to send a new invitation.';
    } else {
        $new     = $_POST['password']        ?? '';
        $confirm = $_POST['password_confirm'] ?? '';

        $errors = Password::validate($new);

        if (!empty($errors)) {
            $error = implode(' ', $errors);
        } elseif ($new !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            DB::run(
                "UPDATE users
                 SET password_hash = ?, email_verified = 1, activation_token = NULL
                 WHERE id = ? AND email_verified = 0",
                [Password::hash($new), $user['id']]
            );

            RateLimit::clear('setup_account', $token);
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
<title>Set up your account — <?= $app_name ?></title>
<link rel="shortcut icon" href="/assets/icons/favicon.png" type="image/png">
<link rel="icon" href="/assets/icons/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
<link rel="manifest" href="/assets/manifest.json">
<link rel="stylesheet" href="/assets/css/design-system.css">
<link rel="stylesheet" href="/assets/css/pages/setup-account.css">
<script nonce="<?= CSP::nonce() ?>">(function(){ var t=localStorage.getItem('dv-theme'); if(t) document.documentElement.setAttribute('data-theme',t); })();</script>
</head>
<body>

<div class="login-card">
    <div class="logo">
        <img src="<?= $icon_url ?>" alt="<?= $app_name ?>" class="logo-img">
        <h1><?= $app_name ?></h1>
        <p>You've been invited</p>
    </div>

    <?php if ($done): ?>

    <div class="result-box">
        <div class="result-icon">🎉</div>
        <h2>Account ready!</h2>
        <p>
            Your account has been activated.<br>
            Sign in with your login: <strong><?= h($user['login']) ?></strong>
        </p>
    </div>
    <a href="/login" class="btn btn-primary btn-block btn-lg" style="margin-top:1.5rem;text-align:center">
        Sign in →
    </a>

    <?php elseif (!$token_valid): ?>

    <div class="result-box">
        <div class="result-icon">⏰</div>
        <h2>Invitation expired or invalid</h2>
        <p>
            This invitation link has expired (valid for 24 hours) or has already been used.<br>
            Please contact your administrator to receive a new invitation.
        </p>
    </div>
    <a href="/login" class="back-link">← Back to sign in</a>

    <?php else: ?>

    <?php if ($error): ?>
    <div class="alert alert-error" style="margin-bottom:1.25rem">
        <span class="alert-icon">&#9888;</span><span><?= h($error) ?></span>
    </div>
    <?php endif; ?>

    <p style="font-size:.875rem;color:var(--text-muted);margin-bottom:1.25rem;line-height:1.6">
        Welcome, <strong><?= h($user['login']) ?></strong>!<br>
        Choose a password to complete your account setup.
    </p>

    <form method="post" autocomplete="new-password">
        <?= CSRF::field() ?>
        <input type="hidden" name="token" value="<?= h($token) ?>">

        <!-- Email: pre-filled, read-only, visually locked -->
        <div class="form-group" style="margin-bottom:1.1rem">
            <label class="form-label">Email address</label>
            <div class="email-locked">
                <span class="lock-icon">🔒</span>
                <span class="email-val"><?= h($user['email']) ?></span>
            </div>
            <div class="invite-note">Linked to this invitation — cannot be changed here.</div>
        </div>

        <div class="form-group">
            <label class="form-label" for="password">Password</label>
            <div class="input-wrap">
                <input type="password" id="password" name="password" class="form-input"
                       autocomplete="new-password" placeholder="Min. 12 characters"
                       autofocus required>
                <button type="button" class="eye-btn" id="eye-btn-password" aria-label="Show/hide">
                    <?= eyeSvg(true) ?>
                </button>
            </div>
            <div class="pw-strength">
                <div class="pw-strength-bar" id="pw-strength-bar"></div>
            </div>
            <div class="form-hint" id="pw-strength-label"></div>
        </div>

        <div class="form-group">
            <label class="form-label" for="password_confirm">Confirm password</label>
            <div class="input-wrap">
                <input type="password" id="password_confirm" name="password_confirm" class="form-input"
                       autocomplete="new-password" placeholder="Repeat password" required>
                <button type="button" class="eye-btn" id="eye-btn-password-confirm" aria-label="Show/hide">
                    <?= eyeSvg(true) ?>
                </button>
            </div>
            <div class="form-hint" id="confirm-hint" style="display:none;color:var(--error)">Passwords do not match.</div>
        </div>

        <button type="submit" class="btn btn-primary btn-block btn-lg" style="margin-top:.25rem">
            Activate my account →
        </button>
    </form>

    <a href="/login" class="back-link">← Back to sign in</a>

    <script nonce="<?= CSP::nonce() ?>">
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

    const pwInput = document.getElementById('password');
    const pwBar   = document.getElementById('pw-strength-bar');
    const pwLabel = document.getElementById('pw-strength-label');
    const cfInput = document.getElementById('password_confirm');
    const cfHint  = document.getElementById('confirm-hint');

    pwInput.addEventListener('input', function() {
        const pw = this.value;
        if (!pw) { pwBar.style.width='0'; pwLabel.textContent=''; return; }
        const lvl = calcStrength(pw);
        pwBar.style.width = widths[lvl];
        pwBar.style.background = colors[lvl];
        pwLabel.textContent = levels[lvl];
        pwLabel.style.color = colors[lvl];
        checkMatch();
    });

    cfInput.addEventListener('input', checkMatch);

    function checkMatch() {
        if (!cfInput.value) { cfHint.style.display='none'; return; }
        cfHint.style.display = pwInput.value === cfInput.value ? 'none' : '';
    }

    function togglePw(id, btn) {
        const i = document.getElementById(id);
        const showing = i.type === 'text';
        i.type = showing ? 'password' : 'text';
        btn.innerHTML = showing
            ? '<?= addslashes(eyeSvg(true)) ?>'
            : '<?= addslashes(eyeSvg(false)) ?>';
    }

    // ── Event listeners (CSP: bez inline onXXX=, Krok 4a) ──────────────────────
    document.getElementById('eye-btn-password')?.addEventListener('click', function() { togglePw('password', this); });
    document.getElementById('eye-btn-password-confirm')?.addEventListener('click', function() { togglePw('password_confirm', this); });
    </script>

    <?php endif; ?>
</div>

</body>
</html>
