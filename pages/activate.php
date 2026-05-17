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
<style>
:root{--primary:#4f46e5;--success:#16a34a;--error:#dc2626;--bg:#f0f2f5;--surface:#fff;--border:#e2e8f0;--text:#1a202c;--muted:#64748b}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,-apple-system,'Segoe UI',sans-serif;background:var(--bg);
     min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem}
.card{background:var(--surface);border-radius:12px;box-shadow:0 2px 24px rgba(0,0,0,.08);
      padding:2.5rem 2rem;max-width:420px;width:100%;text-align:center}
.icon{width:64px;height:64px;border-radius:50%;display:inline-flex;align-items:center;
      justify-content:center;font-size:1.8rem;margin-bottom:1.25rem}
.icon.success{background:#f0fdf4;color:var(--success)}
.icon.error  {background:#fef2f2;color:var(--error)}
.icon.info   {background:#f0f0ff;color:var(--primary)}
h2{font-size:1.2rem;margin-bottom:.75rem}
p{color:var(--muted);line-height:1.6;font-size:.9rem;margin-bottom:1.5rem}
.btn{display:inline-block;padding:.7rem 1.5rem;background:var(--primary);color:#fff;
     border-radius:8px;font-weight:600;text-decoration:none;font-size:.9rem;
     transition:background .15s}
.btn:hover{background:#4338ca}
.btn-ghost{background:transparent;color:var(--muted);border:1.5px solid var(--border);
           padding:.65rem 1.4rem}
.btn-ghost:hover{background:var(--bg)}
</style>
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
