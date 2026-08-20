<?php
/**
 * LetaDial — Account Activation
 *
 * VI.3: two-step GET-then-POST flow, mirroring reset_password_page.php /
 * setup_account_page.php. Previously a plain GET to this URL activated the
 * account immediately — any system that follows links automatically before
 * a person consciously clicks them (a mail security gateway like Safe
 * Links / URL Defense, an antivirus link-scanner, a mail client's link
 * preview, a browser prefetch, a chat app unfurling the link for a
 * preview) could silently consume the one-time activation token. The
 * account owner would then find their invite/activation link already
 * "used" without ever having clicked it themselves.
 *
 * Fix: GET only ever renders a confirmation screen with a real <form>. The
 * actual UPDATE (activation) only happens on POST, once CSRF::require()
 * has verified a same-origin, deliberately-submitted request — the class
 * of automated visitor above never submits HTML forms, so this closes the
 * gap without changing anything about the token itself (still 64 hex
 * chars, still single-use, still looked up the same way).
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die();

// Pre-warm CSRF before any HTML output — mirrors reset_password_page.php /
// forgot_password_page.php / setup_account_page.php for the same pre-auth,
// no-DB-session context.
$_csrf_prewarm = CSRF::token();

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(400);
    die(activatePage('error', 'Invalid activation link.'));
}

// Look up token
$user = DB::row(
    "SELECT id, login, email_verified FROM users
     WHERE activation_token = ? LIMIT 1",
    [$token]
);

if (!$user) {
    die(activatePage('error', 'This activation link is invalid or has already been used.'));
}

if ($user['email_verified']) {
    die(activatePage('already', 'Your account is already activated. You can sign in.'));
}

// Token is valid and the account is still pending — GET renders the
// confirm form below; only a POST (with CSRF) actually flips the switch.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::require();

    DB::run(
        "UPDATE users SET email_verified = 1, activation_token = NULL WHERE id = ?",
        [$user['id']]
    );

    echo activatePage('success', '');
    exit;
}

echo activatePage('confirm', '', $token);
exit;

// ── Page renderer ─────────────────────────────────────────────────────────────
function activatePage(string $status, string $message, string $token = ''): string
{
    $app = htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8');

    [$icon, $heading, $body, $btn] = match ($status) {
        'success' => [
            '✓',
            'Account activated!',
            'Your account has been successfully verified. You can now sign in.',
            '<a href="/login" class="btn">Sign in →</a>',
        ],
        'already' => [
            '◈',
            'Already activated',
            htmlspecialchars($message, ENT_QUOTES, 'UTF-8'),
            '<a href="/login" class="btn">Sign in →</a>',
        ],
        'confirm' => [
            '✉',
            'Activate your account',
            'Click the button below to finish activating your account.',
            '<form method="post">' . CSRF::field()
                . '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
                . '<button type="submit" class="btn">Activate my account →</button></form>',
        ],
        default => [
            '✗',
            'Activation failed',
            htmlspecialchars($message, ENT_QUOTES, 'UTF-8'),
            '<a href="/login" class="btn btn-ghost">Go to login</a>',
        ],
    };

    $cls = match ($status) {
        'success' => 'success',
        'already', 'confirm' => 'info',
        default => 'error',
    };

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Account Activation — {$app}</title>
<link rel="stylesheet" href="/assets/css/pages/activate.css">
</head>
<body>
<div class="card">
  <div class="icon {$cls}">{$icon}</div>
  <h2>{$heading}</h2>
  <p>{$body}</p>
  {$btn}
</div>
</body>
</html>
HTML;
}
