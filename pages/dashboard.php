<?php
/**
 * LetaDial — Dashboard (sesja 059 + 071b + 072 + 078)
 * sesja 059: update notification banner for admin
 * sesja 071b: custom primary color per theme
 * sesja 072: custom bg + text colors per theme
 * sesja 078: avatar shown in topbar (desktop + mobile menu) instead of 👤 emoji
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die();

$user = Auth::requireLogin();

// Admin MUST have 2FA
$needs_2fa_setup = ($user['totp_required'] && !$user['totp_enabled'])
                || ($user['role'] === 'admin' && !$user['totp_enabled']);
if ($needs_2fa_setup) { header('Location: /setup-2fa'); exit; }

$backup_warning = !empty($_GET['bcu']);
$app_name       = htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8');
$user_login     = htmlspecialchars($user['login'], ENT_QUOTES, 'UTF-8');
$is_admin       = ($user['role'] === 'admin');
$icon_url       = htmlspecialchars(APP_URL . '/assets/icons/icon-192.png', ENT_QUOTES, 'UTF-8');
$og_image_url   = htmlspecialchars(APP_URL . '/assets/icons/OG.png', ENT_QUOTES, 'UTF-8');
$has_avatar     = !empty($user['avatar_path']);   // sesja 078

$groups_data    = Group::getAll($user['id']);
$groups_json    = json_encode($groups_data, JSON_HEX_TAG | JSON_HEX_QUOT);
$csrf_token     = htmlspecialchars(CSRF::token(), ENT_QUOTES, 'UTF-8');
$recent_disabled = (bool)($user['recent_disabled'] ?? false);

$_valid_themes = ['light', 'dark', 'midnight'];
$user_theme    = in_array($user['theme'] ?? '', $_valid_themes) ? $user['theme'] : 'light';

$show_update_ui = $is_admin && defined('GITHUB_REPO') && GITHUB_REPO !== '';

// ── PHP color helpers (sesja 071b + 072) ──────────────────────────────────────
function _db_hexToRgb(string $hex): array {
    return [hexdec(substr($hex,1,2)), hexdec(substr($hex,3,2)), hexdec(substr($hex,5,2))];
}
function _db_darkenHex(string $hex, float $amt): string {
    [$r,$g,$b] = _db_hexToRgb($hex);
    return sprintf('#%02x%02x%02x',
        max(0,min(255,(int)round($r*(1-$amt)))),
        max(0,min(255,(int)round($g*(1-$amt)))),
        max(0,min(255,(int)round($b*(1-$amt))))
    );
}
// sesja 072: lighten by mixing with white
function _db_lightenHex(string $hex, float $amt): string {
    [$r,$g,$b] = _db_hexToRgb($hex);
    return sprintf('#%02x%02x%02x',
        min(255,(int)round($r + (255-$r)*$amt)),
        min(255,(int)round($g + (255-$g)*$amt)),
        min(255,(int)round($b + (255-$b)*$amt))
    );
}
// sesja 072: relative luminance (0=black, 1=white)
function _db_luminance(string $hex): float {
    [$r,$g,$b] = _db_hexToRgb($hex);
    return (0.299*$r + 0.587*$g + 0.114*$b) / 255;
}
function _db_contrastFg(string $hex): string {
    [$r,$g,$b] = _db_hexToRgb($hex);
    return ((0.299*$r + 0.587*$g + 0.114*$b) / 255) > 0.55 ? '#000000' : '#ffffff';
}
function _db_toRgba(string $hex, float $a): string {
    [$r,$g,$b] = _db_hexToRgb($hex);
    return "rgba({$r},{$g},{$b},{$a})";
}

$_valid_hex = '/^#[0-9A-Fa-f]{6}$/i';

// ── Custom primary colors (sesja 071b) ────────────────────────────────────────
$custom_colors = [
    'light'    => (preg_match($_valid_hex, $user['theme_light_primary']    ?? '') ? strtolower($user['theme_light_primary'])    : null),
    'dark'     => (preg_match($_valid_hex, $user['theme_dark_primary']     ?? '') ? strtolower($user['theme_dark_primary'])     : null),
    'midnight' => (preg_match($_valid_hex, $user['theme_midnight_primary'] ?? '') ? strtolower($user['theme_midnight_primary']) : null),
];

// ── Custom bg + text colors (sesja 072) ───────────────────────────────────────
$custom_extras = [];
foreach (['light', 'dark', 'midnight'] as $_ctk) {
    $raw = $user['theme_' . $_ctk . '_extra'] ?? null;
    $custom_extras[$_ctk] = ($raw && is_string($raw)) ? json_decode($raw, true) : null;
}

// ── Dial width (sesja 074) ────────────────────────────────────────────────────
$dial_width = max(120, min(280, (int)($user['dial_width'] ?? 175)));

// ── Build inline CSS ──────────────────────────────────────────────────────────
$_inline_css = [];

// Primary colors (071b)
foreach ($custom_colors as $_ctk => $_cth) {
    if ($_cth) {
        $_inline_css[] = "[data-theme=\"{$_ctk}\"]{"
            . "--primary:{$_cth};"
            . "--primary-h:"     . _db_darkenHex($_cth, 0.15) . ";"
            . "--primary-hover:" . _db_darkenHex($_cth, 0.12) . ";"
            . "--primary-fg:"    . _db_contrastFg($_cth) . ";"
            . "--primary-bg:"    . _db_toRgba($_cth, 0.10) . ";"
            . "--primary-bdr:"   . _db_toRgba($_cth, 0.30) . ";"
            . "--border-focus:{$_cth};"
            . "--info:{$_cth};"
            . "}";
    }
}

// Background + text colors (072)
foreach ($custom_extras as $_ctk => $_extra) {
    if (!is_array($_extra)) continue;
    $_css = '';

    $_bg = $_extra['bg'] ?? null;
    $_tx = $_extra['text'] ?? null;

    if ($_bg && preg_match($_valid_hex, $_bg)) {
        $_lum = _db_luminance($_bg);
        $_css .= "--bg:{$_bg};";
        if ($_lum > 0.5) {
            // Light bg: surface = lighten, surface-alt = slightly darker than bg
            $_css .= "--surface:"       . _db_lightenHex($_bg, 0.55) . ";";
            $_css .= "--surface-alt:"   . _db_darkenHex($_bg, 0.04)  . ";";
            $_css .= "--surface-hover:" . _db_darkenHex($_bg, 0.07)  . ";";
            $_css .= "--border:"        . _db_darkenHex($_bg, 0.14)  . ";";
            $_css .= "--border-light:"  . _db_darkenHex($_bg, 0.08)  . ";";
        } else {
            // Dark bg: surface = slightly lighter
            $_css .= "--surface:"       . _db_lightenHex($_bg, 0.08)  . ";";
            $_css .= "--surface-alt:"   . _db_lightenHex($_bg, 0.15)  . ";";
            $_css .= "--surface-hover:" . _db_lightenHex($_bg, 0.11)  . ";";
            $_css .= "--border:"        . _db_lightenHex($_bg, 0.24)  . ";";
            $_css .= "--border-light:"  . _db_lightenHex($_bg, 0.17)  . ";";
        }
    }

    if ($_tx && preg_match($_valid_hex, $_tx)) {
        [$_r,$_g,$_b] = _db_hexToRgb($_tx);
        $_css .= "--text:{$_tx};";
        $_css .= "--text-muted:rgba({$_r},{$_g},{$_b},0.65);";
        $_css .= "--text-faint:rgba({$_r},{$_g},{$_b},0.40);";
    }

    if ($_css) {
        $_inline_css[] = "[data-theme=\"{$_ctk}\"]{" . $_css . "}";
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $app_name ?></title>

<meta property="og:type"         content="website">
<meta property="og:url"          content="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:title"        content="<?= $app_name ?> — Personal Speed Dial">
<meta property="og:description"  content="Your personal browser speed dial dashboard. Fast, private, self-hosted.">
<meta property="og:image"        content="<?= $og_image_url ?>">
<meta property="og:image:width"  content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card"        content="summary_large_image">
<meta name="twitter:image"       content="<?= $og_image_url ?>">

<link rel="shortcut icon" href="/assets/icons/favicon.png" type="image/png">
<link rel="icon" href="/assets/icons/favicon.png" type="image/png" sizes="48x48">
<link rel="icon" href="/assets/icons/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
<link rel="manifest" href="/assets/manifest.json">
<link rel="stylesheet" href="/assets/css/design-system.css">
<link rel="stylesheet" href="/assets/css/app.css">
<?php
// Dial width — add to inline CSS (zero-flash)
$_inline_css[] = ":root{--dial-w:{$dial_width}px;}";
?>
<?php if ($_inline_css): ?>
<style><?= implode('', $_inline_css) ?></style>
<?php endif; ?>
<style>
.update-banner {
    display: none;
    background: var(--info-bg);
    border-bottom: 1px solid var(--info-bdr);
    padding: .6rem var(--space-5);
    font-size: var(--text-sm);
    color: var(--info);
    align-items: center;
    gap: var(--space-3);
    flex-wrap: wrap;
}
.update-banner.show { display: flex; }
.update-banner a { color: var(--info); font-weight: 600; }
.update-banner-dismiss {
    margin-left: auto; background: none; border: none;
    cursor: pointer; color: var(--info); font-size: 1.1rem;
    opacity: .7; line-height: 1; padding: .1rem .3rem;
}
.update-banner-dismiss:hover { opacity: 1; }
/* sesja 078: avatar in topbar */
.topbar-avatar-img {
    width: 20px; height: 20px; border-radius: 50%; object-fit: cover;
    vertical-align: middle; margin-right: .35rem; border: 1px solid var(--border);
    flex-shrink: 0;
}
.mobile-menu-avatar-img {
    width: 22px; height: 22px; border-radius: 50%; object-fit: cover;
    vertical-align: middle; margin-right: .4rem; border: 1px solid var(--border);
    flex-shrink: 0;
}
</style>
<script>
(function(){
    var t = localStorage.getItem('dv-theme');
    if (t) document.documentElement.setAttribute('data-theme', t);
})();
</script>
</head>
<body>

