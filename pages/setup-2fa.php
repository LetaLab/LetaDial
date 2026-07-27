<?php
declare(strict_types=1);
defined('DIALVAULT_APP') or die();

if (isset($_GET['download_codes'])) {
    $codes = $_SESSION['show_backup_codes'] ?? [];
    if (!empty($codes)) {
        $app_name = APP_NAME;
        $login    = Auth::getPartialUser()['login'] ?? Auth::getUser()['login'] ?? 'user';
        $date     = date('Y-m-d H:i:s');
        $content  = "===========================================\n";
        $content .= "  {$app_name} — 2FA Backup Codes\n";
        $content .= "  Generated: {$date}\n";
        $content .= "  Account:   {$login}\n";
        $content .= "===========================================\n";
        $content .= "IMPORTANT: Each code can only be used ONCE.\n";
        $content .= "Store this file in a password manager.\n";
        $content .= "===========================================\n\n";
        foreach ($codes as $i => $code) {
            $content .= sprintf("  %2d.  %s\n", $i + 1, $code);
        }
        $content .= "\n===========================================\n";
        $content .= "After using all codes, regenerate them in\n";
        $content .= "Settings -> Security -> Manage 2FA.\n";
        $fname = preg_replace('/[^a-z0-9_-]/i', '_', APP_NAME) . '_backup_codes.txt';
        header('Content-Type: text/plain; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"{$fname}\"");
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
    }
    header('Location: /setup-2fa'); exit;
}

$user = Auth::getPartialUser();
if (!$user) { header('Location: /login'); exit; }
if ($user['totp_enabled'] && Auth::isLoggedIn()) { header('Location: /'); exit; }

$error        = '';
$backup_codes = [];
$done         = false;

$secret = Auth::getSetupSecret();
if (!$secret) {
    $secret = TOTP::generateSecret();
    Auth::storeSetupSecret($secret);
}

// URI label is display-only in auth apps — does NOT affect TOTP security.
// Truncate to 20 chars to keep QR at version 7 max (svgSize=53px, easily scannable).
// Without truncation, a 26+ char login → version 8 (svgSize=57px) → hard to scan.
$uri_label  = mb_substr($user['login'], 0, 20);
$otp_uri    = TOTP::uri($secret, $uri_label);
// Format secret as 2 rows of 4 groups for better readability
$groups     = str_split($secret, 4);
$secret_ln1 = implode(' ', array_slice($groups, 0, 4));
$secret_ln2 = implode(' ', array_slice($groups, 4));
$secret_fmt = $secret_ln1 . "\n" . $secret_ln2;
$app_name   = htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8');

// ── Generate QR as PNG via GD ─────────────────────────────────────────────────
// WHY PNG not SVG data URI:
//   Mobile camera apps and authenticator apps scan RASTER pixels, not SVG vectors.
//   data:image/svg+xml in <img> renders visually but is NOT reliably scannable.
//   PNG via GD is universally readable by all QR scanners (iOS, Android, GA).
function qr_png_from_svg(string $data): string
{
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) return '';
    $svg = QRCode::svg($data, 1, 4); // moduleSize=1: each module = 1x1 px rect
    if (!$svg) return '';
    if (!preg_match('/viewBox="0 0 (\d+) \d+"/', $svg, $m)) return '';
    $svgSize = (int)$m[1];
    if ($svgSize < 10) return '';
    preg_match_all('/<rect x="(\d+)" y="(\d+)" width="(\d+)" height="(\d+)"\/>/', $svg, $rects, PREG_SET_ORDER);
    if (empty($rects)) return '';
    $scale   = max(6, (int)ceil(450 / $svgSize));
    $imgSize = $svgSize * $scale;
    $img = @imagecreatetruecolor($imgSize, $imgSize);
    if (!$img) return '';
    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 0, 0, 0);
    imagefilledrectangle($img, 0, 0, $imgSize - 1, $imgSize - 1, $white);
    foreach ($rects as $r) {
        $x1 = (int)$r[1] * $scale;
        $y1 = (int)$r[2] * $scale;
        $w  = (int)$r[3] * $scale;
        imagefilledrectangle($img, $x1, $y1, $x1 + $w - 1, $y1 + $w - 1, $black);
    }
    ob_start();
    imagepng($img, null, 1);
    imagedestroy($img);
    return ob_get_clean() ?: '';
}

