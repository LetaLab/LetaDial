<?php
/**
 * LetaDial — LetaLink Bookmarklet Popup (sesja 077)
 *
 * Small popup window opened by the LetaLink browser bookmarklet.
 * Lets user quickly add the current browser page as a dial.
 *
 * GET params (set by bookmarklet JS):
 *   url   — current page URL (FILTER_VALIDATE_URL)
 *   title — page title (sanitized, max 100 chars)
 *   desc  — og:description or meta description (sanitized, max 500 chars)
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die();

$user        = Auth::getUser();
$app_name    = htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8');
$icon_url    = htmlspecialchars(APP_URL . '/assets/icons/icon-192.png', ENT_QUOTES, 'UTF-8');
$csrf_token  = '';
$groups_data = [];
$groups_json = '[]';

// Sanitize incoming GET params from bookmarklet
$in_url   = filter_var(trim($_GET['url']   ?? ''), FILTER_VALIDATE_URL)
            ? trim($_GET['url']) : '';
$in_title = mb_substr(strip_tags(trim($_GET['title'] ?? '')), 0, 100);
$in_desc  = mb_substr(strip_tags(trim($_GET['desc']  ?? '')), 0, 500);

if ($user) {
    $csrf_token  = CSRF::token();
    $groups_data = Group::getAll($user['id']);
    $groups_json = json_encode($groups_data, JSON_HEX_TAG | JSON_HEX_QUOT);
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Add to <?= $app_name ?></title>
<link rel="icon" type="image/png" href="/assets/icons/favicon.png">
<link rel="stylesheet" href="/assets/css/design-system.css">
<script nonce="<?= CSP::nonce() ?>">(function(){const t=localStorage.getItem('dv-theme');if(t)document.documentElement.setAttribute('data-theme',t)})();</script>
<link rel="stylesheet" href="/assets/css/pages/bookmarklet.css">
</head>
<body>

<div class="bm-topbar">
    <img src="<?= $icon_url ?>" alt="">
    <span class="bm-topbar-title">Add to <?= $app_name ?></span>
</div>

<?php if (!$user): ?>
<!-- ── Not logged in ──────────────────────────────────────────────────────────── -->
<div class="bm-center-state" style="flex:1">
    <div class="bm-state-icon">🔒</div>
    <div class="bm-state-title">Sign in required</div>
    <p class="bm-state-sub">
        You need to be signed in to <?= $app_name ?> to use LetaLink.
    </p>
    <button type="button" id="bm-open-app-login"
            class="bm-btn bm-btn-primary" style="max-width:200px;margin-top:.25rem">
        Open <?= $app_name ?> →
    </button>
</div>
<script nonce="<?= CSP::nonce() ?>">
document.getElementById('bm-open-app-login')?.addEventListener('click', function() {
    window.open(<?= json_encode(APP_URL . '/login') ?>, '_blank');
});
</script>

<?php elseif (empty($groups_data)): ?>
<!-- ── No groups yet ─────────────────────────────────────────────────────────── -->
<div class="bm-center-state" style="flex:1">
    <div class="bm-state-icon">📂</div>
    <div class="bm-state-title">No groups yet</div>
    <p class="bm-state-sub">
        Create at least one group in <?= $app_name ?> before using LetaLink.
    </p>
    <button type="button" id="bm-open-app-home"
            class="bm-btn bm-btn-primary" style="max-width:200px;margin-top:.25rem">
        Open <?= $app_name ?> →
    </button>
</div>
<script nonce="<?= CSP::nonce() ?>">
document.getElementById('bm-open-app-home')?.addEventListener('click', function() {
    window.open(<?= json_encode(APP_URL) ?>, '_blank');
});
</script>

<?php else: ?>
<!-- ── Add Dial Form ──────────────────────────────────────────────────────────── -->
<div id="bm-form-wrap" style="display:flex;flex-direction:column;flex:1;overflow:hidden">
    <div class="bm-body">
        <div id="bm-alert" style="display:none"></div>

        <div class="bm-form-group">
            <label class="bm-label" for="bm-group">Group</label>
            <select id="bm-group" class="bm-input bm-select">
                <?php foreach ($groups_data as $g): ?>
                <option value="<?= (int)$g['id'] ?>">
                    <?= h(($g['icon'] ? $g['icon'] . ' ' : '') . $g['name']) ?> (<?= (int)$g['dial_count'] ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="bm-form-group">
            <label class="bm-label" for="bm-title">Title</label>
            <input type="text" id="bm-title" class="bm-input"
                   value="<?= h($in_title) ?>"
                   maxlength="100"
                   placeholder="Page title">
        </div>

        <div class="bm-form-group">
            <label class="bm-label" for="bm-url">URL</label>
            <input type="url" id="bm-url" class="bm-input"
                   value="<?= h($in_url) ?>"
                   placeholder="https://…">
        </div>

        <div class="bm-form-group">
            <label class="bm-label" for="bm-notes">
                Note <span style="color:var(--text-faint);font-weight:400;text-transform:none">(optional)</span>
            </label>
            <textarea id="bm-notes" class="bm-input bm-textarea"
                      maxlength="500"
                      placeholder="Short note or description…"><?= h($in_desc) ?></textarea>
            <div class="notes-count"><span id="bm-notes-count"><?= mb_strlen($in_desc) ?></span>/500</div>
        </div>
    </div>

    <div class="bm-footer">
        <button type="button" class="bm-btn bm-btn-ghost" id="bm-cancel-btn">Cancel</button>
        <button type="button" class="bm-btn bm-btn-primary" id="bm-add-btn">Add dial →</button>
    </div>
</div>

<!-- ── Success State ──────────────────────────────────────────────────────────── -->
<div id="bm-success" style="display:none;flex-direction:column;flex:1">
    <div class="bm-center-state" style="flex:1">
        <div class="bm-state-icon">✅</div>
        <div class="bm-state-title">Dial added!</div>
        <p class="bm-state-sub" id="bm-success-sub">Closing in 2 seconds…</p>
    </div>
    <div class="bm-footer">
        <button type="button" class="bm-btn bm-btn-ghost" id="bm-close-now-btn">Close now</button>
        <button type="button" class="bm-btn bm-btn-primary" id="bm-open-app-success">
            Open <?= $app_name ?>
        </button>
    </div>
</div>

<script nonce="<?= CSP::nonce() ?>">
(function() {
    const CSRF  = <?= json_encode($csrf_token) ?>;
    const sel   = document.getElementById('bm-group');
    const alert = document.getElementById('bm-alert');
    const notes = document.getElementById('bm-notes');
    const count = document.getElementById('bm-notes-count');
    const urlEl = document.getElementById('bm-url');
    const titleEl = document.getElementById('bm-title');

    // ── Event listeners (CSP: bez inline onXXX=, Krok 4a) ──────────────────────
    const APP_URL_JS = <?= json_encode(APP_URL) ?>;
    document.getElementById('bm-cancel-btn')?.addEventListener('click', function() { window.close(); });
    document.getElementById('bm-close-now-btn')?.addEventListener('click', function() { window.close(); });
    document.getElementById('bm-open-app-success')?.addEventListener('click', function() {
        window.open(APP_URL_JS, '_blank');
        window.close();
    });

    // Restore last used group
    const lastGroup = localStorage.getItem('bm-last-group');
    if (lastGroup && sel) {
        const opt = sel.querySelector('option[value="' + lastGroup + '"]');
        if (opt) sel.value = lastGroup;
    }

    // Notes character counter
    notes?.addEventListener('input', function() {
        if (count) count.textContent = this.value.length;
    });

    function showAlert(type, msg) {
        if (!alert) return;
        alert.style.display = '';
        alert.className = 'bm-alert bm-alert-' + type;
        alert.textContent = msg;
    }

    // Enter on text inputs triggers submit
    [titleEl, urlEl].forEach(el => {
        el?.addEventListener('keydown', e => {
            if (e.key === 'Enter') document.getElementById('bm-add-btn')?.click();
        });
    });

    // Auto-focus: URL field if title already present, else title
    setTimeout(() => {
        if (titleEl?.value) { urlEl?.focus(); urlEl?.select(); }
        else                { titleEl?.focus(); }
    }, 80);

    // Add dial
    document.getElementById('bm-add-btn')?.addEventListener('click', async () => {
        const groupId = parseInt(sel?.value) || 0;
        const url     = urlEl?.value?.trim();
        const title   = titleEl?.value?.trim();
        const note    = notes?.value?.trim();

        if (!url)     { showAlert('error', 'URL is required.'); return; }
        if (!groupId) { showAlert('error', 'Please select a group.'); return; }

        const btn = document.getElementById('bm-add-btn');
        btn.disabled = true; btn.textContent = '…';

        try {
            const res = await fetch('/api/dials', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                credentials: 'same-origin',
                body: JSON.stringify({ group_id: groupId, url, title: title || '', notes: note || '' })
            });
            const data = await res.json();

            if (!data.ok) {
                btn.disabled = false; btn.textContent = 'Add dial →';
                showAlert('error', data.error || 'Could not add dial.');
                return;
            }

            // Remember last used group
            localStorage.setItem('bm-last-group', String(groupId));

            // Trigger thumbnail generation in background
            if (data.id) {
                fetch('/api/thumbs/' + data.id, {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': CSRF },
                    credentials: 'same-origin'
                }).catch(() => {});
            }

            // Show success + countdown
            document.getElementById('bm-form-wrap').style.display = 'none';
            const succ = document.getElementById('bm-success');
            succ.style.display = 'flex';

            let t = 2;
            const sub = document.getElementById('bm-success-sub');
            const timer = setInterval(() => {
                t--;
                if (sub) sub.textContent = t > 0
                    ? 'Closing in ' + t + ' second' + (t !== 1 ? 's' : '') + '…'
                    : 'Closing…';
                if (t <= 0) { clearInterval(timer); window.close(); }
            }, 1000);

        } catch (e) {
            btn.disabled = false; btn.textContent = 'Add dial →';
            showAlert('error', 'Network error. Are you still signed in?');
        }
    });
})();
</script>

<?php endif; ?>

</body>
</html>