<?php if ($show_update_ui): ?>
<div class="update-banner" id="update-banner" role="alert">
    <span>🆕</span>
    <span id="update-banner-text"></span>
    <button type="button" class="update-banner-dismiss" id="update-banner-dismiss" aria-label="Dismiss">×</button>
</div>
<?php endif; ?>

<header class="topbar">
    <a href="/" class="topbar-brand">
        <img src="<?= $icon_url ?>" alt="<?= $app_name ?>">
        <span class="topbar-hide-mobile"><?= $app_name ?></span>
    </a>
    <div class="topbar-search-wrap">
        <div class="topbar-search">
            <svg class="search-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
            <input type="search" id="dial-search" class="search-input"
                   placeholder="Search dials &amp; notes…" autocomplete="off" aria-label="Search speed dials">
            <button type="button" class="search-clear" id="search-clear"
                    aria-label="Clear search" style="display:none">×</button>
        </div>
    </div>
    <div class="topbar-user topbar-hide-mobile">
        <button class="theme-toggle" data-theme-toggle title="Toggle theme">🌙 Dark</button>
        <div class="topbar-sep"></div>
        <button type="button" id="btn-bulk-select" class="topbar-btn-io" title="Select multiple dials">☑ Select</button>
        <button type="button" id="btn-import" class="topbar-btn-io" title="Import dials from JSON file">↑ Import</button>
        <button type="button" id="btn-export" class="topbar-btn-io" title="Export all dials to JSON">↓ Export</button>
        <div class="topbar-sep"></div>
        <span class="topbar-user-name">
            <?php if ($has_avatar): ?>
            <img src="/api/avatars/<?= (int)$user['id'] ?>" alt="" class="topbar-avatar-img" onerror="this.remove()">
            <?php else: ?>
            👤
            <?php endif; ?>
            <?= $user_login ?>
        </span>
        <?php if ($is_admin): ?>
        <a href="/admin" class="topbar-link">Admin</a>
        <?php endif; ?>
        <a href="/settings" class="topbar-link">Settings</a>
        <form method="post" action="/logout">
            <?= CSRF::field() ?>
            <button type="submit" class="btn-signout">Sign out</button>
        </form>
    </div>
    <button type="button" id="btn-hamburger" class="hamburger topbar-show-mobile" aria-label="Menu" aria-expanded="false">
        <span></span><span></span><span></span>
    </button>
