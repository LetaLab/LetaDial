<?php
/**
 * LetaDial — Confirm Email Change (sesja 066)
 *
 * GET  /confirm-email?token=XXX   — validate token, render a confirm form
 * POST /confirm-email             — validate token + CSRF, apply the change
 *
 * VI.3: two-step GET-then-POST flow, mirroring reset_password_page.php /
 * setup_account_page.php / activate_page.php (see that file for the parallel
 * fix and full shared rationale). Previously a plain GET to this URL both
 * swapped the user's email address AND called Auth::logoutAllSessions() —
 * any system that follows links automatically before a person consciously
 * clicks them (mail security gateway / antivirus scanner / link-preview /
 * prefetch) could silently apply the change and force-sign-out every
 * session on the account, purely from the confirmation email landing in an
 * inbox that such a system scans.
 *
 * Fix: GET only ever renders a confirmation screen with a real <form>. The
 * email swap + Auth::logoutAllSessions() only run on POST, once
 * CSRF::require() has verified a same-origin, deliberately-submitted
 * request. Token validation (format, existence, expiry, matching
 * email_pending) runs identically on both GET and POST via the shared block
 * below, so a token that expired or was consumed between the two requests
 * is still caught correctly.
 *
 * SEC-110: the "apply the change" UPDATE is wrapped in try/catch(PDOException)
 * — the $taken SELECT-based pre-check just above it is not atomic with this
 * write, and email_pending itself carries no UNIQUE constraint, so two users
 * confirming the same target address around the same time could otherwise hit
 * the uq_email UNIQUE KEY as an uncaught exception instead of the normal
 * "already taken" response. See the inline comment on that branch, and
 * auth_src.php's docblock for the same pattern applied to registration.
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die();

// Pre-warm CSRF before any HTML output
$_csrf_prewarm = CSRF::token();

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$done  = false;
$error = '';
$user  = null;

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
    }
}

// Token format/existence/expiry/pending-change all check out — GET renders
// the confirm form below; only a POST (with CSRF) actually applies anything.
$token_valid = ($user !== null && $error === '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token_valid) {
    CSRF::require();

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
        $error       = 'This email address has already been taken by another account. '
                     . 'Please request a new email change from Settings.';
        $token_valid = false;
    } else {
        // Apply the change.
        //
        // SEC-110: the $taken SELECT immediately above is not atomic
        // with this UPDATE. There is no UNIQUE constraint on
        // email_pending (install.php) — only on the real `email` column
        // — so two different users can each set the SAME email_pending
        // and both still pass the $taken check above before either one
        // has actually applied its change. Whichever one loses that
        // narrow race hits the uq_email UNIQUE KEY here instead, which
        // (db_src.php sets PDO::ATTR_ERRMODE_EXCEPTION) would otherwise
        // escape as an uncaught PDOException. Catch it and fall back to
        // the exact same "already taken" outcome the $taken branch above
        // already uses, rather than letting a losing confirmation click
        // surface a raw error.
        $duplicateOnApply = false;
        try {
            DB::run(
                "UPDATE users
                 SET email               = email_pending,
                     email_pending       = NULL,
                     email_change_token  = NULL,
                     email_change_expires = NULL
                 WHERE id = ?",
                [$user['id']]
            );
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e; // any other DB error stays a real, loud failure
            }
            $duplicateOnApply = true;
        }

        if ($duplicateOnApply) {
            // Clear the pending change — it can no longer be applied.
            DB::run(
                "UPDATE users SET email_pending = NULL, email_change_token = NULL,
                 email_change_expires = NULL WHERE id = ?",
                [$user['id']]
            );
            $error       = 'This email address has already been taken by another account. '
                         . 'Please request a new email change from Settings.';
            $token_valid = false;
        } else {
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
<script nonce="<?= CSP::nonce() ?>">(function(){var t=localStorage.getItem('dv-theme');if(t)document.documentElement.setAttribute('data-theme',t)})();</script>
<link rel="stylesheet" href="/assets/css/pages/confirm-email.css">
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

    <?php elseif ($token_valid): ?>
        <div class="ce-icon">✉️</div>
        <div class="ce-title">Confirm your new email address</div>
        <p class="ce-sub">
            Click the button below to apply the change to<br>
            <strong><?= h($user['email_pending']) ?></strong>.<br>
            All sessions will be signed out for security.
        </p>
        <form method="post">
            <?= CSRF::field() ?>
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <button type="submit" class="btn btn-primary" style="min-width:180px">Confirm email change →</button>
        </form>

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
