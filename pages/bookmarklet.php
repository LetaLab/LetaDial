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
<script>(function(){const t=localStorage.getItem('dv-theme');if(t)document.documentElement.setAttribute('data-theme',t)})();</script>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: var(--font-sans);
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    font-size: 15px;
    line-height: 1.5;
}
.bm-topbar {
    background: var(--primary);
    color: var(--primary-fg);
    display: flex;
    align-items: center;
    gap: .6rem;
    padding: .65rem 1rem;
    flex-shrink: 0;
}
.bm-topbar img { width: 22px; height: 22px; object-fit: contain; filter: brightness(0) invert(1); }
.bm-topbar-title { font-size: .92rem; font-weight: 700; }
.bm-body { padding: .9rem 1rem; flex: 1; overflow-y: auto; }
.bm-form-group { margin-bottom: .75rem; }
.bm-label {
    display: block;
    font-size: .7rem;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: var(--text-muted);
    margin-bottom: .28rem;
}
.bm-input {
    width: 100%;
    padding: .48rem .65rem;
    background: var(--surface);
    border: 1.5px solid var(--border);
    border-radius: var(--radius-md);
    font-size: .875rem;
    color: var(--text);
    font-family: var(--font-sans);
    outline: none;
    transition: border-color .15s, box-shadow .15s;
}
.bm-input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-bg); }
.bm-textarea { resize: vertical; min-height: 58px; }
.bm-select {
    appearance: none; -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right .65rem center;
    padding-right: 2rem;
    cursor: pointer;
}
.bm-footer {
    padding: .65rem 1rem;
    border-top: 1px solid var(--border);
    background: var(--surface);
    display: flex;
    gap: .6rem;
    flex-shrink: 0;
}
.bm-btn {
    flex: 1;
    padding: .55rem;
    font-size: .875rem;
    font-family: var(--font-sans);
    font-weight: 600;
    border-radius: var(--radius-md);
    border: 1.5px solid transparent;
    cursor: pointer;
    transition: background .15s, color .15s, border-color .15s;
    text-align: center;
}
.bm-btn-primary { background: var(--primary); color: var(--primary-fg); }
.bm-btn-primary:hover:not(:disabled) { background: var(--primary-h, #520818); }
.bm-btn-primary:disabled { opacity: .55; cursor: not-allowed; }
.bm-btn-ghost { background: var(--surface-alt); color: var(--text-muted); border-color: var(--border); }
.bm-btn-ghost:hover { background: var(--border); color: var(--text); }
.bm-alert {
    display: flex;
    align-items: flex-start;
    gap: .4rem;
    padding: .55rem .75rem;
    border-radius: var(--radius-md);
    font-size: .82rem;
    margin-bottom: .75rem;
    border: 1px solid transparent;
    line-height: 1.4;
}
.bm-alert-success { background: var(--success-bg); border-color: var(--success-bdr); color: var(--success); }
.bm-alert-error   { background: var(--error-bg);   border-color: var(--error-bdr);   color: var(--error); }
.bm-center-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    flex: 1;
    text-align: center;
    padding: 1.5rem 1rem;
    gap: .6rem;
}
.bm-state-icon  { font-size: 2.5rem; line-height: 1; }
.bm-state-title { font-size: .95rem; font-weight: 600; }
.bm-state-sub   { font-size: .82rem; color: var(--text-muted); max-width: 240px; line-height: 1.5; }
.notes-count { text-align: right; font-size: .68rem; color: var(--text-faint); margin-top: .18rem; }
</style>
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
    <button type="button"
            onclick="window.open('<?= h(APP_URL . '/login') ?>', '_blank')"
            class="bm-btn bm-btn-primary" style="max-width:200px;margin-top:.25rem">
        Open <?= $app_name ?> →
    </button>
</div>

<?php elseif (empty($groups_data)): ?>
<!-- ── No groups yet ─────────────────────────────────────────────────────────── -->
<div class="bm-center-state" style="flex:1">
    <div class="bm-state-icon">📂</div>
    <div class="bm-state-title">No groups yet</div>
    <p class="bm-state-sub">
        Create at least one group in <?= $app_name ?> before using LetaLink.
    </p>
    <button type="button"
            onclick="window.open('<?= h(APP_URL) ?>', '_blank')"
            class="bm-btn bm-btn-primary" style="max-width:200px;margin-top:.25rem">
        Open <?= $app_name ?> →
    </button>
</div>

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
        <button type="button" class="bm-btn bm-btn-ghost" onclick="window.close()">Cancel</button>
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
        <button type="button" class="bm-btn bm-btn-ghost" onclick="window.close()">Close now</button>
        <button type="button" class="bm-btn bm-btn-primary"
                onclick="window.open('<?= h(APP_URL) ?>','_blank');window.close()">
            Open <?= $app_name ?>
        </button>
    </div>
</div>

<script>
(function() {
    const CSRF  = <?= json_encode($csrf_token) ?>;
    const sel   = document.getElementById('bm-group');
    const alert = document.getElementById('bm-alert');
    const notes = document.getElementById('bm-notes');
    const count = document.getElementById('bm-notes-count');
    const urlEl = document.getElementById('bm-url');
    const titleEl = document.getElementById('bm-title');

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