$qr_png_bytes = qr_png_from_svg($otp_uri);
$qr_img_src   = $qr_png_bytes
    ? 'data:image/png;base64,' . base64_encode($qr_png_bytes)
    : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::require();
    $code   = preg_replace('/\s/', '', $_POST['code'] ?? '');
    $result = Auth::enable2FA($code);
    if ($result['ok']) {
        $done         = true;
        $backup_codes = $result['backup_codes'];
        $_SESSION['show_backup_codes'] = $backup_codes;
        RateLimit::clear('2fa', $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    } else {
        $error = $result['error'];
    }
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
$icon_url = h(APP_URL . '/assets/icons/icon-192.png');
$secret_has_o = str_contains($secret, 'O');
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Set up 2FA — <?= $app_name ?></title>
<link rel="icon" type="image/png" href="/assets/icons/favicon.png" sizes="48x48">
<link rel="icon" type="image/svg+xml" href="/assets/icons/favicon.svg">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
<link rel="manifest" href="/assets/manifest.json">
<link rel="stylesheet" href="/assets/css/design-system.css">
<link rel="stylesheet" href="/assets/css/pages/setup-2fa.css">
</head>
<body>
<div class="page-card">

    <?php if ($done): ?>
    <div class="logo">
        <img src="<?= $icon_url ?>" alt="<?= $app_name ?>">
        <h1><?= $app_name ?></h1>
    </div>
    <div class="page-body">
        <div class="success-icon">🎉</div>
        <h2 style="text-align:center;margin-bottom:.5rem">2FA is now active!</h2>
        <p class="text-center text-muted" style="font-size:.875rem;margin-bottom:1rem">
            Save these backup codes somewhere safe.<br>
            <strong>Each code can only be used once.</strong>
        </p>
        <div class="alert alert-warning" style="margin-bottom:1rem">
            <span class="alert-icon">⚠</span>
            <span>Store in a password manager or print. They will <strong>not be shown again.</strong></span>
        </div>
        <div class="backup-grid" id="backup-grid">
            <?php foreach ($backup_codes as $code): ?>
            <div class="backup-code"><?= h($code) ?></div>
            <?php endforeach; ?>
        </div>
        <div class="action-row">
            <button type="button" class="btn btn-ghost" id="btn-download-backup-codes">↓ Download .txt</button>
            <a href="/" class="btn btn-primary">Continue →</a>
        </div>
    </div>

    <?php else: ?>
    <div class="logo">
        <img src="<?= $icon_url ?>" alt="<?= $app_name ?>">
        <h1><?= $app_name ?></h1>
        <p>Set up two-factor authentication</p>
    </div>

    <?php if ($error): ?>
    <div style="padding:0 2rem">
        <div class="alert alert-error"><span class="alert-icon">⚠</span><span><?= h($error) ?></span></div>
    </div>
    <?php endif; ?>

    <div class="page-body">
        <div class="setup-steps">

            <div class="setup-step">
                <div class="step-num">1</div>
                <div class="step-body">
                    <strong>Install an authenticator app</strong>
                    <p>Google Authenticator, Bitwarden, Authy, or any TOTP-compatible app.</p>
                </div>
            </div>

            <div class="setup-step">
                <div class="step-num">2</div>
                <div class="step-body">
                    <strong>Scan the QR code or enter the key manually</strong>
                    <p>Open your app, tap <em>+</em>, then <em>Scan QR code</em>:</p>

                    <?php if ($qr_img_src): ?>
                    <div class="qr-container">
                        <img src="<?= $qr_img_src ?>"
                             style="display:block;width:min(380px,100%);height:auto;image-rendering:pixelated;image-rendering:crisp-edges;border:0;border-radius:0;max-width:100%;"
                             alt="QR code — scan with your authenticator app">
                        <div class="qr-label">Scan with your authenticator app</div>
                    </div>
                    <?php else: ?>
                    <div class="qr-container">
                        <p style="color:var(--text-muted);font-size:.85rem">QR image unavailable — enter the key manually below.</p>
                    </div>
                    <?php endif; ?>

                    <p style="margin-top:.75rem;margin-bottom:.25rem;font-size:.84rem;color:var(--text-muted)">
                        Or enter this key manually:
                    </p>
                    <div class="secret-box" id="secret-box" title="Click to select all"><?= h($secret_ln1) ?><br><?= h($secret_ln2) ?></div>
                    <?php if ($secret_has_o): ?>
                    <p class="secret-note">⚠ Key contains <strong>letter O</strong> (not digit zero) — in this font: <strong style="font-family:'Courier New',monospace;font-size:1rem">O</strong> ≠ <strong style="font-family:'Courier New',monospace;font-size:1rem">0</strong></p>
                    <?php endif; ?>
                    <div class="copy-row">
                        <button type="button" class="copy-btn" id="btn-copy-secret">📋 Copy key</button>
                        <span style="color:var(--border);font-size:.8rem">|</span>
                        <a href="<?= h($otp_uri) ?>" class="otp-link">📱 Open in app (mobile)</a>
                    </div>
                </div>
            </div>

            <div class="setup-step">
                <div class="step-num">3</div>
                <div class="step-body">
                    <strong>Verify the 6-digit code</strong>
                    <p>Enter the code shown in your app to confirm everything works.</p>
                </div>
            </div>

        </div>

        <form method="post" autocomplete="off">
            <?= CSRF::field() ?>
            <div class="form-group">
                <label class="form-label">Verification code</label>
                <input type="text" name="code" class="form-input code-input"
                       inputmode="numeric" maxlength="6" pattern="\d{6}"
                       placeholder="000000" autocomplete="one-time-code" autofocus required>
            </div>
            <button type="submit" class="btn btn-primary btn-block btn-lg">Activate 2FA →</button>
        </form>
    </div>
    <?php endif; ?>

</div>

<script nonce="<?= CSP::nonce() ?>">
(function(){ const t=localStorage.getItem('dv-theme'); if(t) document.documentElement.setAttribute('data-theme',t); })();

const RAW_SECRET = '<?= preg_replace('/\s+/', '', $secret) ?>';

function copySecret() {
    navigator.clipboard?.writeText(RAW_SECRET).then(() => {
        const btn = document.querySelector('.copy-btn');
        const orig = btn.textContent;
        btn.textContent = '✓ Copied!';
        btn.style.color = 'var(--success)';
        setTimeout(() => { btn.textContent = orig; btn.style.color = ''; }, 2000);
    }).catch(() => {
        const box = document.getElementById('secret-box');
        const range = document.createRange();
        range.selectNode(box);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);
    });
}

