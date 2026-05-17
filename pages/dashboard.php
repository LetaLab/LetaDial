<?php
/**
 * LetaDial — Dashboard
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

$groups_data = Group::getAll($user['id']);
$groups_json = json_encode($groups_data, JSON_HEX_TAG | JSON_HEX_QUOT);
$csrf_token  = htmlspecialchars(CSRF::token(), ENT_QUOTES, 'UTF-8');
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
<script>
(function(){
    var t = localStorage.getItem('dv-theme');
    if (t) document.documentElement.setAttribute('data-theme', t);
})();
</script>
</head>
<body>

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
                   placeholder="Search dials…" autocomplete="off" aria-label="Search speed dials">
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
    csrfToken: "<?= $csrf_token ?>",
    groups:    <?= $groups_json ?>,
    userId:    <?= (int)$user['id'] ?>,
    appUrl:    <?= json_encode(APP_URL) ?>,
    isAdmin:   <?= $is_admin ? 'true' : 'false' ?>
};
</script>
<script src="/assets/js/app.js"></script>

</body>
</html>
