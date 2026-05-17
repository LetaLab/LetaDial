<?php
declare(strict_types=1);
defined('DIALVAULT_APP') or die();

if (Auth::isLoggedIn()) { header('Location: /'); exit; }

// ── PRE-WARM CSRF TOKEN ───────────────────────────────────────────────────────
// MUST happen before any HTML output. For pre-auth (no DB session), this
// triggers CSRF::preAuthToken() which calls setcookie('dv_pa', ...).
// If setcookie() is called after output starts, the cookie is never sent
// and all form submissions fail with 403 CSRF error.
$_csrf_prewarm = CSRF::token();
// ─────────────────────────────────────────────────────────────────────────────

$error  = '';
$step   = 'login';

$partial = Auth::getPartialUser();
if ($partial && !Auth::isLoggedIn()) {
    $step = $partial['totp_enabled'] ? 'totp' : 'setup';
    if ($step === 'setup') { header('Location: /setup-2fa'); exit; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::require();
    $action = $_POST['action'] ?? 'login';
    if ($action === 'login' && $step === 'login') {
        $login    = trim($_POST['login']    ?? '');
        $password = $_POST['password']      ?? '';
        $remember = !empty($_POST['remember']);
        if (!$login || !$password) {
            $error = 'Please enter your login and password.';
        } else {
            $result = Auth::login($login, $password, $remember);
            if (!$result['ok'])                         { $error = $result['error']; }
            elseif ($result['needs_setup'] ?? false)    { header('Location: /setup-2fa'); exit; }
            elseif ($result['needs_2fa']   ?? false)    { header('Location: /login');    exit; }
            else                                        { header('Location: /');         exit; }
        }
    }
    if ($action === 'verify_2fa' && $step === 'totp') {
        $code   = preg_replace('/\s/', '', $_POST['code'] ?? '');
        $result = Auth::verify2FA($code);
        if ($result['ok']) { header('Location: ' . (!empty($result['used_backup']) ? '/?bcu=1' : '/')); exit; }
        $error = $result['error'];
    }
    if ($action === 'cancel_2fa') { Auth::logout(); header('Location: /login'); exit; }
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function eyeSvg(bool $show): string {
    return $show
        ? '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>'
        : '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
}
$app_name = h(APP_NAME);
$icon_url = h(APP_URL . '/assets/icons/icon-192.png');
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Sign in - <?= $app_name ?></title>
<link rel="shortcut icon" href="/assets/icons/favicon.png" type="image/png">
<link rel="icon" href="/assets/icons/favicon.png" type="image/png" sizes="48x48">
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
.logo-img { width:80px; height:80px; object-fit:contain; filter:drop-shadow(0 2px 10px rgba(0,0,0,.18)); margin-bottom:.75rem; transition:transform .25s ease; }
.logo-img:hover { transform:scale(1.06) rotate(-2deg); }
.logo h1 { font-size:1.35rem; font-weight:700; }
.logo p  { color:var(--text-muted); font-size:.875rem; margin:.15rem 0 0; }
.form-row { display:flex; align-items:center; justify-content:space-between; margin-bottom:1.5rem; font-size:.85rem; flex-wrap:wrap; gap:.5rem; }
.code-input { text-align:center !important; letter-spacing:.28em !important; font-size:1.5rem !important; font-weight:700 !important; font-family:var(--font-mono) !important; padding:.75rem !important; }
.totp-info { text-align:center; color:var(--text-muted); font-size:.875rem; margin-bottom:1.25rem; line-height:1.6; }
/* Cookie Consent Banner */
.cookie-overlay {
    position:fixed; inset:0;
    background:rgba(0,0,0,.35); z-index:9999;
    display:flex; align-items:flex-end; padding:1rem;
    backdrop-filter:blur(2px);
}
.cookie-banner {
    background:var(--surface); border:1px solid var(--border);
    border-radius:var(--radius-lg); box-shadow:var(--shadow-xl);
    padding:1.5rem 1.75rem; max-width:640px; margin:0 auto; width:100%;
    animation:cookieSlideUp .25s ease;
}
@keyframes cookieSlideUp { from{opacity:0;transform:translateY(24px)} to{opacity:1;transform:translateY(0)} }
.cookie-banner h3 { font-size:1rem; font-weight:700; margin-bottom:.5rem; }
.cookie-banner p  { font-size:.875rem; color:var(--text-muted); line-height:1.6; margin-bottom:.75rem; }
.cookie-banner p:last-of-type { margin-bottom:1rem; }
.cookie-banner a  { color:var(--primary); }
.cookie-buttons   { display:flex; gap:.75rem; flex-wrap:wrap; }
/* Equal prominence - EU requirement */
.cookie-buttons .cbtn {
    flex:1; min-width:140px;
    background:var(--surface-alt); color:var(--text);
    border:1.5px solid var(--border); border-radius:var(--radius-md);
    padding:.65rem 1rem; font-size:.875rem; font-weight:600;
    cursor:pointer; text-align:center; font-family:var(--font-sans);
    transition:all var(--transition);
}
.cookie-buttons .cbtn:hover { border-color:var(--primary); color:var(--primary); background:var(--primary-bg); }
.login-blocked { pointer-events:none; filter:blur(2px); user-select:none; opacity:.6; transition:all .3s; }
</style>
</head>
<body>

<!-- EU Cookie Consent (ePrivacy Directive 2002/58/EC + GDPR 2016/679) -->
<div class="cookie-overlay" id="cookie-overlay" style="display:none" role="dialog" aria-modal="true">
    <div class="cookie-banner">
        <h3>A quick word about cookies</h3>
        <p>
            To log you in securely, this site stores a session cookie in your browser - a small file
            that keeps you signed in while you're here. We don't use tracking cookies, analytics,
            or advertising. This is a private dashboard: just you and your data.
        </p>
        <p style="font-size:.8rem;color:var(--text-faint)">
            Under EU law
            (<a href="https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:02002L0058-20091219" target="_blank" rel="noopener">ePrivacy Directive 2002/58/EC</a>
            and
            <a href="https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:02016R0679-20160504" target="_blank" rel="noopener">GDPR 2016/679</a>),
            session cookies required for authentication are considered strictly necessary
            and are permitted without consent - but we want you to know they exist.
        </p>
        <div class="cookie-buttons">
            <button type="button" class="cbtn" id="btn-accept-cookies">Accept cookies</button>
            <button type="button" class="cbtn" id="btn-decline-cookies">Decline cookies and get me out of here</button>
        </div>
    </div>
</div>

<div class="login-card" id="login-card">
    <div class="logo">
        <img src="<?= $icon_url ?>" alt="<?= $app_name ?>" class="logo-img">
        <h1><?= $app_name ?></h1>
        <p><?= $step === 'login' ? 'Sign in to your account' : 'Two-factor authentication' ?></p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-error" style="margin-bottom:1.25rem">
        <span class="alert-icon">&#9888;</span><span><?= h($error) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($step === 'login'): ?>
    <form method="post" autocomplete="on">
        <?= CSRF::field() ?>
        <input type="hidden" name="action" value="login">
        <div class="form-group">
            <label class="form-label" for="lf">Login</label>
            <input type="text" id="lf" name="login" class="form-input"
                   autocomplete="username" value="<?= h($_POST['login'] ?? '') ?>"
                   placeholder="your login" autofocus required>
        </div>
        <div class="form-group">
            <label class="form-label" for="pw">Password</label>
            <div class="input-wrap">
                <input type="password" id="pw" name="password" class="form-input"
                       autocomplete="current-password" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;" required>
                <button type="button" class="eye-btn" onclick="togglePw('pw',this)" aria-label="Show/hide">
                    <?= eyeSvg(true) ?>
                </button>
            </div>
        </div>
        <div class="form-row">
            <label class="check-label">
                <input type="checkbox" name="remember" value="1" checked>
                Remember me for 90 days
            </label>
            <a href="/forgot-password" style="color:var(--text-muted);font-size:.83rem;transition:color .15s"
               onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color=''">
                Forgot password?
            </a>
        </div>
        <button type="submit" class="btn btn-primary btn-block btn-lg">Sign in &rarr;</button>
    </form>

    <?php elseif ($step === 'totp'): ?>
    <p class="totp-info">
        Open your authenticator app and enter<br>
        the 6-digit code for <strong><?= $app_name ?></strong>.
    </p>
    <form method="post" autocomplete="off">
        <?= CSRF::field() ?>
        <input type="hidden" name="action" value="verify_2fa">
        <div class="form-group">
            <label class="form-label">Authentication code</label>
            <input type="text" name="code" class="form-input code-input"
                   inputmode="numeric" maxlength="6" pattern="\d{6}"
                   placeholder="000000" autofocus autocomplete="one-time-code" required>
        </div>
        <button type="submit" class="btn btn-primary btn-block btn-lg">Verify &rarr;</button>
    </form>
    <div class="divider" style="margin:1.25rem 0">or backup code</div>
    <form method="post" autocomplete="off">
        <?= CSRF::field() ?>
        <input type="hidden" name="action" value="verify_2fa">
        <div class="form-group">
            <label class="form-label">Backup code</label>
            <input type="text" name="code" class="form-input"
                   style="text-align:center;letter-spacing:.12em" placeholder="XXXX-XXXX">
        </div>
        <button type="submit" class="btn btn-ghost btn-block">Use backup code</button>
    </form>
    <div style="text-align:center;margin-top:.75rem">
        <form method="post">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="cancel_2fa">
            <button type="submit" style="background:none;border:none;cursor:pointer;font-size:.82rem;color:var(--text-muted);font-family:var(--font-sans)">
                &larr; Back to login
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>

<script>
(function(){ var t=localStorage.getItem('dv-theme'); if(t) document.documentElement.setAttribute('data-theme',t); })();

// Cookie consent
(function() {
    var CONSENT_KEY = 'dv_cookie_consent';
    var overlay = document.getElementById('cookie-overlay');
    var card    = document.getElementById('login-card');
    var btnOk   = document.getElementById('btn-accept-cookies');
    var btnNo   = document.getElementById('btn-decline-cookies');

    if (localStorage.getItem(CONSENT_KEY) !== '1') {
        overlay.style.display = 'flex';
        card.classList.add('login-blocked');
    }

    // Accept: record consent + reload so PHP sends a fresh dv_pa CSRF cookie
    // and renders the form with a matching token.
    btnOk.addEventListener('click', function() {
        localStorage.setItem(CONSENT_KEY, '1');
        window.location.reload();
    });

    // Decline: clear JS-accessible storage + navigate away
    btnNo.addEventListener('click', function() {
        localStorage.clear();
        sessionStorage.clear();
        document.cookie.split(';').forEach(function(c) {
            var name = c.trim().split('=')[0];
            if (name) document.cookie = name + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/;SameSite=Strict';
        });
        setTimeout(function() { window.location.href = 'about:blank'; }, 80);
    });
})();

function togglePw(id, btn) {
    var i = document.getElementById(id);
    var showing = i.type === 'text';
    i.type = showing ? 'password' : 'text';
    btn.innerHTML = showing
        ? '<?= addslashes(eyeSvg(true)) ?>'
        : '<?= addslashes(eyeSvg(false)) ?>';
}

var ci = document.querySelector('.code-input');
if (ci) ci.addEventListener('input', function() {
    this.value = this.value.replace(/\D/g, '');
    if (this.value.length === 6) this.closest('form').submit();
});
</script>
</body>
</html>