document.querySelector('.code-input')?.addEventListener('input', function() {
    this.value = this.value.replace(/\D/g, '');
    if (this.value.length === 6) this.closest('form').submit();
});

function downloadBackupCodes() {
    const codes = [...document.querySelectorAll('#backup-grid .backup-code')]
        .map((el, i) => '  ' + String(i + 1).padStart(2, ' ') + '.  ' + el.textContent.trim())
        .join('\n');
    const appName = <?= json_encode(APP_NAME) ?>;
    const now = new Date().toISOString().slice(0, 19).replace('T', ' ');
    const content = [
        '===========================================',
        '  ' + appName + ' — 2FA Backup Codes',
        '  Generated: ' + now,
        '===========================================',
        'IMPORTANT: Each code can only be used ONCE.',
        'Store this file in a password manager.',
        '===========================================',
        '',
        codes,
        '',
        '===========================================',
        'After using all codes, regenerate them in',
        'Settings -> Security -> Manage 2FA.',
    ].join('\n');
    const blob = new Blob([content], { type: 'text/plain' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = appName.replace(/[^a-z0-9_-]/gi, '_') + '_backup_codes.txt';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

// ── Event listeners (CSP: bez inline onXXX=, Krok 4a) ──────────────────────────
document.getElementById('btn-copy-secret')?.addEventListener('click', copySecret);
document.getElementById('btn-download-backup-codes')?.addEventListener('click', downloadBackupCodes);
</script>
</body>
</html>