</header>

<div id="mobile-menu" class="mobile-menu topbar-show-mobile" aria-hidden="true">
    <div class="mobile-menu-inner">
        <div class="mobile-menu-user">
            <?php if ($has_avatar): ?>
            <img src="/api/avatars/<?= (int)$user['id'] ?>" alt="" class="mobile-menu-avatar-img" onerror="this.remove()">
            <?php else: ?>
            👤
            <?php endif; ?>
            <?= $user_login ?>
        </div>
        <button class="mobile-menu-item" data-theme-toggle>🌙 Dark mode</button>
        <button class="mobile-menu-item" id="btn-bulk-select-mobile">☑ Select multiple</button>
        <button class="mobile-menu-item" id="btn-import-mobile">↑ Import</button>
        <button class="mobile-menu-item" id="btn-export-mobile">↓ Export</button>
        <?php if ($is_admin): ?>
        <a href="/admin" class="mobile-menu-item">Admin panel</a>
        <?php endif; ?>
        <a href="/settings" class="mobile-menu-item">Settings</a>
        <form method="post" action="/logout">
            <?= CSRF::field() ?>
            <button type="submit" class="mobile-menu-item mobile-menu-danger">Sign out</button>
        </form>
    </div>
</div>

<nav class="groups-bar" id="groups-bar" aria-label="Dial groups">
    <button class="group-tab active" data-group-id="all" type="button">
        <span class="tab-name">All</span>
        <span class="tab-count">0</span>
    </button>
    <button class="tab-add-group" id="btn-add-group" type="button" title="Create a new group">
        <span class="tab-add-group-icon">＋</span>
        <span class="topbar-hide-mobile">New group</span>
    </button>
