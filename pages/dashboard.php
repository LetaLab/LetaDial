<?php
/**
 * LetaDial — Dashboard (sesja 059: update notification banner for admin)
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

$groups_data    = Group::getAll($user['id']);
$groups_json    = json_encode($groups_data, JSON_HEX_TAG | JSON_HEX_QUOT);
$csrf_token     = htmlspecialchars(CSRF::token(), ENT_QUOTES, 'UTF-8');
$recent_disabled = (bool)($user['recent_disabled'] ?? false);

// Validate theme from DB — defence against corrupt values
$_valid_themes = ['light', 'dark', 'midnight'];
$user_theme    = in_array($user['theme'] ?? '', $_valid_themes) ? $user['theme'] : 'light';

// Update check — only for admin, only if GITHUB_REPO is configured, non-blocking
// We pass update info to JS which shows the banner — no PHP blocking here
$show_update_ui = $is_admin && defined('GITHUB_REPO') && GITHUB_REPO !== '';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $app_name ?></title>

<!-- Open Graph / Social Media Preview -->
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
<style>
/* ── Update banner (sesja 059) ───────────────────────────────────────────── */
.update-banner {
    display: none; /* shown by JS after async check */
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
</style>
<script>
(function(){
    var t = localStorage.getItem('dv-theme');
    if (t) document.documentElement.setAttribute('data-theme', t);
})();
</script>
</head>
<body>

<!-- UPDATE BANNER (admin only, filled by JS) -->
<?php if ($show_update_ui): ?>
<div class="update-banner" id="update-banner" role="alert">
    <span>🆕</span>
    <span id="update-banner-text"></span>
    <button type="button" class="update-banner-dismiss" id="update-banner-dismiss"
            aria-label="Dismiss">×</button>
</div>
<?php endif; ?>

<!-- TOPBAR -->
<header class="topbar">
    <a href="/" class="topbar-brand">
        <img src="<?= $icon_url ?>" alt="<?= $app_name ?>">
        <span class="topbar-hide-mobile"><?= $app_name ?></span>
    </a>

    <!-- Search bar — centered in topbar -->
    <div class="topbar-search-wrap">
        <div class="topbar-search">
            <svg class="search-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
            <input type="search" id="dial-search" class="search-input"
                   placeholder="Search dials &amp; notes…" autocomplete="off" aria-label="Search speed dials">
            <button type="button" class="search-clear" id="search-clear"
                    aria-label="Clear search" style="display:none">×</button>
        </div>
    </div>

    <!-- Desktop user menu -->
    <div class="topbar-user topbar-hide-mobile">
        <button class="theme-toggle" data-theme-toggle title="Toggle dark/light mode">🌙 Dark</button>
        <div class="topbar-sep"></div>
        <button type="button" id="btn-bulk-select" class="topbar-btn-io" title="Select multiple dials">☑ Select</button>
        <button type="button" id="btn-import" class="topbar-btn-io" title="Import dials from JSON file">↑ Import</button>
        <button type="button" id="btn-export" class="topbar-btn-io" title="Export all dials to JSON">↓ Export</button>
        <div class="topbar-sep"></div>
        <span class="topbar-user-name">👤 <?= $user_login ?></span>
        <?php if ($is_admin): ?>
        <a href="/admin" class="topbar-link">Admin</a>
        <?php endif; ?>
        <a href="/settings" class="topbar-link">Settings</a>
        <form method="post" action="/logout">
            <?= CSRF::field() ?>
            <button type="submit" class="btn-signout">Sign out</button>
        </form>
    </div>

    <!-- Mobile hamburger -->
    <button type="button" id="btn-hamburger" class="hamburger topbar-show-mobile" aria-label="Menu" aria-expanded="false">
        <span></span><span></span><span></span>
    </button>
</header>

<!-- Mobile menu drawer -->
<div id="mobile-menu" class="mobile-menu topbar-show-mobile" aria-hidden="true">
    <div class="mobile-menu-inner">
        <div class="mobile-menu-user">👤 <?= $user_login ?></div>
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

<!-- GROUP TABS BAR -->
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

<!-- MAIN CONTENT -->
<main class="page-main">
    <?php if ($backup_warning): ?>
    <div class="alert alert-warning" style="margin-bottom:var(--space-5)">
        <span class="alert-icon">⚠</span>
        <span>You signed in with a backup code.
              <a href="/settings#2fa">Regenerate your backup codes</a> when possible.</span>
    </div>
    <?php endif; ?>

    <!-- Search status bar -->
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
};
</script>
<script src="/assets/js/app.js"></script>

<?php if ($show_update_ui): ?>
<script>
// ── Update check (sesja 059) ──────────────────────────────────────────────────
// Runs after page load, async, non-blocking.
// Uses cached result from DB — no GitHub hit on every pageload.
// Dismissed state stored in localStorage per version.
(function() {
    const DISMISS_KEY = 'dv-update-dismissed';
    const banner      = document.getElementById('update-banner');
    const bannerText  = document.getElementById('update-banner-text');
    const dismissBtn  = document.getElementById('update-banner-dismiss');
    const csrf        = window.LETADIAL_BOOT?.csrfToken || '';

    if (!banner) return;

    async function checkUpdate() {
        try {
            const res  = await fetch('/api/update', {
                headers: { 'X-CSRF-Token': csrf },
                credentials: 'same-origin',
            });
            if (!res.ok) return;
            const data = await res.json();

            if (!data.ok || !data.update_available) return;

            // Check if user already dismissed this version
            const dismissed = localStorage.getItem(DISMISS_KEY);
            if (dismissed === data.latest) return;

            // Show banner
            const notes = data.notes ? ` — ${data.notes}` : '';
            bannerText.innerHTML =
                `<strong>LetaDial ${data.latest} is available</strong>${escHtml(notes)} &nbsp;` +
                `<a href="${escHtml(data.url)}" target="_blank" rel="noopener noreferrer">` +
                `View release →</a>` +
                `<span style="color:var(--text-faint);font-size:.8em;margin-left:.5rem">` +
                `(current: ${escHtml(data.current)})</span>`;
            banner.classList.add('show');
        } catch (e) {
            // Silently ignore — update check is non-critical
        }
    }

    function escHtml(s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    dismissBtn?.addEventListener('click', () => {
        // Read latest version from banner text data attribute or re-fetch
        // Simple approach: just hide and set dismissed flag to current banner content
        // We fetch the version from the API result cached in DOM
        fetch('/api/update', { headers: { 'X-CSRF-Token': csrf }, credentials: 'same-origin' })
            .then(r => r.json())
            .then(d => { if (d.latest) localStorage.setItem(DISMISS_KEY, d.latest); })
            .catch(() => {});
        banner.classList.remove('show');
    });

    // Delay check by 3s after page load — don't compete with initial render
    setTimeout(checkUpdate, 3000);
})();
</script>
<?php endif; ?>

</body>
</html>
