<?php
/**
 * LetaDial — Forgot Password (sesja 063)
 *
 * GET  /forgot-password  — show form
 * POST /forgot-password  — send reset email
 *
 * Security:
 *   - Rate limited: 3 requests per hour per IP
 *   - Always shows same success message (prevents email enumeration)
 *   - Token: 32 random bytes = 64 hex chars, expires in 1 hour
 *   - CSRF required on POST
 *   - Works only if SMTP_ENABLED = true
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die();

if (Auth::isLoggedIn()) { header('Location: /'); exit; }

// Pre-warm CSRF before any HTML output
$_csrf_prewarm = CSRF::token();

$sent  = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::require();

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Rate limit: 3 requests per hour per IP
    if (RateLimit::check('forgot_pw', $ip, 3, 3600, 3600)) {
        $error = 'Too many requests. Please wait before trying again.';
    } else {
        $login = trim($_POST['login'] ?? '');

        if (!$login) {
            $error = 'Please enter your login or email address.';
        } else {
            // Look up user — support both login and email
            $user = DB::row(
                "SELECT id, email, email_verified FROM users
                 WHERE (login = ? OR email = ?) AND email_verified = 1
                 LIMIT 1",
                [$login, $login]
            );

            if ($user) {
                // Generate token
                $token   = bin2hex(random_bytes(32)); // 64 hex chars
                $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour

                DB::run(
                    "UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?",
                    [$token, $expires, $user['id']]
                );

                // Send email — ignore result (don't leak whether send succeeded)
                if (defined('SMTP_ENABLED') && SMTP_ENABLED) {
                    Mailer::sendPasswordReset($user['email'], $token);
                }
            }

            // Always show success — never reveal if account exists
            $sent = true;
        }
    }
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
$app_name = h(APP_NAME);
$icon_url = h(APP_URL . '/assets/icons/icon-192.png');
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Forgot password — <?= $app_name ?></title>
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
</style>
<script>(function(){ var t=localStorage.getItem('dv-theme'); if(t) document.documentElement.setAttribute('data-theme',t); })();</script>
</head>
<body>

<div class="login-card">
    <div class="logo">
        <img src="<?= $icon_url ?>" alt="<?= $app_name ?>" class="logo-img">
        <h1><?= $app_name ?></h1>
        <p>Reset your password</p>
    </div>

    <?php if ($sent): ?>

    <div class="success-box">
        <div class="success-icon">📧</div>
        <h2>Check your email</h2>
        <p>
            If an account exists for that login or email address,
            we've sent a password reset link. It expires in <strong>1 hour</strong>.
        </p>
        <p style="margin-top:.75rem;font-size:.8rem;color:var(--text-faint)">
            Don't see it? Check your spam folder.
        </p>
    </div>
    <a href="/login" class="back-link">← Back to sign in</a>

    <?php else: ?>

    <?php if ($error): ?>
    <div class="alert alert-error" style="margin-bottom:1.25rem">
        <span class="alert-icon">&#9888;</span><span><?= h($error) ?></span>
    </div>
    <?php endif; ?>

    <?php if (!defined('SMTP_ENABLED') || !SMTP_ENABLED): ?>
    <div class="alert alert-warning" style="margin-bottom:1.25rem">
        <span class="alert-icon">⚠</span>
        <span>Email is not configured on this server. Contact your administrator to reset your password manually.</span>
    </div>
    <?php else: ?>

    <p style="font-size:.875rem;color:var(--text-muted);margin-bottom:1.5rem;line-height:1.6">
        Enter your login or email address and we'll send you a link to reset your password.
    </p>

    <form method="post" autocomplete="on">
        <?= CSRF::field() ?>
        <div class="form-group">
            <label class="form-label" for="login">Login or email</label>
            <input type="text" id="login" name="login" class="form-input"
                   autocomplete="username email"
                   value="<?= h($_POST['login'] ?? '') ?>"
                   placeholder="your login or email"
                   autofocus required>
        </div>
        <button type="submit" class="btn btn-primary btn-block btn-lg" style="margin-top:.25rem">
            Send reset link →
        </button>
    </form>

    <?php endif; ?>

    <a href="/login" class="back-link">← Back to sign in</a>

    <?php endif; ?>
</div>

</body>
</html>