</nav>

<main class="page-main">
    <?php if ($backup_warning): ?>
    <div class="alert alert-warning" style="margin-bottom:var(--space-5)">
        <span class="alert-icon">⚠</span>
        <span>You signed in with a backup code.
              <a href="/settings#2fa">Regenerate your backup codes</a> when possible.</span>
    </div>
    <?php endif; ?>

    <div id="search-info" style="display:none;font-size:.85rem;color:var(--text-muted);margin-bottom:var(--space-3)"></div>

    <div class="dial-grid" id="dial-grid">
        <?php if (empty($groups_data)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">📌</div>
            <h3>No groups yet</h3>
            <p>Create your first group using the <strong>＋ New group</strong> button above.</p>
            <button class="btn btn-primary" id="btn-create-first" type="button">Create first group</button>
        </div>
        <?php endif; ?>
    </div>
</main>

<div class="toast-container"></div>

<script>
window.LETADIAL_BOOT = {
    csrfToken:      <?= json_encode($csrf_token) ?>,
    groups:         <?= $groups_json ?>,
    userId:         <?= (int)$user['id'] ?>,
    appUrl:         <?= json_encode(APP_URL) ?>,
    isAdmin:        <?= $is_admin ? 'true' : 'false' ?>,
    showUpdateUi:   <?= $show_update_ui ? 'true' : 'false' ?>,
    recentDisabled: <?= $recent_disabled ? 'true' : 'false' ?>,
    userTheme:      '<?= $user_theme ?>',
    customColors:   <?= json_encode($custom_colors) ?>,
    customExtras:   <?= json_encode($custom_extras) ?>,
    dialWidth:      <?= $dial_width ?>,       // sesja 074
    hasAvatar:      <?= $has_avatar ? 'true' : 'false' ?>,  // sesja 078
};
</script>
<script src="/assets/js/app.js"></script>

<?php if ($show_update_ui): ?>
<script>
(function() {
    const DISMISS_KEY = 'dv-update-dismissed';
    const banner      = document.getElementById('update-banner');
    const bannerText  = document.getElementById('update-banner-text');
    const dismissBtn  = document.getElementById('update-banner-dismiss');
    const csrf        = window.LETADIAL_BOOT?.csrfToken || '';
    if (!banner) return;
    async function checkUpdate() {
        try {
            const res  = await fetch('/api/update', { headers: { 'X-CSRF-Token': csrf }, credentials: 'same-origin' });
            if (!res.ok) return;
            const data = await res.json();
            if (!data.ok || !data.update_available) return;
            const dismissed = localStorage.getItem(DISMISS_KEY);
            if (dismissed === data.latest) return;
            const notes = data.notes ? ` — ${data.notes}` : '';
            bannerText.innerHTML =
                `<strong>LetaDial ${data.latest} is available</strong>${escHtml(notes)} &nbsp;` +
                `<a href="${escHtml(data.url)}" target="_blank" rel="noopener noreferrer">View release →</a>` +
                `<span style="color:var(--text-faint);font-size:.8em;margin-left:.5rem">(current: ${escHtml(data.current)})</span>`;
            banner.classList.add('show');
        } catch (e) {}
    }
    function escHtml(s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    dismissBtn?.addEventListener('click', () => {
        fetch('/api/update', { headers: { 'X-CSRF-Token': csrf }, credentials: 'same-origin' })
            .then(r => r.json()).then(d => { if (d.latest) localStorage.setItem(DISMISS_KEY, d.latest); }).catch(() => {});
        banner.classList.remove('show');
    });
    setTimeout(checkUpdate, 3000);
})();
</script>
<?php endif; ?>

</body>
</html>
