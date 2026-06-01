<?php
/**
 * LetaDial — Login Page (sesja 068: self-registration added)
 *
 * Handles three steps: login, 2FA verification, registration.
 * Registration is shown only if settings.registration_enabled = '1'.
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die();

if (Auth::isLoggedIn()) { header('Location: /'); exit; }

// ── PRE-WARM CSRF TOKEN ───────────────────────────────────────────────────────
// MUST happen before any HTML output. For pre-auth (no DB session), this
// triggers CSRF::preAuthToken() which calls setcookie('dv_pa', ...).
$_csrf_prewarm = CSRF::token();
// ─────────────────────────────────────────────────────────────────────────────

$error   = '';
$success = '';
$step    = 'login';

$partial = Auth::getPartialUser();
if ($partial && !Auth::isLoggedIn()) {
    $step = $partial['totp_enabled'] ? 'totp' : 'setup';
    if ($step === 'setup') { header('Location: /setup-2fa'); exit; }
}

// ── Check registration enabled ────────────────────────────────────────────────
$registration_enabled = (DB::val(
    "SELECT value FROM settings WHERE key_name = 'registration_enabled'"
) ?? '1') === '1';

// ── Handle POST ───────────────────────────────────────────────────────────────
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

    // ── Registration ──────────────────────────────────────────────────────────
    if ($action === 'register' && $step === 'login') {
        if (!$registration_enabled) {
            $error = 'Registration is currently disabled.';
        } else {
            $reg_result = Auth::register(
                trim($_POST['reg_login']   ?? ''),
                trim($_POST['reg_email']   ?? ''),
                $_POST['reg_password']     ?? '',
                $_POST['reg_confirm']      ?? ''
            );
            if (!$reg_result['ok']) {
                $error = $reg_result['error'];
                $step  = 'register';
            } elseif ($reg_result['auto_verified'] ?? false) {
                // SMTP disabled — account immediately active
                $success = 'Account created! You can sign in now.';
                $step    = 'login';
            } else {
                // SMTP enabled — activation email sent
                $success = 'Account created! Check your email to activate your account.';
                $step    = 'login';
            }
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
<title><?= $step === 'register' ? 'Create account' : 'Sign in' ?> — <?= $app_name ?></title>
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
.register-switch { text-align:center; margin-top:1.25rem; font-size:.85rem; color:var(--text-muted); }
.register-switch a { color:var(--primary); cursor:pointer; text-decoration:none; }
.register-switch a:hover { text-decoration:underline; }
.pw-strength { height:4px; border-radius:var(--radius-full); background:var(--border); margin-top:var(--space-2); overflow:hidden; }
.pw-strength-bar { height:100%; border-radius:var(--radius-full); transition:width 0.3s ease, background-color 0.3s ease; width:0%; }
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
        <p id="logo-subtitle"><?php
            if ($step === 'register') echo 'Create your account';
            elseif ($step === 'totp') echo 'Two-factor authentication';
            else echo 'Sign in to your account';
        ?></p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-error" style="margin-bottom:1.25rem">
        <span class="alert-icon">&#9888;</span><span><?= h($error) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert alert-success" style="margin-bottom:1.25rem">
        <span class="alert-icon">&#10003;</span><span><?= h($success) ?></span>
    </div>
    <?php endif; ?>

    <?php if ($step === 'login'): ?>
    <!-- ── LOGIN FORM ── -->
    <form method="post" autocomplete="on" id="form-login">
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

    <?php if ($registration_enabled): ?>
    <div class="register-switch">
        Don't have an account? <a href="#" onclick="switchToRegister(event)">Create one</a>
    </div>
    <?php endif; ?>

    <!-- ── REGISTER FORM (hidden by default, shown via JS or when $step==='register') ── -->
    <?php if ($registration_enabled): ?>
    <form method="post" autocomplete="off" id="form-register" style="display:none">
        <?= CSRF::field() ?>
        <input type="hidden" name="action" value="register">
        <div class="form-group">
            <label class="form-label" for="reg_login">Login</label>
            <input type="text" id="reg_login" name="reg_login" class="form-input"
                   autocomplete="username" maxlength="50"
                   value="<?= h($_POST['reg_login'] ?? '') ?>"
                   placeholder="letters, numbers, underscore (3–50)" required>
            <div class="form-hint">Letters, numbers, underscore. No spaces.</div>
        </div>
        <div class="form-group">
            <label class="form-label" for="reg_email">Email address</label>
            <input type="email" id="reg_email" name="reg_email" class="form-input"
                   autocomplete="email"
                   value="<?= h($_POST['reg_email'] ?? '') ?>"
                   placeholder="you@example.com" required>
        </div>
        <div class="form-group">
            <label class="form-label" for="reg_password">Password</label>
            <div class="input-wrap">
                <input type="password" id="reg_password" name="reg_password" class="form-input"
                       autocomplete="new-password" placeholder="Min. 12 characters" required>
                <button type="button" class="eye-btn" onclick="togglePw('reg_password',this)" aria-label="Show/hide">
                    <?= eyeSvg(true) ?>
                </button>
            </div>
            <div class="pw-strength">
                <div class="pw-strength-bar" id="reg-pw-bar"></div>
            </div>
            <div class="form-hint" id="reg-pw-label"></div>
        </div>
        <div class="form-group">
            <label class="form-label" for="reg_confirm">Confirm password</label>
            <div class="input-wrap">
                <input type="password" id="reg_confirm" name="reg_confirm" class="form-input"
                       autocomplete="new-password" placeholder="Repeat password" required>
                <button type="button" class="eye-btn" onclick="togglePw('reg_confirm',this)" aria-label="Show/hide">
                    <?= eyeSvg(true) ?>
                </button>
            </div>
            <div class="form-hint" id="reg-confirm-hint" style="display:none;color:var(--error)">Passwords do not match.</div>
        </div>
        <button type="submit" class="btn btn-primary btn-block btn-lg">Create account &rarr;</button>
        <div class="register-switch" style="margin-top:1rem">
            Already have an account? <a href="#" onclick="switchToLogin(event)">Sign in</a>
        </div>
    </form>
    <?php endif; ?>

    <?php elseif ($step === 'register' && $registration_enabled): ?>
    <!-- ── REGISTER FORM (server-side rendered when POST failed validation) ── -->
    <form method="post" autocomplete="off">
        <?= CSRF::field() ?>
        <input type="hidden" name="action" value="register">
        <div class="form-group">
            <label class="form-label" for="reg_login">Login</label>
            <input type="text" id="reg_login" name="reg_login" class="form-input"
                   autocomplete="username" maxlength="50"
                   value="<?= h($_POST['reg_login'] ?? '') ?>"
                   placeholder="letters, numbers, underscore (3–50)" autofocus required>
            <div class="form-hint">Letters, numbers, underscore. No spaces.</div>
        </div>
        <div class="form-group">
            <label class="form-label" for="reg_email">Email address</label>
            <input type="email" id="reg_email" name="reg_email" class="form-input"
                   autocomplete="email"
                   value="<?= h($_POST['reg_email'] ?? '') ?>"
                   placeholder="you@example.com" required>
        </div>
        <div class="form-group">
            <label class="form-label" for="reg_password">Password</label>
            <div class="input-wrap">
                <input type="password" id="reg_password" name="reg_password" class="form-input"
                       autocomplete="new-password" placeholder="Min. 12 characters" required>
                <button type="button" class="eye-btn" onclick="togglePw('reg_password',this)" aria-label="Show/hide">
                    <?= eyeSvg(true) ?>
                </button>
            </div>
            <div class="pw-strength">
                <div class="pw-strength-bar" id="reg-pw-bar"></div>
            </div>
            <div class="form-hint" id="reg-pw-label"></div>
        </div>
        <div class="form-group">
            <label class="form-label" for="reg_confirm">Confirm password</label>
            <div class="input-wrap">
                <input type="password" id="reg_confirm" name="reg_confirm" class="form-input"
                       autocomplete="new-password" placeholder="Repeat password" required>
                <button type="button" class="eye-btn" onclick="togglePw('reg_confirm',this)" aria-label="Show/hide">
                    <?= eyeSvg(true) ?>
                </button>
            </div>
            <div class="form-hint" id="reg-confirm-hint" style="display:none;color:var(--error)">Passwords do not match.</div>
        </div>
        <button type="submit" class="btn btn-primary btn-block btn-lg">Create account &rarr;</button>
        <div class="register-switch" style="margin-top:1rem">
            Already have an account? <a href="/login">Sign in</a>
        </div>
    </form>

    <?php elseif ($step === 'totp'): ?>
    <!-- ── 2FA FORM ── -->
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

    if (!overlay) return;

    if (localStorage.getItem(CONSENT_KEY) !== '1') {
        overlay.style.display = 'flex';
        card.classList.add('login-blocked');
    }

    btnOk.addEventListener('click', function() {
        localStorage.setItem(CONSENT_KEY, '1');
        window.location.reload();
    });

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

// ── 2FA code auto-submit ───────────────────────────────────────────────────────
var ci = document.querySelector('.code-input');
if (ci) ci.addEventListener('input', function() {
    this.value = this.value.replace(/\D/g, '');
    if (this.value.length === 6) this.closest('form').submit();
});

// ── Password strength meter ────────────────────────────────────────────────────
var PW_RULES  = <?= $pw_rules ?>;
var levels    = ['', 'Too short', 'Weak', 'Fair', 'Strong'];
var levelClrs = ['', '#E53E3E', '#D69E2E', '#D69E2E', '#1D5C42'];
var levelPct  = ['', '25%', '50%', '75%', '100%'];

function calcStrength(pw) {
    if (!pw || pw.length < (PW_RULES.minLength || 12)) return 1;
    var s = 0;
    if (/[A-Z]/.test(pw)) s++;
    if (/[a-z]/.test(pw)) s++;
    if (/[0-9]/.test(pw)) s++;
    if (/[^A-Za-z0-9]/.test(pw)) s++;
    if (pw.length >= 16) s = Math.min(s + 1, 4);
    return Math.max(1, Math.min(s, 4));
}

var regPwInput = document.getElementById('reg_password');
var regPwBar   = document.getElementById('reg-pw-bar');
var regPwLabel = document.getElementById('reg-pw-label');
var regConfirm = document.getElementById('reg_confirm');
var regConfHint = document.getElementById('reg-confirm-hint');

if (regPwInput) {
    regPwInput.addEventListener('input', function() {
        var pw = this.value;
        if (!pw) { regPwBar.style.width='0'; regPwLabel.textContent=''; return; }
        var lvl = calcStrength(pw);
        regPwBar.style.width = levelPct[lvl];
        regPwBar.style.background = levelClrs[lvl];
        regPwLabel.textContent = levels[lvl];
        regPwLabel.style.color = levelClrs[lvl];
        checkRegMatch();
    });
}
if (regConfirm) {
    regConfirm.addEventListener('input', checkRegMatch);
}
function checkRegMatch() {
    if (!regConfirm || !regPwInput) return;
    if (!regConfirm.value) { if (regConfHint) regConfHint.style.display='none'; return; }
    if (regConfHint) regConfHint.style.display = regPwInput.value === regConfirm.value ? 'none' : '';
}

// ── Login / Register form toggle ──────────────────────────────────────────────
function switchToRegister(e) {
    e.preventDefault();
    var fl = document.getElementById('form-login');
    var fr = document.getElementById('form-register');
    var sub = document.getElementById('logo-subtitle');
    if (!fr) return;
    if (fl) fl.style.display = 'none';
    fr.style.display = '';
    if (sub) sub.textContent = 'Create your account';
    var rl = document.getElementById('reg_login');
    if (rl) rl.focus();
    // Hide switch link under login form if present
    var sw = document.querySelector('.register-switch');
    if (sw && sw.closest('#form-login') === null) sw.style.display = 'none';
}
function switchToLogin(e) {
    e.preventDefault();
    var fl = document.getElementById('form-login');
    var fr = document.getElementById('form-register');
    var sub = document.getElementById('logo-subtitle');
    if (fl) fl.style.display = '';
    if (fr) fr.style.display = 'none';
    if (sub) sub.textContent = 'Sign in to your account';
    var sw = document.querySelector('.register-switch');
    if (sw) sw.style.display = '';
    var lf = document.getElementById('lf');
    if (lf) lf.focus();
}

<?php if ($step === 'register' && $registration_enabled): ?>
// Server sent us back to register step (validation failed) — show strength meter
document.addEventListener('DOMContentLoaded', function() {
    var pw = document.getElementById('reg_password');
    if (pw) pw.focus();
});
<?php endif; ?>
</script>
</body>
</html>
