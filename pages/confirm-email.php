<?php
/**
 * LetaDial — Confirm Email Change (sesja 066)
 *
 * GET /confirm-email?token=XXX
 * Validates the email change token and updates the user's email.
 * Invalidates all sessions after success (forces re-login with new email).
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die();

$token = trim($_GET['token'] ?? '');
$done  = false;
$error = '';

if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    $error = 'Invalid or malformed confirmation link.';
} else {
    $user = DB::row(
        "SELECT id, email, email_pending, email_change_expires
         FROM users
         WHERE email_change_token = ? AND email_change_expires > NOW()
         LIMIT 1",
        [$token]
    );

    if (!$user) {
        $error = 'This confirmation link is invalid or has expired. Links are valid for 1 hour.';
    } elseif (!$user['email_pending']) {
        $error = 'No pending email change found for this link.';
    } else {
        // Race condition guard — check if the new email is still available
        $taken = DB::val(
            "SELECT id FROM users WHERE email = ? AND id != ?",
            [$user['email_pending'], $user['id']]
        );

        if ($taken) {
            // Clear the pending change — it can no longer be applied
            DB::run(
                "UPDATE users SET email_pending = NULL, email_change_token = NULL,
                 email_change_expires = NULL WHERE id = ?",
                [$user['id']]
            );
            $error = 'This email address has already been taken by another account. '
                   . 'Please request a new email change from Settings.';
        } else {
            // Apply the change
            DB::run(
                "UPDATE users
                 SET email               = email_pending,
                     email_pending       = NULL,
                     email_change_token  = NULL,
                     email_change_expires = NULL
                 WHERE id = ?",
                [$user['id']]
            );

            // Invalidate all existing sessions — user must log in again with new email
            Auth::logoutAllSessions($user['id']);
            $done = true;
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
<title>Confirm Email — <?= $app_name ?></title>
<link rel="shortcut icon" href="/assets/icons/favicon.png" type="image/png">
<link rel="icon" href="/assets/icons/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
<link rel="manifest" href="/assets/manifest.json">
<link rel="stylesheet" href="/assets/css/design-system.css">
<script>(function(){var t=localStorage.getItem('dv-theme');if(t)document.documentElement.setAttribute('data-theme',t)})();</script>
<style>
body { display:flex; align-items:center; justify-content:center; min-height:100vh; padding:1.5rem; }
.ce-card {
    width:100%; max-width:440px;
    background:var(--surface); border:1px solid var(--border);
    border-radius:var(--radius-lg); box-shadow:var(--shadow-xl);
    padding:2.5rem 2rem 2rem; text-align:center;
}
.ce-logo { width:72px; height:72px; object-fit:contain; margin-bottom:.75rem;
    filter:drop-shadow(0 2px 8px rgba(0,0,0,.15)); }
.ce-icon { font-size:3.5rem; margin:1rem 0; line-height:1; }
.ce-title { font-size:1.2rem; font-weight:700; margin-bottom:.75rem; }
.ce-sub { color:var(--text-muted); font-size:.875rem; line-height:1.6; margin-bottom:1.5rem; }
.back-link { display:block; margin-top:1.25rem; font-size:.82rem;
    color:var(--text-muted); text-decoration:none; }
.back-link:hover { color:var(--primary); }
</style>
</head>
<body>
<div class="ce-card">
    <img src="<?= $icon_url ?>" alt="<?= $app_name ?>" class="ce-logo">

    <?php if ($done): ?>
        <div class="ce-icon">✅</div>
        <div class="ce-title">Email address updated!</div>
        <p class="ce-sub">
            Your email address has been changed successfully.<br>
            All sessions have been signed out for security.<br>
            Please sign in again with your new email.
        </p>
        <a href="/login" class="btn btn-primary" style="min-width:180px">Sign in →</a>

    <?php else: ?>
        <div class="ce-icon">⏰</div>
        <div class="ce-title">Link expired or invalid</div>
        <p class="ce-sub"><?= h($error) ?></p>
        <a href="/settings" class="btn btn-primary" style="min-width:180px">← Back to Settings</a>

    <?php endif; ?>

    <a href="/login" class="back-link">Go to login page</a>
</div>
</body>
</html>
