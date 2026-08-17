<?php
declare(strict_types=1);
defined('DIALVAULT_APP') or die();

$token = trim($_GET['token'] ?? '');

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

// Activate
DB::run(
    "UPDATE users SET email_verified = 1, activation_token = NULL WHERE id = ?",
    [$user['id']]
);

echo activatePage('success', '');
exit;

// ── Page renderer ─────────────────────────────────────────────────────────────
function activatePage(string $status, string $message): string
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
        default => [
            '✗',
            'Activation failed',
            htmlspecialchars($message, ENT_QUOTES, 'UTF-8'),
            '<a href="/login" class="btn btn-ghost">Go to login</a>',
        ],
    };

    $cls = $status === 'success' ? 'success' : ($status === 'already' ? 'info' : 'error');

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
