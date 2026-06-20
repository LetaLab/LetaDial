/**
 * LetaDial — App JavaScript
 * Pure vanilla ES6+, zero libraries, zero frameworks.
 *
 * Sesja 053: Keyboard navigation
 * Sesja 054: Dial notes
 * Sesja 056: Sort options
 * Sesja 057: OG meta auto-fetch
 * Sesja 061: Pin / favourite — pinned dials always first, 📌 badge, context menu toggle
 * Sesja 062: Recently used — virtual group, last 20 clicked dials ordered by last_click
 */

const LetaDial = (() => {

    const RECENT_GROUP_ID = 'recent';

    let activeGroupId = localStorage.getItem('dv-last-group') || 'all';
    let groups        = [];

    function init() {
        theme.init();
        // sesja 074: apply saved dial width from DB (zero-flash — PHP already wrote
        // :root{--dial-w:Xpx} in inline <style>, this JS sync keeps SPA state correct
        // when user changes size in Settings and comes back without a full reload)
        _applyDialWidth(window.LETADIAL_BOOT?.dialWidth ?? 175);
        sort_module.init();
        groups_module.init();
        import_export_module.init();
        contextMenu.init();
        search_module.init();
        mobile_menu.init();
        bulk_module.init();
        keyboard_nav.init();
    }

    // ── Theme ─────────────────────────────────────────────────────────────────
    // sesja 071a: 3-theme cycle Light → Dark → Midnight → Light, per-user in DB
    const theme = {
        current: 'light',
        _order:  ['light', 'dark', 'midnight'],
        _labels: { light: '🌙 Dark', dark: '🌑 Midnight', midnight: '☀ Light' },
        _titles: { light: 'Switch to Dark mode', dark: 'Switch to Midnight mode', midnight: 'Switch to Light mode' },

        _next(t) {
            const idx = this._order.indexOf(t);
            return this._order[(idx + 1) % this._order.length];
        },

        init() {
            // DB theme (LETADIAL_BOOT.userTheme) takes precedence over localStorage
            const boot    = window.LETADIAL_BOOT;
            const dbTheme = boot?.userTheme;
            const lsTheme = localStorage.getItem('dv-theme');
            const saved   = (dbTheme && this._order.includes(dbTheme)) ? dbTheme
                          : (lsTheme && this._order.includes(lsTheme)) ? lsTheme
                          : 'light';
            // Sync localStorage with DB value if they differ
            if (dbTheme && dbTheme !== lsTheme) {
                localStorage.setItem('dv-theme', dbTheme);
            }
            this.apply(saved, false);
            document.querySelectorAll('[data-theme-toggle]').forEach(btn => {
                btn.addEventListener('click', () => this.toggle());
                this._update(btn, this.current);
            });
        },

        toggle() { this.apply(this._next(this.current)); },

        apply(t, save = true) {
            if (!this._order.includes(t)) t = 'light';
            this.current = t;
            document.documentElement.setAttribute('data-theme', t);
            this._applyCustomColor(t);   // sesja 071b — custom primary color
            if (save) {
                localStorage.setItem('dv-theme', t);
                // Save to DB — non-blocking, ignore errors
                api.post('/api/settings/theme', { theme: t }).catch(() => {});
            }
            document.querySelectorAll('[data-theme-toggle]').forEach(b => this._update(b, t));
        },

        _update(btn, t) {
            btn.textContent = this._labels[t] || '🌙 Dark';
            btn.title       = this._titles[t] || 'Toggle theme';
        },

        // ── Custom color helpers (sesja 071b) ─────────────────────────────
        _hexToRgb(hex) {
            return [parseInt(hex.slice(1,3),16), parseInt(hex.slice(3,5),16), parseInt(hex.slice(5,7),16)];
        },
        _darken(hex, amt) {
            const [r,g,b] = this._hexToRgb(hex);
            return '#' + [r,g,b]
                .map(v => Math.max(0,Math.min(255,Math.round(v*(1-amt)))).toString(16).padStart(2,'0'))
                .join('');
        },
        _contrastFg(hex) {
            const [r,g,b] = this._hexToRgb(hex);
            return (0.299*r + 0.587*g + 0.114*b)/255 > 0.55 ? '#000000' : '#ffffff';
        },
        _toRgba(hex, a) {
            const [r,g,b] = this._hexToRgb(hex);
            return `rgba(${r},${g},${b},${a})`;
        },

        /**
         * Czyta customColors z LETADIAL_BOOT i nadpisuje CSS vars.
         * PHP inline <style> zapewnia zero flash przy zaladowaniu.
         * JS element.style ma najwyzszy priorytet — wygrywa z kazdym selektorem.
         */
        _applyCustomColor(t) {
            const colors = window.LETADIAL_BOOT?.customColors || {};
            const extras = window.LETADIAL_BOOT?.customExtras || {};
            const hex = colors[t];
            if (hex && /^#[0-9A-Fa-f]{6}$/i.test(hex)) { this._setCssVars(hex); }
            else { this._clearCssVars(); }
            const extra = extras[t];
            if (extra) { this._setExtraCssVars(extra.bg || null, extra.text || null); }
            else { this._clearExtraCssVars(); }
        },
        _setCssVars(hex) {
            const root = document.documentElement;
            root.style.setProperty('--primary',       hex);
            root.style.setProperty('--primary-h',     this._darken(hex, 0.15));
            root.style.setProperty('--primary-hover', this._darken(hex, 0.12));
            root.style.setProperty('--primary-fg',    this._contrastFg(hex));
            root.style.setProperty('--primary-bg',    this._toRgba(hex, 0.10));
            root.style.setProperty('--primary-bdr',   this._toRgba(hex, 0.30));
            root.style.setProperty('--border-focus',  hex);
            root.style.setProperty('--info',          hex);
        },
        _clearCssVars() {
            ['--primary','--primary-h','--primary-hover','--primary-fg',
             '--primary-bg','--primary-bdr','--border-focus','--info']
                .forEach(v => document.documentElement.style.removeProperty(v));
        },
        // sesja 072
        _lighten(hex, amt) {
            const [r,g,b] = this._hexToRgb(hex);
            return '#' + [r,g,b].map(v => Math.min(255,Math.round(v+(255-v)*amt)).toString(16).padStart(2,'0')).join('');
        },
        _luminance(hex) {
            const [r,g,b] = this._hexToRgb(hex);
            return (0.299*r + 0.587*g + 0.114*b) / 255;
        },
        _setExtraCssVars(bg, text) {
            const root = document.documentElement;
            if (bg && /^#[0-9A-Fa-f]{6}$/i.test(bg)) {
                const lum = this._luminance(bg);
                root.style.setProperty('--bg', bg);
                if (lum > 0.5) {
                    root.style.setProperty('--surface',       this._lighten(bg, 0.55));
                    root.style.setProperty('--surface-alt',   this._darken(bg, 0.04));
                    root.style.setProperty('--surface-hover', this._darken(bg, 0.07));
                    root.style.setProperty('--border',        this._darken(bg, 0.14));
                    root.style.setProperty('--border-light',  this._darken(bg, 0.08));
                } else {
                    root.style.setProperty('--surface',       this._lighten(bg, 0.08));
                    root.style.setProperty('--surface-alt',   this._lighten(bg, 0.15));
                    root.style.setProperty('--surface-hover', this._lighten(bg, 0.11));
                    root.style.setProperty('--border',        this._lighten(bg, 0.24));
                    root.style.setProperty('--border-light',  this._lighten(bg, 0.17));
                }
            }
            if (text && /^#[0-9A-Fa-f]{6}$/i.test(text)) {
                const [r,g,b] = this._hexToRgb(text);
                root.style.setProperty('--text', text);
                root.style.setProperty('--text-muted', `rgba(${r},${g},${b},0.65)`);
                root.style.setProperty('--text-faint', `rgba(${r},${g},${b},0.40)`);
            }
        },
        _clearExtraCssVars() {
            ['--bg','--surface','--surface-alt','--surface-hover','--border','--border-light',
             '--text','--text-muted','--text-faint']
                .forEach(v => document.documentElement.style.removeProperty(v));
        },
    };

    // ── API ───────────────────────────────────────────────────────────────────
    const api = {
        csrfToken() { return window.LETADIAL_BOOT?.csrfToken || ''; },
        csrf()      { return window.LETADIAL_BOOT?.csrfToken || ''; },
        async request(method, url, body = null) {
            const opts = {
                method,
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken() },
                credentials: 'same-origin',
            };
            if (body !== null) opts.body = JSON.stringify(body);
            try {
                const res  = await fetch(url, opts);
                const data = await res.json();
                return { status: res.status, ...data };
            } catch (e) {
                console.error('[LetaDial] API error:', e);
                return { ok: false, error: 'Network error.' };
            }
        },
        get:    (url)       => api.request('GET',    url),
        post:   (url, body) => api.request('POST',   url, body),
        put:    (url, body) => api.request('PUT',    url, body),
        delete: (url)       => api.request('DELETE', url),
    };

    // ── Meta fetch (sesja 057) ────────────────────────────────────────────────
    const meta_module = {
        _timer: null,
        _lastUrl: null,

        async fetchFor(url, titleInput, notesInput, statusEl) {
            url = url.trim();
            if (!url) return;
            if (!/^https?:\/\//i.test(url)) url = 'https://' + url;
            if (url === this._lastUrl) return;

            this._showStatus(statusEl, '⏳ Fetching title…', 'var(--text-faint)');

            let data;
            try {
                data = await api.post('/api/meta', { url });
            } catch {
                this._showStatus(statusEl, '', '');
                return;
            }

            if (!data.ok) {
                this._showStatus(statusEl, '⚠ Could not fetch title', 'var(--warning)');
                setTimeout(() => this._showStatus(statusEl, '', ''), 3000);
                return;
            }

            this._lastUrl = url;
            let filled = false;

            if (data.title && titleInput && !titleInput.value.trim()) {
                titleInput.value = data.title;
                filled = true;
            }

            if (data.description && notesInput && !notesInput.value.trim()) {
                notesInput.value = data.description.substring(0, 500);
                const counterId = notesInput.id === 'new-dial-notes'
                    ? 'new-dial-notes-count' : 'edit-dial-notes-count';
                const counter = document.getElementById(counterId);
                if (counter) counter.textContent = notesInput.value.length;
                filled = true;
            }

            this._showStatus(
                statusEl,
                filled ? '✓ Title fetched' : '✓ Fetched (no new data)',
                'var(--success)'
            );
            setTimeout(() => this._showStatus(statusEl, '', ''), 2500);
        },

        attachDebounced(urlInput, titleInput, notesInput, statusEl) {
            this._lastUrl = null;
            urlInput.addEventListener('input', () => {
                clearTimeout(this._timer);
                const url = urlInput.value.trim();
                if (!url || url.length < 6) return;
                this._timer = setTimeout(() => {
                    this.fetchFor(url, titleInput, notesInput, statusEl);
                }, 700);
            });
            urlInput.addEventListener('blur', () => {
                clearTimeout(this._timer);
                const url = urlInput.value.trim();
                if (url && url.length >= 6) {
                    this.fetchFor(url, titleInput, notesInput, statusEl);
                }
            });
        },

        _showStatus(el, msg, color) {
            if (!el) return;
            el.textContent = msg;
            el.style.color = color;
        },
    };

    // ── Keyboard Navigation ───────────────────────────────────────────────────
    const keyboard_nav = {
        _focusedId: null,

        init() {
            document.addEventListener('keydown', e => this._onKey(e));
            document.getElementById('dial-grid')?.addEventListener('mousedown', () => this.clear());
        },

        afterRender() {
            if (this._focusedId === null) return;
            const card = this._cardById(this._focusedId);
            if (card) { card.classList.add('kb-focus'); }
            else      { this._focusedId = null; }
        },

        clear() {
            document.querySelectorAll('.dial-card.kb-focus').forEach(c => c.classList.remove('kb-focus'));
            this._focusedId = null;
        },

        _isBlocked() {
            if (modal.el) return true;
            const tag = document.activeElement?.tagName;
            return ['INPUT','TEXTAREA','SELECT'].includes(tag);
        },

        _cards() {
            return [...document.querySelectorAll('#dial-grid .dial-card:not(.search-hidden)')];
        },

        _cardById(id) {
            return document.querySelector(`#dial-grid .dial-card[data-dial-id="${id}"]`);
        },

        _idxOf(id) {
            return this._cards().findIndex(c => parseInt(c.dataset.dialId) === id);
        },

        _colCount() {
            const cards = this._cards();
            if (cards.length < 2) return 1;
            const firstTop = cards[0].getBoundingClientRect().top;
            let cols = 0;
            for (const c of cards) {
                if (Math.abs(c.getBoundingClientRect().top - firstTop) < 4) cols++;
                else break;
            }
            return Math.max(1, cols);
        },

        _focusIdx(idx) {
            const cards = this._cards();
            if (!cards.length) return;
            const clamped = Math.max(0, Math.min(idx, cards.length - 1));
            document.querySelectorAll('.dial-card.kb-focus').forEach(c => c.classList.remove('kb-focus'));
            const card = cards[clamped];
            card.classList.add('kb-focus');
            this._focusedId = parseInt(card.dataset.dialId);
            card.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
        },

        _onKey(e) {
            const key = e.key;
            if (key === '/') return;
            if (key === 'Escape' && this._focusedId !== null && !modal.el) {
                this.clear(); e.preventDefault(); return;
            }
            const navKeys = ['ArrowRight','ArrowLeft','ArrowUp','ArrowDown','Enter','Delete','Backspace','e','E'];
            if (!navKeys.includes(key)) return;
            if (this._isBlocked()) return;
            const cards = this._cards();
            if (!cards.length) return;
            if (this._focusedId === null) {
                if (['ArrowRight','ArrowLeft','ArrowUp','ArrowDown'].includes(key)) {
                    e.preventDefault(); this._focusIdx(0);
                }
                return;
            }
            const idx  = this._idxOf(this._focusedId);
            const cols = this._colCount();
            switch (key) {
                case 'ArrowRight': e.preventDefault(); this._focusIdx(idx + 1); break;
                case 'ArrowLeft':  e.preventDefault(); this._focusIdx(idx - 1); break;
                case 'ArrowDown':  e.preventDefault(); this._focusIdx(Math.min(idx + cols, cards.length - 1)); break;
                case 'ArrowUp':    e.preventDefault(); this._focusIdx(Math.max(idx - cols, 0)); break;
                case 'Enter': {
                    e.preventDefault();
                    const card = this._cardById(this._focusedId);
                    if (!card) break;
                    api.post(`/api/dials/${this._focusedId}/click`, {}).catch(() => {});
                    window.open(card.href, '_blank', 'noopener,noreferrer');
                    break;
                }
                case 'Delete':
                case 'Backspace': {
                    e.preventDefault();
                    if (activeGroupId === RECENT_GROUP_ID) break; // read-only in recent
                    const dialId = this._focusedId;
                    const allDials = Object.values(dials_module._cache).flat();
                    const dial = allDials.find(d => d.id === dialId);
                    if (!dial) break;
                    const nextIdx = idx < cards.length - 1 ? idx : idx - 1;
                    const nextId  = (nextIdx >= 0 && cards[nextIdx] && parseInt(cards[nextIdx].dataset.dialId) !== dialId)
                        ? parseInt(cards[nextIdx].dataset.dialId) : null;
                    dials_module.confirmDelete(dial, () => { this._focusedId = nextId; });
                    break;
                }
                case 'e':
                case 'E': {
                    e.preventDefault();
                    const allDials2 = Object.values(dials_module._cache).flat();
                    const dial2 = allDials2.find(d => d.id === this._focusedId);
                    if (dial2) dials_module.showEditModal(dial2);
                    break;
                }
            }
        },
    };

    // ── Search / Filter ───────────────────────────────────────────────────────
    const search_module = {
        _query: '',
        _timer: null,
        init() {
            const input = document.getElementById('dial-search');
            const clear = document.getElementById('search-clear');
            if (!input) return;
            input.addEventListener('input', () => {
                clearTimeout(this._timer);
                this._timer = setTimeout(() => {
                    this._query = input.value.trim().toLowerCase();
                    clear.style.display = this._query ? '' : 'none';
                    this.filter();
                }, 150);
            });
            clear.addEventListener('click', () => {
                input.value = ''; this._query = ''; clear.style.display = 'none';
                this.filter(); input.focus();
            });
            document.addEventListener('keydown', e => {
                if (e.key === '/' && !['INPUT','TEXTAREA'].includes(document.activeElement.tagName)) {
                    e.preventDefault(); keyboard_nav.clear(); input.focus();
                }
                if (e.key === 'Escape' && document.activeElement === input) input.blur();
            });
        },
        // sesja 070: notes included in search automatically
        filter() {
            keyboard_nav.clear();
            const q    = this._query;
            const grid = document.getElementById('dial-grid');
            const info = document.getElementById('search-info');
            if (!grid) return;
            const cards = grid.querySelectorAll('.dial-card');
            if (!cards.length) { if (info) info.style.display = 'none'; return; }
            if (!q) {
                cards.forEach(c => c.classList.remove('search-hidden'));
                if (info) info.style.display = 'none'; return;
            }
            let shown = 0;
            let notesMatches = 0;
            cards.forEach(card => {
                const title      = (card.querySelector('.dial-title')?.textContent || '').toLowerCase();
                const url        = (card.dataset.url   || '').toLowerCase();
                const notes      = (card.dataset.notes || '').toLowerCase();
                const matchTitle = title.includes(q) || url.includes(q);
                const matchNotes = notes.includes(q);
                const match      = matchTitle || matchNotes;
                card.classList.toggle('search-hidden', !match);
                if (match) {
                    shown++;
                    if (!matchTitle && matchNotes) notesMatches++;
                }
            });
            if (info) {
                info.style.display = '';
                const notesPart = notesMatches > 0 ? ` (${notesMatches} in notes)` : '';
                info.textContent = shown === 0
                    ? `No dials match "${q}"`
                    : `${shown} dial${shown !== 1 ? 's' : ''} matching "${q}"${notesPart}`;
            }
        },
        reapply() { if (this._query) this.filter(); },
        clear() {
            const input = document.getElementById('dial-search');
            const clear = document.getElementById('search-clear');
            if (input) input.value = '';
            if (clear) clear.style.display = 'none';
            this._query = '';
            const info = document.getElementById('search-info');
            if (info) info.style.display = 'none';
        }
    };

    // ── Sort ──────────────────────────────────────────────────────────────────
    const sort_module = {
        OPTIONS: [
            { id: 'manual',    label: 'Manual',    title: 'Manual order — drag to reorder' },
            { id: 'name_asc',  label: 'A → Z',     title: 'Sort by name A→Z' },
            { id: 'name_desc', label: 'Z → A',     title: 'Sort by name Z→A' },
            { id: 'clicks',    label: '🔥 Popular', title: 'Most clicked first' },
            { id: 'newest',    label: 'Newest',    title: 'Date added — newest first' },
            { id: 'oldest',    label: 'Oldest',    title: 'Date added — oldest first' },
        ],
        init() {},
        get(groupId)  { return localStorage.getItem('dv-sort-' + groupId) || 'manual'; },
        set(groupId, sortId) { localStorage.setItem('dv-sort-' + groupId, sortId); },
        isManual(groupId) { return this.get(groupId) === 'manual'; },

        /**
         * Apply sort — pinned dials always stay at the top (sesja 061).
         * Recent group: always sorted by last_click (server already does this,
         * but we keep it stable client-side regardless of local sort preference).
         */
        apply(dials, groupId) {
            // Recent group is always ordered by last_click — no client-side re-sort
            if (groupId === RECENT_GROUP_ID) return [...dials];

            const sort   = this.get(groupId);
            const pinned = dials.filter(d => d.pinned);
            const rest   = dials.filter(d => !d.pinned);

            const sortFn = (arr) => {
                const a = [...arr];
                switch (sort) {
                    case 'name_asc':
                        return a.sort((x, y) => (x.title||'').localeCompare(y.title||'', undefined, {sensitivity:'base'}));
                    case 'name_desc':
                        return a.sort((x, y) => (y.title||'').localeCompare(x.title||'', undefined, {sensitivity:'base'}));
                    case 'clicks':
                        return a.sort((x, y) => (y.click_count||0) - (x.click_count||0));
                    case 'newest':
                        return a.sort((x, y) => new Date((y.created_at||'').replace(' ','T')) - new Date((x.created_at||'').replace(' ','T')));
                    case 'oldest':
                        return a.sort((x, y) => new Date((x.created_at||'').replace(' ','T')) - new Date((y.created_at||'').replace(' ','T')));
                    default:
                        return a;
                }
            };

            return [...sortFn(pinned), ...sortFn(rest)];
        },

        renderBar(groupId, dialCount) {
            document.getElementById('sort-bar')?.remove();
            // No sort bar for recent group — order is always by last_click
            if (dialCount === 0 || bulk_module.active || groupId === RECENT_GROUP_ID) return;
            const current = this.get(groupId);
            const bar     = document.createElement('div');
            bar.id        = 'sort-bar';
            bar.className = 'sort-bar';

            const lbl = document.createElement('span');
            lbl.className   = 'sort-bar-label';
            lbl.textContent = 'Sort:';
            bar.appendChild(lbl);

            const opts = document.createElement('div');
            opts.className = 'sort-options';
            this.OPTIONS.forEach(opt => {
                const btn = document.createElement('button');
                btn.type      = 'button';
                btn.className = 'sort-btn' + (opt.id === current ? ' active' : '');
                btn.title     = opt.title;
                btn.textContent = opt.label;
                btn.addEventListener('click', () => {
                    if (this.get(groupId) === opt.id) return;
                    this.set(groupId, opt.id);
                    keyboard_nav.clear();
                    const grid = document.getElementById('dial-grid');
                    if (grid) dials_module.render(groupId, grid);
                });
                opts.appendChild(btn);
            });
            bar.appendChild(opts);

            if (current !== 'manual' && groupId !== 'all') {
                const hint = document.createElement('span');
                hint.className  = 'sort-bar-hint';
                hint.textContent = '↑ Switch to Manual to drag & reorder';
                bar.appendChild(hint);
            }

            const grid = document.getElementById('dial-grid');
            if (grid?.parentNode) grid.parentNode.insertBefore(bar, grid);
        },
    };

    // ── Groups ────────────────────────────────────────────────────────────────
    const groups_module = {
        bar: null,
        EMOJIS: ['💼','📁','📂','📋','📊','📈','📉','💻','🖥️','🖨️','⌨️','🖱️','⚙️','🔧','🔨','🔩','🛠️','📌','📍','🗂️','🗃️','📚','📖','📝','✏️','🖊️','📐','📏','🔑','🗝️','🔐','🔒','🛡️','🔔','💡','⚡','🌐','📱','📞','☎️','✉️','📧','📨','📩','📦','🎁','🏆','🥇','⭐','🌟','❤️','💜','💙','💚','🧡','💛','🔥','❄️','🌱','🌍','🌙','☀️','⛅','🎵','🎮','🎬','📺','🎨','🎯','🏋️','🚀','✈️','🚗','🏠','🏢','🏖️','🛒','☕','🍕','🍔','🍺','🎓','👥','👤','🤝','🧠','💰','💳','📰','🔖','📅','⏰','🔍','🗺️','🌈','🎪'],
        COLORS: [
            {label:'Red',hex:'#E53E3E'},{label:'Orange',hex:'#DD6B20'},{label:'Yellow',hex:'#D69E2E'},
            {label:'Green',hex:'#38A169'},{label:'Teal',hex:'#319795'},{label:'Blue',hex:'#3182CE'},
            {label:'Indigo',hex:'#5A67D8'},{label:'Purple',hex:'#805AD5'},{label:'Pink',hex:'#D53F8C'},
            {label:'Cyan',hex:'#00B5D8'},{label:'Gray',hex:'#718096'},{label:'Dark',hex:'#2D3748'},
        ],

        async init() {
            this.bar = document.querySelector('.groups-bar');
            if (!this.bar) return;
            groups = window.LETADIAL_BOOT?.groups || [];
            this.render();
            document.getElementById('btn-add-group')?.addEventListener('click', () => this.showAddModal());
            document.getElementById('btn-create-first')?.addEventListener('click', () => this.showAddModal());
        },

        async reload() {
            const data = await api.get('/api/groups');
            if (!data.ok) { toast.error('Could not load groups.'); return; }
            groups = data.groups || [];
            this.render();
        },

        render() {
            if (!this.bar) return;
            this.bar.querySelectorAll('.group-tab[data-group-id]:not([data-group-id="all"]):not([data-group-id="recent"])').forEach(t => t.remove());

            const total  = groups.reduce((s, g) => s + (parseInt(g.dial_count) || 0), 0);

            // ── All tab ───────────────────────────────────────────────────────
            const allTab = this.bar.querySelector('[data-group-id="all"]');
            if (allTab) {
                allTab.querySelector('.tab-count').textContent = total;
                allTab.classList.toggle('active', activeGroupId === 'all');
                allTab.onclick = () => {
                    search_module.clear(); keyboard_nav.clear();
                    activeGroupId = 'all'; localStorage.setItem('dv-last-group','all');
                    this.render(); dials_module.load('all');
                };
            }

            // ── Recent tab (sesja 062 + 064 privacy) ──────────────────────────
            // When recentDisabled=true: remove tab, redirect away if active.
            if (window.LETADIAL_BOOT?.recentDisabled) {
                const existingRecent = this.bar.querySelector('[data-group-id="recent"]');
                if (existingRecent) existingRecent.remove();
                if (activeGroupId === RECENT_GROUP_ID) {
                    activeGroupId = 'all';
                    localStorage.setItem('dv-last-group', 'all');
                }
            } else {
            let recentTab = this.bar.querySelector('[data-group-id="recent"]');
            if (!recentTab) {
                recentTab = document.createElement('button');
                recentTab.type = 'button';
                recentTab.className = 'group-tab';
                recentTab.dataset.groupId = 'recent';
                recentTab.innerHTML = `<span class="tab-icon" aria-hidden="true">🕐</span><span class="tab-name">Recent</span>`;
                recentTab.title = 'Recently clicked dials — last 20';
                // Insert after All tab, before real group tabs
                const addBtn = this.bar.querySelector('#btn-add-group');
                if (allTab && allTab.nextSibling) {
                    this.bar.insertBefore(recentTab, allTab.nextSibling);
                } else if (addBtn) {
                    this.bar.insertBefore(recentTab, addBtn);
                } else {
                    this.bar.appendChild(recentTab);
                }
            }
            recentTab.classList.toggle('active', activeGroupId === RECENT_GROUP_ID);
            recentTab.onclick = () => {
                search_module.clear(); keyboard_nav.clear();
                activeGroupId = RECENT_GROUP_ID;
                localStorage.setItem('dv-last-group', RECENT_GROUP_ID);
                this.render(); dials_module.load(RECENT_GROUP_ID);
            };
            } // end else (!recentDisabled)

            const addBtn = this.bar.querySelector('#btn-add-group');
            groups.forEach(g => {
                const tab = document.createElement('button');
                tab.type = 'button';
                tab.className = 'group-tab' + (activeGroupId == g.id ? ' active' : '');
                tab.dataset.groupId = g.id;
                if (g.color) tab.style.setProperty('--tab-c', g.color);

                const iconHtml = g.icon_path
                    ? `<img class="tab-icon-img" src="/api/group_icons/${g.id}?t=${Date.now()}" alt="" loading="lazy" onerror="this.style.display='none'">`
                    : (g.icon ? `<span class="tab-icon" aria-hidden="true">${escHtml(g.icon)}</span>` : '');

                tab.innerHTML = `${iconHtml}<span class="tab-name">${escHtml(g.name)}</span><span class="tab-count">${g.dial_count || 0}</span>`;

                tab.addEventListener('click', () => {
                    search_module.clear(); keyboard_nav.clear();
                    activeGroupId = g.id; localStorage.setItem('dv-last-group', String(g.id));
                    this.render(); dials_module.load(g.id);
                });

                tab.addEventListener('contextmenu', e => {
                    e.preventDefault();
                    const idx = groups.findIndex(x => x.id == g.id);
                    contextMenu.show(e, [
                        { label: 'Rename group',  icon: '✏',  action: () => this.showRenameModal(g) },
                        { label: 'Icon & color…', icon: '🎨', action: () => this.showStyleModal(g) },
                        { separator: true },
                        { label: 'Move left',  icon: '←', action: () => this.moveGroup(idx, -1), disabled: idx === 0 },
                        { label: 'Move right', icon: '→', action: () => this.moveGroup(idx, +1), disabled: idx === groups.length - 1 },
                        { separator: true },
                        { label: 'Delete group', icon: '🗑', danger: true, action: () => this.confirmDelete(g) },
                    ]);
                });

                if (addBtn) this.bar.insertBefore(tab, addBtn);
                else this.bar.appendChild(tab);
            });
            dials_module.load(activeGroupId);
        },

        async moveGroup(idx, direction) {
            const newIdx = idx + direction;
            if (newIdx < 0 || newIdx >= groups.length) return;
            [groups[idx], groups[newIdx]] = [groups[newIdx], groups[idx]];
            const ids = groups.map(g => g.id);
            const r = await api.post('/api/groups/reorder', { ids });
            if (!r.ok) { toast.error('Could not reorder groups.'); await this.reload(); return; }
            this.render();
        },

        showAddModal() {
            modal.show({
                title: 'New Group',
                body: `<div class="form-group"><label class="form-label" for="new-group-name">GROUP NAME</label>
                    <input type="text" id="new-group-name" class="form-input" placeholder="e.g. Work, Shopping…" maxlength="100" autocomplete="off"></div>`,
                confirmLabel: 'Create',
                onConfirm: async () => {
                    const name = document.getElementById('new-group-name')?.value?.trim();
                    if (!name) { toast.error('Please enter a group name.'); return false; }
                    const r = await api.post('/api/groups', { name });
                    if (!r.ok) { toast.error(r.error || 'Could not create group.'); return false; }
                    toast.success(`Group "${name}" created.`);
                    await this.reload(); activeGroupId = r.id; this.render();
                    return true;
                }
            });
            setTimeout(() => document.getElementById('new-group-name')?.focus(), 80);
        },

        showRenameModal(group) {
            modal.show({
                title: 'Rename Group',
                body: `<div class="form-group"><label class="form-label" for="rename-name">NEW NAME</label>
                    <input type="text" id="rename-name" class="form-input" value="${escHtml(group.name)}" maxlength="100" autocomplete="off"></div>`,
                confirmLabel: 'Rename',
                onConfirm: async () => {
                    const name = document.getElementById('rename-name')?.value?.trim();
                    if (!name) { toast.error('Name cannot be empty.'); return false; }
                    const r = await api.put(`/api/groups/${group.id}`, { name });
                    if (!r.ok) { toast.error(r.error || 'Could not rename.'); return false; }
                    toast.success('Group renamed.'); await this.reload();
                    return true;
                }
            });
            setTimeout(() => { const i = document.getElementById('rename-name'); i?.focus(); i?.select(); }, 80);
        },

        showStyleModal(group) {
            let selectedIcon    = group.icon  || null;
            let selectedColor   = group.color || null;
            let selectedFile    = null;
            let clearCustomIcon = false;
            const hasCustomIcon = !!group.icon_path;
            const ts            = Date.now();

            const emojiGrid    = this.EMOJIS.map(e => `<button type="button" class="emoji-btn" data-emoji="${e}" title="${e}">${e}</button>`).join('');
            const colorSwatches = this.COLORS.map(c => `<button type="button" class="color-swatch" data-color="${c.hex}" title="${c.label}" style="background:${c.hex}" aria-label="${c.label}"></button>`).join('');
            const currentIconHtml = hasCustomIcon
                ? `<img class="style-cur-icon-img" src="/api/group_icons/${group.id}?t=${ts}" alt="">`
                : (group.icon ? `<span style="font-size:1.4rem">${escHtml(group.icon)}</span>` : '<span style="color:var(--text-faint)">—</span>');
            const currentColorDisp = group.color ? `<span class="color-preview-dot" style="background:${group.color}"></span>${group.color}` : '—';

            modal.show({
                title: 'Icon & color',
                body: `<div class="style-modal-body">
                  <div class="style-section">
                    <div class="style-section-head">
                      <span class="form-label" style="margin:0">ICON</span>
                      <span class="style-current-val" id="sm-icon-val">${currentIconHtml}</span>
                    </div>
                    <div class="emoji-grid">${emojiGrid}</div>
                    <div class="style-row" style="margin-top:.5rem;gap:.5rem;align-items:center;flex-wrap:wrap">
                      <input type="text" id="sm-custom-emoji" class="form-input" style="width:70px;text-align:center;font-size:1.3rem;padding:.4rem" maxlength="4" placeholder="✍️">
                      <span style="color:var(--text-faint);font-size:.8rem;flex-shrink:0">lub</span>
                      <label class="btn btn-ghost btn-sm" style="cursor:pointer;margin:0" title="JPEG, PNG, GIF, WebP — max 2 MB">
                        📂 Upload<input type="file" id="sm-icon-file" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none">
                      </label>
                      <button type="button" class="btn btn-ghost btn-sm" id="sm-clear-icon">✕ Clear icon</button>
                    </div>
                    <div id="sm-upload-preview" style="display:none;align-items:center;gap:.6rem;margin-top:.5rem;padding:.5rem;background:var(--surface-alt);border-radius:var(--radius-sm);border:1px solid var(--border)">
                      <img id="sm-upload-img" style="width:48px;height:48px;border-radius:var(--radius-sm);border:1px solid var(--border);object-fit:cover;flex-shrink:0" alt="preview">
                      <div style="font-size:.75rem;color:var(--text-muted);min-width:0"><div id="sm-upload-name" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></div></div>
                    </div>
                  </div>
                  <div class="style-section" style="margin-top:1rem">
                    <div class="style-section-head">
                      <span class="form-label" style="margin:0">TAB COLOR</span>
                      <span class="style-current-val" id="sm-color-val">${currentColorDisp}</span>
                    </div>
                    <div class="color-swatches">${colorSwatches}</div>
                    <div class="style-row" style="margin-top:.5rem;gap:.5rem">
                      <input type="color" id="sm-custom-color" class="color-picker-input" value="${group.color || '#3182CE'}">
                      <label for="sm-custom-color" class="btn btn-ghost btn-sm" style="cursor:pointer">Custom…</label>
                      <button type="button" class="btn btn-ghost btn-sm" id="sm-clear-color">No color</button>
                    </div>
                  </div>
                  <div class="style-preview">
                    <span class="style-preview-label">Preview:</span>
                    <div class="style-preview-tab" id="sm-preview-tab">
                      <span class="tab-icon" id="sm-prev-emoji" style="${(group.icon && !hasCustomIcon) ? '' : 'display:none'}">${group.icon || ''}</span>
                      <img class="tab-icon-img" id="sm-prev-img" src="${hasCustomIcon ? `/api/group_icons/${group.id}?t=${ts}` : ''}" style="${hasCustomIcon ? '' : 'display:none'}" alt="">
                      <span class="tab-name">${escHtml(group.name)}</span>
                      <span class="tab-count">0</span>
                    </div>
                  </div>
                </div>`,
                confirmLabel: 'Save',
                onConfirm: async () => {
                    const r = await api.put(`/api/groups/${group.id}/style`, { icon: selectedIcon ?? '', color: selectedColor ?? '' });
                    if (!r.ok) { toast.error(r.error || 'Could not save style.'); return false; }
                    if (selectedFile) {
                        const fd = new FormData(); fd.append('icon', selectedFile);
                        try {
                            const res  = await fetch(`/api/group_icons/${group.id}/upload`, { method: 'POST', headers: { 'X-CSRF-Token': api.csrf() }, credentials: 'same-origin', body: fd });
                            const data = await res.json();
                            if (!data.ok) { toast.error(data.error || 'Icon upload failed.'); return false; }
                        } catch { toast.error('Upload failed: network error.'); return false; }
                    }
                    if (!selectedFile && clearCustomIcon && group.icon_path) await api.delete(`/api/group_icons/${group.id}`);
                    toast.success('Style saved.'); await this.reload(); return true;
                }
            });

            setTimeout(() => {
                const iconVal = document.getElementById('sm-icon-val');
                const colorVal = document.getElementById('sm-color-val');
                const prevTab = document.getElementById('sm-preview-tab');
                const prevEmoji = document.getElementById('sm-prev-emoji');
                const prevImg = document.getElementById('sm-prev-img');
                const uploadPrev = document.getElementById('sm-upload-preview');
                const uploadImg = document.getElementById('sm-upload-img');
                const uploadName = document.getElementById('sm-upload-name');
                const customEmoji = document.getElementById('sm-custom-emoji');

                const updatePreview = () => {
                    if (prevEmoji) { prevEmoji.textContent = selectedIcon || ''; prevEmoji.style.display = (selectedIcon && !selectedFile) ? '' : 'none'; }
                    if (prevImg) { prevImg.style.display = (selectedFile || (!clearCustomIcon && group.icon_path)) ? '' : 'none'; }
                    if (iconVal) {
                        if (selectedFile && uploadImg?.src) iconVal.innerHTML = `<img class="style-cur-icon-img" src="${escHtml(uploadImg.src)}" alt="">`;
                        else if (selectedIcon) iconVal.innerHTML = `<span style="font-size:1.4rem">${escHtml(selectedIcon)}</span>`;
                        else if (!clearCustomIcon && group.icon_path) iconVal.innerHTML = `<img class="style-cur-icon-img" src="/api/group_icons/${group.id}?t=${ts}" alt="">`;
                        else iconVal.innerHTML = '<span style="color:var(--text-faint)">—</span>';
                    }
                    if (prevTab) prevTab.style.setProperty('--tab-c', selectedColor || '');
                    if (colorVal) colorVal.innerHTML = selectedColor ? `<span class="color-preview-dot" style="background:${selectedColor}"></span>${selectedColor}` : '—';
                    document.querySelectorAll('.emoji-btn').forEach(b => b.classList.toggle('active', b.dataset.emoji === selectedIcon));
                    document.querySelectorAll('.color-swatch').forEach(b => b.classList.toggle('active', b.dataset.color?.toLowerCase() === selectedColor?.toLowerCase()));
                };

                document.querySelectorAll('.emoji-btn').forEach(btn => btn.addEventListener('click', () => {
                    selectedIcon = btn.dataset.emoji; selectedFile = null; clearCustomIcon = true;
                    if (customEmoji) customEmoji.value = '';
                    if (uploadPrev) uploadPrev.style.display = 'none';
                    updatePreview();
                }));
                customEmoji?.addEventListener('input', function() {
                    const val = this.value.trim();
                    if (val) { selectedIcon = val; selectedFile = null; clearCustomIcon = true; if (uploadPrev) uploadPrev.style.display = 'none'; updatePreview(); }
                });
                document.getElementById('sm-icon-file')?.addEventListener('change', function() {
                    const f = this.files?.[0];
                    if (!f) return;
                    if (f.size > 2*1024*1024) { toast.error('File too large (max 2 MB).'); this.value = ''; return; }
                    if (uploadImg?.src?.startsWith('blob:')) URL.revokeObjectURL(uploadImg.src);
                    const objUrl = URL.createObjectURL(f);
                    selectedFile = f; selectedIcon = null; clearCustomIcon = false;
                    if (customEmoji) customEmoji.value = '';
                    if (uploadImg) uploadImg.src = objUrl;
                    if (uploadName) uploadName.textContent = f.name;
                    if (uploadPrev) uploadPrev.style.display = 'flex';
                    if (prevImg) { prevImg.src = objUrl; prevImg.style.display = ''; }
                    updatePreview();
                });
                document.getElementById('sm-clear-icon')?.addEventListener('click', () => {
                    selectedIcon = null; selectedFile = null; clearCustomIcon = true;
                    if (customEmoji) customEmoji.value = '';
                    const fi = document.getElementById('sm-icon-file'); if (fi) fi.value = '';
                    if (uploadPrev) uploadPrev.style.display = 'none';
                    if (prevImg) prevImg.style.display = 'none';
                    updatePreview();
                });
                document.querySelectorAll('.color-swatch').forEach(btn => btn.addEventListener('click', () => {
                    selectedColor = btn.dataset.color.toLowerCase();
                    const cp = document.getElementById('sm-custom-color'); if (cp) cp.value = selectedColor;
                    updatePreview();
                }));
                document.getElementById('sm-custom-color')?.addEventListener('input', () => {
                    selectedColor = document.getElementById('sm-custom-color').value.toLowerCase(); updatePreview();
                });
                document.getElementById('sm-clear-color')?.addEventListener('click', () => { selectedColor = null; updatePreview(); });
                updatePreview();
            }, 80);
        },

        confirmDelete(group) {
            const n = parseInt(group.dial_count) || 0;
            const warn = n > 0 ? `<div class="alert alert-warning" style="margin-top:.75rem"><span class="alert-icon">⚠</span><span>Also deletes <strong>${n} dial${n !== 1 ? 's' : ''}</strong>.</span></div>` : '';
            modal.show({
                title: 'Delete Group',
                body: `<p>Delete <strong>"${escHtml(group.name)}"</strong>?</p>${warn}<p class="text-muted text-sm" style="margin-top:.5rem">Cannot be undone.</p>`,
                confirmLabel: 'Delete', confirmClass: 'btn-danger',
                onConfirm: async () => {
                    const r = await api.delete(`/api/groups/${group.id}`);
                    if (!r.ok) { toast.error(r.error || 'Could not delete.'); return false; }
                    toast.success(`Deleted "${group.name}".`);
                    activeGroupId = 'all'; await this.reload(); return true;
                }
            });
        }
    };

    // ── Dials ─────────────────────────────────────────────────────────────────
    const dials_module = {
        _cache: {},

        async load(groupId) {
            bulk_module.reset();
            keyboard_nav.clear();
            document.querySelectorAll('.group-tab').forEach(t => t.classList.toggle('active', t.dataset.groupId == groupId));
            const grid = document.getElementById('dial-grid');
            if (!grid) return;

            if (groups.length === 0 && groupId !== RECENT_GROUP_ID) {
                grid.innerHTML = `<div class="empty-state"><div class="empty-state-icon">📌</div>
                    <h3>No groups yet</h3><p>Create your first group to start adding speed dials.</p>
                    <button id="btn-create-first" class="btn btn-primary" type="button">Create first group</button></div>`;
                document.getElementById('btn-create-first')?.addEventListener('click', () => groups_module.showAddModal());
                return;
            }

            grid.innerHTML = '<div class="dials-loading"></div>';

            let url;
            if (groupId === RECENT_GROUP_ID) {
                // Safety: if Recent is disabled and someone navigates here anyway, redirect
                if (window.LETADIAL_BOOT?.recentDisabled) {
                    activeGroupId = 'all';
                    localStorage.setItem('dv-last-group', 'all');
                    await this.load('all');
                    return;
                }
                url = '/api/dials?recent=1';
            } else if (groupId === 'all') {
                url = '/api/dials';
            } else {
                url = `/api/dials?group_id=${groupId}`;
            }

            const data = await api.get(url);
            if (!data.ok) { toast.error('Could not load dials.'); grid.innerHTML = ''; return; }
            this._cache[groupId] = data.dials || [];
            this.render(groupId, grid);
        },

        render(groupId, grid) {
            const rawDials = this._cache[groupId] || [];
            const dials    = sort_module.apply(rawDials, groupId);

            grid.innerHTML = '';
            grid.classList.toggle('bulk-select-active', bulk_module.active);

            const isRecent = groupId === RECENT_GROUP_ID;

            dials.forEach(d => grid.appendChild(this.makeCard(d, isRecent)));

            if (!isRecent && groupId !== 'all' && !bulk_module.active) {
                grid.appendChild(this.makeAddCard(groupId));
            } else if (dials.length === 0 && !bulk_module.active) {
                if (isRecent) {
                    grid.innerHTML = `<div class="empty-state"><div class="empty-state-icon" style="font-size:2rem;opacity:.3">🕐</div>
                        <h3 style="color:var(--text-faint);font-weight:500">No recent activity</h3>
                        <p>Dials you click will appear here, ordered by most recently used.</p></div>`;
                } else {
                    grid.innerHTML = `<div class="empty-state"><div class="empty-state-icon" style="font-size:2rem;opacity:.3">🔗</div>
                        <h3 style="color:var(--text-faint);font-weight:500">No dials yet</h3>
                        <p>Select a group and click <strong>+ Add dial</strong> to get started.</p></div>`;
                }
            }

            grid.oncontextmenu = (e) => {
                if (bulk_module.active || isRecent) return;
                if (e.target.closest('.dial-card') || e.target.closest('.dial-add-card')) return;
                e.preventDefault();
                const currentDials = this._cache[activeGroupId] || [];
                if (currentDials.length === 0) return;
                contextMenu.show(e, [
                    { label: `Open all ${currentDials.length} dial${currentDials.length !== 1 ? 's' : ''} in new tabs`, icon: '🔗', action: () => this.openAllInTabs(currentDials) },
                ]);
            };
            sort_module.renderBar(groupId, dials.length);
            search_module.reapply();
            keyboard_nav.afterRender();
        },

        openAllInTabs(dials) {
            dials.forEach(d => {
                const a = document.createElement('a');
                a.href = d.url; a.target = '_blank'; a.rel = 'noopener noreferrer';
                a.style.display = 'none'; document.body.appendChild(a); a.click(); document.body.removeChild(a);
                api.post(`/api/dials/${d.id}/click`, {}).catch(() => {});
            });
            toast.info(`Opening ${dials.length} dial${dials.length !== 1 ? 's' : ''} in new tabs…`);
        },

        /**
         * makeCard — sesja 062: when isRecent=true, show group badge + relative time
         * instead of the notes row, and suppress drag/drop/pin.
         */
        makeCard(dial, isRecent = false) {
            const card = document.createElement('a');
            card.className = 'dial-card' + (dial.pinned && !isRecent ? ' pinned' : '');
            card.href = dial.url; card.target = '_blank'; card.rel = 'noopener noreferrer';
            card.dataset.dialId = dial.id; card.dataset.url = dial.url || ''; card.dataset.notes = dial.notes || '';

            if (bulk_module.active && bulk_module.selected.has(dial.id)) card.classList.add('selected');

            let host = '';
            try { host = new URL(dial.url).hostname; } catch {}

            // Pin badge (sesja 061) — hidden in recent view
            if (!isRecent) {
                const pinBadge = document.createElement('span');
                pinBadge.className = 'dial-pin-badge';
                pinBadge.setAttribute('aria-label', 'Pinned');
                pinBadge.textContent = '📌';
                card.appendChild(pinBadge);
            }

            const header = document.createElement('div');
            header.className = 'dial-header';
            if (host) {
                const fav = document.createElement('img');
                fav.className = 'dial-favicon'; fav.src = `https://${host}/favicon.ico`;
                fav.alt = ''; fav.loading = 'lazy'; fav.onerror = () => fav.remove();
                header.appendChild(fav);
            }
            const titleEl = document.createElement('span');
            titleEl.className = 'dial-title';
            titleEl.textContent = dial.title || host || dial.url;
            header.appendChild(titleEl);
            card.appendChild(header);

            // Recent info row: group badge + time — replaces notes in recent view
            if (isRecent && dial.last_click) {
                const infoRow = document.createElement('div');
                infoRow.className = 'dial-recent-info';

                const badge = document.createElement('span');
                badge.className = 'dial-group-badge';
                badge.textContent = dial.group_name || '';
                badge.title = dial.group_name || '';
                infoRow.appendChild(badge);

                const timeEl = document.createElement('span');
                timeEl.className = 'dial-recent-time';
                timeEl.textContent = _relativeTime(dial.last_click);
                timeEl.title = dial.last_click;
                infoRow.appendChild(timeEl);

                card.appendChild(infoRow);
            } else if (!isRecent && dial.notes) {
                const notesWrap = document.createElement('div');
                notesWrap.className = 'dial-notes-tooltip-wrap';
                const notesEl = document.createElement('div');
                notesEl.className = 'dial-notes';
                notesEl.textContent = dial.notes;
                notesWrap.appendChild(notesEl);
                const tooltip = document.createElement('div');
                tooltip.className = 'dial-notes-tooltip';
                tooltip.textContent = dial.notes;
                notesWrap.appendChild(tooltip);
                card.appendChild(notesWrap);
            }

            const wrap = document.createElement('div');
            wrap.className = 'dial-thumb-wrap';
            if (dial.thumb_path) {
                const img = document.createElement('img');
                img.src = `/api/thumbs/${dial.id}`; img.alt = dial.title || '';
                img.loading = 'lazy'; img.onerror = () => img.remove();
                wrap.appendChild(img);
            }
            card.appendChild(wrap);

            if (!isRecent) {
                const checkEl = document.createElement('div');
                checkEl.className = 'dial-select-check';
                checkEl.setAttribute('aria-hidden', 'true');
                checkEl.textContent = '✓';
                card.appendChild(checkEl);
            }

            const hint = document.createElement('div');
            hint.className = 'dial-kb-hint';
            hint.setAttribute('aria-hidden', 'true');
            hint.innerHTML = '<kbd>Enter</kbd> open &nbsp;<kbd>E</kbd> edit &nbsp;<kbd>Del</kbd> delete';
            card.appendChild(hint);

            card.addEventListener('click', e => {
                if (bulk_module.active && !isRecent) { e.preventDefault(); bulk_module.toggle(dial.id); return; }
                if (e.button === 0) {
                    api.post(`/api/dials/${dial.id}/click`, {}).catch(() => {});
                    // Refresh recent cache so time updates on next visit
                    if (this._cache[RECENT_GROUP_ID]) delete this._cache[RECENT_GROUP_ID];
                }
            });
            card.addEventListener('auxclick', e => {
                if (bulk_module.active) return;
                if (e.button === 1) api.post(`/api/dials/${dial.id}/click`, {}).catch(() => {});
            });

            card.addEventListener('contextmenu', e => {
                if (bulk_module.active && !isRecent) { e.preventDefault(); bulk_module.toggle(dial.id); return; }
                e.preventDefault(); e.stopPropagation();

                if (isRecent) {
                    // Minimal context menu for recent view — no pin/reorder, just open/edit/go-to-group
                    const group = groups.find(g => g.id == dial.group_id);
                    contextMenu.show(e, [
                        { label: 'Edit dial',    icon: '✏', action: () => this.showEditModal(dial) },
                        { label: 'Refresh thumbnail', icon: '🔄', action: () => this.refreshThumb(dial) },
                        { separator: true },
                        { label: group ? `Go to "${group.name}"` : 'Go to group', icon: '📂', action: () => {
                            if (!group) return;
                            search_module.clear(); keyboard_nav.clear();
                            activeGroupId = group.id; localStorage.setItem('dv-last-group', String(group.id));
                            groups_module.render(); dials_module.load(group.id);
                        }},
                        { separator: true },
                        { label: 'Delete dial', icon: '🗑', danger: true, action: () => this.confirmDelete(dial) },
                    ]);
                    return;
                }

                const otherGroups   = groups.filter(g => g.id != dial.group_id);
                const moveItems     = otherGroups.length > 0
                    ? otherGroups.map(g => ({ label: g.name, icon: '📁', action: () => this.moveTo(dial, g.id) }))
                    : [{ label: 'No other groups', icon: '', disabled: true, action: () => {} }];
                const duplicateItems = [
                    { label: 'This group', icon: '📋', action: () => this.duplicateDial(dial, dial.group_id) },
                    ...groups.filter(g => g.id != dial.group_id).map(g => ({ label: g.name, icon: '📁', action: () => this.duplicateDial(dial, g.id) }))
                ];
                // Pin label (sesja 061)
                const pinLabel = dial.pinned ? 'Unpin' : 'Pin to top';

                contextMenu.show(e, [
                    { label: 'Edit dial',         icon: '✏',  action: () => this.showEditModal(dial) },
                    { label: pinLabel,            icon: '📌', action: () => this.togglePin(dial) },
                    { label: 'Refresh thumbnail', icon: '🔄', action: () => this.refreshThumb(dial) },
                    { label: 'Move to…',          icon: '📂', submenu: moveItems },
                    { label: 'Duplicate to…',     icon: '⧉',  submenu: duplicateItems },
                    { separator: true },
                    { label: 'Select multiple', icon: '☑', action: () => { bulk_module.enter(); bulk_module.toggle(dial.id); } },
                    { separator: true },
                    { label: 'Delete dial', icon: '🗑', danger: true, action: () => this.confirmDelete(dial) },
                ]);
            });

            // Drag only for unpinned dials in manual sort, never in recent view
            if (!isRecent && activeGroupId !== 'all' && sort_module.isManual(activeGroupId) && !dial.pinned) {
                card.draggable = true;
                card.addEventListener('dragstart', e => {
                    if (bulk_module.active) { e.preventDefault(); return; }
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', String(dial.id));
                    card.classList.add('drag-source');
                    keyboard_nav.clear();
                });
                card.addEventListener('dragend', () => {
                    card.classList.remove('drag-source');
                    document.querySelectorAll('.dial-card.drag-over').forEach(c => c.classList.remove('drag-over'));
                });
                card.addEventListener('dragover', e => {
                    if (bulk_module.active) return;
                    e.preventDefault(); e.dataTransfer.dropEffect = 'move';
                    document.querySelectorAll('.dial-card.drag-over').forEach(c => c.classList.remove('drag-over'));
                    if (card.dataset.dialId !== e.dataTransfer.getData('text/plain')) card.classList.add('drag-over');
                });
                card.addEventListener('dragleave', () => card.classList.remove('drag-over'));
                card.addEventListener('drop', async e => {
                    if (bulk_module.active) return;
                    e.preventDefault(); card.classList.remove('drag-over');
                    const srcId = parseInt(e.dataTransfer.getData('text/plain'));
                    const dstId = parseInt(card.dataset.dialId);
                    if (srcId === dstId) return;
                    const dstDial = (this._cache[activeGroupId] || []).find(d => d.id === dstId);
                    if (dstDial?.pinned) return;
                    await this.reorderDials(srcId, dstId, activeGroupId);
                });
            }
            return card;
        },

        // ── Pin toggle (sesja 061) ─────────────────────────────────────────────
        async togglePin(dial) {
            const r = await api.post(`/api/dials/${dial.id}/pin`, {});
            if (!r.ok) { toast.error(r.error || 'Could not update pin.'); return; }

            const cached = this._cache[activeGroupId] || [];
            const found  = cached.find(d => d.id === dial.id);
            if (found) found.pinned = r.pinned;
            if (this._cache['all']) {
                const foundAll = this._cache['all'].find(d => d.id === dial.id);
                if (foundAll) foundAll.pinned = r.pinned;
            }

            toast.success(r.pinned ? 'Pinned to top.' : 'Unpinned.');
            const grid = document.getElementById('dial-grid');
            if (grid) this.render(activeGroupId, grid);
        },

        async moveTo(dial, newGroupId) {
            const targetGroup = groups.find(g => g.id == newGroupId);
            const r = await api.put(`/api/dials/${dial.id}`, { group_id: newGroupId });
            if (!r.ok) { toast.error(r.error || 'Could not move dial.'); return; }
            toast.success(`Moved "${dial.title || dial.url}" to "${targetGroup?.name || 'group'}".`);
            delete this._cache[activeGroupId]; delete this._cache[newGroupId]; delete this._cache['all'];
            delete this._cache[RECENT_GROUP_ID];
            await groups_module.reload(); groups_module.render();
        },

        async duplicateDial(dial, targetGroupId) {
            const targetGroup = groups.find(g => g.id == targetGroupId);
            const label = targetGroupId == dial.group_id ? 'this group' : `"${targetGroup?.name || 'group'}"`;
            const r = await api.post(`/api/dials/${dial.id}/duplicate`, { group_id: targetGroupId });
            if (!r.ok) { toast.error(r.error || 'Could not duplicate dial.'); return; }
            if (r.id) api.post(`/api/thumbs/${r.id}`, {}).catch(() => {});
            toast.success(`Duplicated "${dial.title || dial.url}" to ${label}.`);
            delete this._cache[targetGroupId]; delete this._cache[activeGroupId]; delete this._cache['all'];
            delete this._cache[RECENT_GROUP_ID];
            await groups_module.reload(); groups_module.render();
        },

        async reorderDials(srcId, dstId, groupId) {
            const dials  = this._cache[groupId] || [];
            const ids    = dials.map(d => d.id);
            const srcIdx = ids.indexOf(srcId); const dstIdx = ids.indexOf(dstId);
            if (srcIdx < 0 || dstIdx < 0) return;
            ids.splice(srcIdx, 1); ids.splice(dstIdx, 0, srcId);
            const sorted = ids.map(id => dials.find(d => d.id === id)).filter(Boolean);
            this._cache[groupId] = sorted;
            const grid = document.getElementById('dial-grid');
            if (grid) this.render(groupId, grid);
            const r = await api.post('/api/dials/reorder', { group_id: parseInt(groupId), ids });
            if (!r.ok) { toast.error('Could not save order.'); delete this._cache[groupId]; await this.load(groupId); }
        },

        makeAddCard(groupId) {
            const card = document.createElement('button');
            card.type = 'button'; card.className = 'dial-add-card'; card.title = 'Add a new speed dial';
            card.innerHTML = `<div class="dial-add-icon">＋</div><span>Add dial</span>`;
            card.addEventListener('click', () => this.showAddModal(parseInt(groupId)));
            return card;
        },

        showAddModal(groupId) {
            if (!groupId || groupId === 'all' || groupId === RECENT_GROUP_ID) { toast.error('Select a specific group first.'); return; }
            modal.show({
                title: 'Add Dial',
                body: `
                    <div class="form-group">
                        <label class="form-label" for="new-dial-url">URL</label>
                        <input type="url" id="new-dial-url" class="form-input" placeholder="https://example.com" autocomplete="off">
                        <div id="new-dial-meta-status" style="min-height:1.2em;font-size:.75rem;margin-top:.25rem;transition:color .2s"></div>
                    </div>
                    <div class="form-group" style="margin-top:.75rem">
                        <label class="form-label" for="new-dial-title">Title <span style="color:var(--text-faint);font-weight:400">(auto-filled from page)</span></label>
                        <input type="text" id="new-dial-title" class="form-input" placeholder="Fetched automatically…" maxlength="100" autocomplete="off">
                    </div>
                    <div class="form-group" style="margin-top:.75rem">
                        <label class="form-label" for="new-dial-notes">Note <span style="color:var(--text-faint);font-weight:400">(auto-filled from description)</span></label>
                        <textarea id="new-dial-notes" class="form-input" rows="2" maxlength="500" placeholder="Fetched from page description…" style="resize:vertical;min-height:54px;font-family:var(--font-sans);font-size:.875rem"></textarea>
                        <div style="text-align:right;font-size:.72rem;color:var(--text-faint);margin-top:.2rem"><span id="new-dial-notes-count">0</span>/500</div>
                    </div>`,
                confirmLabel: 'Add',
                onConfirm: async () => {
                    const url   = document.getElementById('new-dial-url')?.value?.trim();
                    const title = document.getElementById('new-dial-title')?.value?.trim();
                    const notes = document.getElementById('new-dial-notes')?.value?.trim();
                    if (!url) { toast.error('Please enter a URL.'); return false; }
                    const r = await api.post('/api/dials', { group_id: groupId, url, title, notes: notes || '' });
                    if (!r.ok) { toast.error(r.error || 'Could not add dial.'); return false; }
                    toast.success('Dial added.');
                    api.post(`/api/thumbs/${r.id}`, {}).catch(() => {});
                    delete this._cache[groupId]; delete this._cache['all'];
                    delete this._cache[RECENT_GROUP_ID];
                    await groups_module.reload(); activeGroupId = groupId; groups_module.render();
                    return true;
                }
            });
            setTimeout(() => {
                const urlInput   = document.getElementById('new-dial-url');
                const titleInput = document.getElementById('new-dial-title');
                const notesInput = document.getElementById('new-dial-notes');
                const statusEl   = document.getElementById('new-dial-meta-status');
                const notesCount = document.getElementById('new-dial-notes-count');
                urlInput?.focus();
                notesInput?.addEventListener('input', function() { if (notesCount) notesCount.textContent = this.value.length; });
                if (urlInput && titleInput) meta_module.attachDebounced(urlInput, titleInput, notesInput, statusEl);
            }, 80);
        },

        showEditModal(dial) {
            modal.show({
                title: 'Edit Dial',
                body: `
                    <div class="form-group">
                        <label class="form-label" for="edit-dial-url">URL</label>
                        <div style="display:flex;gap:.5rem;align-items:center">
                            <input type="url" id="edit-dial-url" class="form-input" value="${escHtml(dial.url)}" autocomplete="off" style="flex:1">
                            <button type="button" id="edit-dial-fetch-btn" class="btn btn-ghost btn-sm" style="flex-shrink:0;white-space:nowrap">↺ Fetch</button>
                        </div>
                        <div id="edit-dial-meta-status" style="min-height:1.2em;font-size:.75rem;margin-top:.25rem;transition:color .2s"></div>
                    </div>
                    <div class="form-group" style="margin-top:.75rem">
                        <label class="form-label" for="edit-dial-title">TITLE</label>
                        <input type="text" id="edit-dial-title" class="form-input" value="${escHtml(dial.title)}" maxlength="100" autocomplete="off">
                    </div>
                    <div class="form-group" style="margin-top:.75rem">
                        <label class="form-label" for="edit-dial-notes">NOTE <span style="color:var(--text-faint);font-weight:400">(optional)</span></label>
                        <textarea id="edit-dial-notes" class="form-input" rows="2" maxlength="500" placeholder="Short description, reminder…" style="resize:vertical;min-height:54px;font-family:var(--font-sans);font-size:.875rem">${escHtml(dial.notes || '')}</textarea>
                        <div style="text-align:right;font-size:.72rem;color:var(--text-faint);margin-top:.2rem"><span id="edit-dial-notes-count">${(dial.notes||'').length}</span>/500</div>
                    </div>
                    <div class="form-group" style="margin-top:.75rem">
                        <label class="form-label" for="edit-dial-thumb">THUMBNAIL <span style="color:var(--text-faint);font-weight:400">(optional — JPEG, PNG, GIF, WebP, max 5 MB)</span></label>
                        <input type="file" id="edit-dial-thumb" class="form-input" accept="image/jpeg,image/png,image/gif,image/webp" style="cursor:pointer">
                        <div id="edit-dial-thumb-preview" style="display:none;margin-top:.5rem">
                            <img id="edit-dial-thumb-img" style="max-width:163px;max-height:100px;border-radius:var(--radius-sm);border:1px solid var(--border);display:block" alt="">
                        </div>
                    </div>`,
                confirmLabel: 'Save',
                onConfirm: async () => {
                    const url   = document.getElementById('edit-dial-url')?.value?.trim();
                    const title = document.getElementById('edit-dial-title')?.value?.trim();
                    const notes = document.getElementById('edit-dial-notes')?.value?.trim();
                    const file  = document.getElementById('edit-dial-thumb')?.files?.[0];
                    if (!url) { toast.error('URL cannot be empty.'); return false; }
                    const r = await api.put(`/api/dials/${dial.id}`, { url, title, notes: notes || '' });
                    if (!r.ok) { toast.error(r.error || 'Could not save.'); return false; }
                    if (file) {
                        const previewImg = document.getElementById('edit-dial-thumb-img');
                        if (previewImg?.src?.startsWith('blob:')) URL.revokeObjectURL(previewImg.src);
                        const fd = new FormData(); fd.append('thumb', file);
                        try {
                            const res  = await fetch(`/api/thumbs/${dial.id}/upload`, { method: 'POST', headers: { 'X-CSRF-Token': api.csrf() }, credentials: 'same-origin', body: fd });
                            const data = await res.json();
                            if (!data.ok) toast.error(data.error || 'Thumbnail upload failed.');
                        } catch { toast.error('Thumbnail upload failed: network error.'); }
                    } else if (url !== dial.url) { api.post(`/api/thumbs/${dial.id}`, {}).catch(() => {}); }
                    toast.success('Dial updated.');
                    delete this._cache[activeGroupId]; delete this._cache['all'];
                    delete this._cache[RECENT_GROUP_ID];
                    await groups_module.reload(); groups_module.render();
                    return true;
                }
            });

            setTimeout(() => {
                const urlInput   = document.getElementById('edit-dial-url');
                const titleInput = document.getElementById('edit-dial-title');
                const notesInput = document.getElementById('edit-dial-notes');
                const statusEl   = document.getElementById('edit-dial-meta-status');
                const notesCount = document.getElementById('edit-dial-notes-count');
                const fetchBtn   = document.getElementById('edit-dial-fetch-btn');

                urlInput?.focus(); urlInput?.select();
                notesInput?.addEventListener('input', function() { if (notesCount) notesCount.textContent = this.value.length; });

                fetchBtn?.addEventListener('click', async () => {
                    const url = urlInput?.value?.trim();
                    if (!url) { toast.error('Enter a URL first.'); return; }
                    fetchBtn.disabled = true; fetchBtn.textContent = '⏳';
                    const savedTitle = titleInput?.value;
                    const savedNotes = notesInput?.value;
                    if (titleInput) titleInput.value = '';
                    if (notesInput) notesInput.value = '';
                    meta_module._lastUrl = null;
                    await meta_module.fetchFor(url, titleInput, notesInput, statusEl);
                    if (titleInput && !titleInput.value) titleInput.value = savedTitle;
                    if (notesInput && !notesInput.value) notesInput.value = savedNotes;
                    if (notesCount && notesInput) notesCount.textContent = notesInput.value.length;
                    fetchBtn.disabled = false; fetchBtn.textContent = '↺ Fetch';
                });

                document.getElementById('edit-dial-thumb')?.addEventListener('change', function() {
                    const f = this.files?.[0];
                    const preview = document.getElementById('edit-dial-thumb-preview');
                    const img = document.getElementById('edit-dial-thumb-img');
                    if (!preview || !img) return;
                    if (f) { if (img.src?.startsWith('blob:')) URL.revokeObjectURL(img.src); img.src = URL.createObjectURL(f); preview.style.display = ''; }
                    else preview.style.display = 'none';
                });
            }, 80);
        },

        async refreshThumb(dial) {
            toast.info('Refreshing thumbnail…');
            const r = await api.post(`/api/thumbs/${dial.id}`, {});
            if (!r.ok) { toast.error(r.error || 'Thumbnail refresh failed.'); return; }
            toast.success('Thumbnail updated.');
            const card = document.querySelector(`[data-dial-id="${dial.id}"]`);
            const img  = card?.querySelector('.dial-thumb-wrap img');
            if (img) { img.src = `/api/thumbs/${dial.id}?t=${Date.now()}`; }
            else {
                const wrap = card?.querySelector('.dial-thumb-wrap');
                if (wrap) { const ni = document.createElement('img'); ni.src = `/api/thumbs/${dial.id}?t=${Date.now()}`; ni.alt = dial.title || ''; ni.loading = 'lazy'; ni.onerror = () => ni.remove(); wrap.appendChild(ni); }
            }
        },

        confirmDelete(dial, onDeleteCallback) {
            modal.show({
                title: 'Delete Dial',
                body: `<p>Delete <strong>"${escHtml(dial.title || dial.url)}"</strong>?</p>
                       <p class="text-muted text-sm" style="margin-top:.5rem">Cannot be undone.</p>`,
                confirmLabel: 'Delete', confirmClass: 'btn-danger',
                onConfirm: async () => {
                    const r = await api.delete(`/api/dials/${dial.id}`);
                    if (!r.ok) { toast.error(r.error || 'Could not delete.'); return false; }
                    toast.success('Dial deleted.');
                    delete this._cache[activeGroupId]; delete this._cache['all'];
                    delete this._cache[RECENT_GROUP_ID];
                    await groups_module.reload(); groups_module.render();
                    if (onDeleteCallback) onDeleteCallback();
                    return true;
                }
            });
        }
    };

    // ── Relative time helper (sesja 062) ──────────────────────────────────────
    function _relativeTime(datetimeStr) {
        if (!datetimeStr) return '';
        const d    = new Date(datetimeStr.replace(' ', 'T'));
        const diff = Math.floor((Date.now() - d.getTime()) / 1000);
        if (diff < 60)   return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    }

    // ── Bulk Select ───────────────────────────────────────────────────────────
    const bulk_module = {
        active: false, selected: new Set(),
        init() {
            document.getElementById('btn-bulk-select')?.addEventListener('click', () => { this.active ? this.exit() : this.enter(); });
            document.getElementById('btn-bulk-select-mobile')?.addEventListener('click', () => { mobile_menu.close(); this.active ? this.exit() : this.enter(); });
            document.addEventListener('keydown', e => { if (e.key === 'Escape' && this.active) this.exit(); });
        },
        enter() {
            if (activeGroupId === RECENT_GROUP_ID) { toast.error('Switch to a regular group to use bulk select.'); return; }
            this.active = true; this.selected.clear(); this._updateBtn(); keyboard_nav.clear();
            const grid = document.getElementById('dial-grid');
            if (grid) { grid.classList.add('bulk-select-active'); dials_module.render(activeGroupId, grid); }
            this._renderToolbar();
        },
        exit() {
            this.active = false; this.selected.clear(); this._updateBtn(); this._removeToolbar();
            const grid = document.getElementById('dial-grid');
            if (grid) { grid.classList.remove('bulk-select-active'); dials_module.render(activeGroupId, grid); }
        },
        reset() {
            if (!this.active) return;
            this.active = false; this.selected.clear(); this._updateBtn(); this._removeToolbar();
            document.getElementById('dial-grid')?.classList.remove('bulk-select-active');
        },
        toggle(dialId) {
            if (this.selected.has(dialId)) this.selected.delete(dialId); else this.selected.add(dialId);
            const card = document.querySelector(`[data-dial-id="${dialId}"]`);
            if (card) card.classList.toggle('selected', this.selected.has(dialId));
            this._renderToolbar();
        },
        selectAll() {
            const dials = dials_module._cache[activeGroupId] || [];
            dials.forEach(d => this.selected.add(d.id));
            document.querySelectorAll('.dial-card').forEach(c => { const id = parseInt(c.dataset.dialId); if (id) c.classList.add('selected'); });
            this._renderToolbar();
        },
        _updateBtn() {
            const label = this.active ? '✕ Exit select' : '☑ Select';
            const btnD = document.getElementById('btn-bulk-select'); if (btnD) btnD.textContent = label;
            const btnM = document.getElementById('btn-bulk-select-mobile'); if (btnM) btnM.textContent = label;
        },
        _renderToolbar() {
            this._removeToolbar();
            const count = this.selected.size;
            const bar = document.createElement('div'); bar.id = 'bulk-toolbar'; bar.className = 'bulk-toolbar';
            const countEl = document.createElement('span'); countEl.className = 'bulk-count'; countEl.textContent = count === 0 ? 'None selected' : `${count} selected`; bar.appendChild(countEl);
            if (count > 0) {
                const btnAll = document.createElement('button'); btnAll.type = 'button'; btnAll.className = 'btn btn-ghost btn-sm'; btnAll.textContent = 'All'; btnAll.addEventListener('click', () => this.selectAll()); bar.appendChild(btnAll);
                const btnMove = document.createElement('button'); btnMove.type = 'button'; btnMove.className = 'btn btn-ghost btn-sm'; btnMove.innerHTML = '📂 Move to…'; btnMove.addEventListener('click', () => this._showGroupPicker('move')); bar.appendChild(btnMove);
                const btnDup = document.createElement('button'); btnDup.type = 'button'; btnDup.className = 'btn btn-ghost btn-sm'; btnDup.innerHTML = '⧉ Duplicate to…'; btnDup.addEventListener('click', () => this._showGroupPicker('duplicate')); bar.appendChild(btnDup);
                const btnRefresh = document.createElement('button'); btnRefresh.type = 'button'; btnRefresh.className = 'btn btn-ghost btn-sm'; btnRefresh.innerHTML = '🔄 Refresh thumbs'; btnRefresh.addEventListener('click', () => this._bulkRefreshThumbs()); bar.appendChild(btnRefresh);
                const btnDel = document.createElement('button'); btnDel.type = 'button'; btnDel.className = 'btn btn-danger btn-sm'; btnDel.innerHTML = '🗑 Delete'; btnDel.addEventListener('click', () => this._confirmBulkDelete()); bar.appendChild(btnDel);
            }
            const btnCancel = document.createElement('button'); btnCancel.type = 'button'; btnCancel.className = 'btn btn-ghost btn-sm'; btnCancel.textContent = '✕ Cancel'; btnCancel.addEventListener('click', () => this.exit()); bar.appendChild(btnCancel);
            document.body.appendChild(bar);
        },
        _removeToolbar() { document.getElementById('bulk-toolbar')?.remove(); },
        _showGroupPicker(action) {
            const count = this.selected.size;
            const verb  = action === 'move' ? 'Move' : 'Duplicate';
            const groupOptions = groups.map(g => {
                const iconHtml = g.icon_path
                    ? `<img src="/api/group_icons/${g.id}" alt="" style="width:16px;height:16px;border-radius:2px;object-fit:cover;vertical-align:middle;flex-shrink:0">`
                    : (g.icon ? `<span>${escHtml(g.icon)}</span>` : '');
                return `<label style="display:flex;align-items:center;gap:.6rem;padding:.45rem .5rem;border-radius:var(--radius-sm);cursor:pointer;font-size:var(--text-sm);transition:background var(--transition)" onmouseover="this.style.background='var(--surface-alt)'" onmouseout="this.style.background=''">
                    <input type="radio" name="bulk-group" value="${g.id}" style="accent-color:var(--primary);flex-shrink:0">
                    ${iconHtml}<span style="flex:1">${escHtml(g.name)}</span>
                    <span style="color:var(--text-faint);font-size:var(--text-xs)">${g.dial_count||0} dials</span>
                </label>`;
            }).join('');
            modal.show({
                title: action === 'move' ? 'Move to group' : 'Duplicate to group',
                body: `<p style="font-size:.875rem;color:var(--text-muted);margin-bottom:.75rem">${verb} <strong>${count} dial${count!==1?'s':''}</strong> to:</p>
                       <div style="display:flex;flex-direction:column;gap:.1rem;max-height:260px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius-md);padding:.4rem">${groupOptions}</div>`,
                confirmLabel: verb,
                onConfirm: async () => {
                    const sel = document.querySelector('input[name="bulk-group"]:checked');
                    if (!sel) { toast.error('Please select a group.'); return false; }
                    const targetGroupId = parseInt(sel.value);
                    const ids = [...this.selected];
                    if (action === 'move') {
                        const r = await api.post('/api/dials/bulk-move', { ids, group_id: targetGroupId });
                        if (!r.ok) { toast.error(r.error || 'Could not move dials.'); return false; }
                        toast.success(`Moved ${r.moved} dial${r.moved!==1?'s':''}.`);
                    } else {
                        const r = await api.post('/api/dials/bulk-duplicate', { ids, group_id: targetGroupId });
                        if (!r.ok) { toast.error(r.error || 'Could not duplicate dials.'); return false; }
                        (r.ids||[]).forEach(id => api.post(`/api/thumbs/${id}`, {}).catch(()=>{}));
                        toast.success(`Duplicated ${r.duplicated} dial${r.duplicated!==1?'s':''}.`);
                    }
                    this.exit();
                    Object.keys(dials_module._cache).forEach(k => delete dials_module._cache[k]);
                    await groups_module.reload(); groups_module.render();
                    return true;
                }
            });
        },
        /**
         * Bulk refresh thumbnails (sesja 068).
         * Fires POST /api/thumbs/{id} for each selected dial sequentially.
         * Shows a progress toast and updates card images inline without a full reload.
         * Rate limited server-side (60 refreshes/hour/user) — we cap at 60 client-side
         * to avoid hammering the limit; more than that is impractical anyway.
         */
        async _bulkRefreshThumbs() {
            const ids   = [...this.selected];
            const count = ids.length;
            if (!count) return;

            const CAP = 60;
            const toRefresh = ids.slice(0, CAP);
            const skipped   = ids.length - toRefresh.length;

            toast.info(`Refreshing ${toRefresh.length} thumbnail${toRefresh.length !== 1 ? 's' : ''}…`);

            let ok = 0, fail = 0;
            for (const dialId of toRefresh) {
                try {
                    const r = await api.post(`/api/thumbs/${dialId}`, {});
                    if (r.ok) {
                        ok++;
                        // Update the card image inline
                        const card = document.querySelector(`[data-dial-id="${dialId}"]`);
                        const wrap = card?.querySelector('.dial-thumb-wrap');
                        if (wrap) {
                            let img = wrap.querySelector('img');
                            if (!img) {
                                img = document.createElement('img');
                                img.alt     = '';
                                img.loading = 'lazy';
                                img.onerror = () => img.remove();
                                wrap.appendChild(img);
                            }
                            img.src = `/api/thumbs/${dialId}?t=${Date.now()}`;
                        }
                    } else {
                        fail++;
                    }
                } catch {
                    fail++;
                }
            }

            let msg = `Refreshed ${ok} thumbnail${ok !== 1 ? 's' : ''}.`;
            if (fail > 0) msg += ` ${fail} failed.`;
            if (skipped > 0) msg += ` ${skipped} skipped (rate limit cap).`;

            if (fail > 0) toast.error(msg);
            else          toast.success(msg);
        },

        _confirmBulkDelete() {
            const count = this.selected.size;
            modal.show({
                title: 'Delete selected dials',
                body: `<p>Delete <strong>${count} dial${count!==1?'s':''}</strong>?</p><p class="text-muted text-sm" style="margin-top:.5rem">Cannot be undone.</p>`,
                confirmLabel: `Delete ${count}`, confirmClass: 'btn-danger',
                onConfirm: async () => {
                    const ids = [...this.selected];
                    const r   = await api.post('/api/dials/bulk-delete', { ids });
                    if (!r.ok) { toast.error(r.error || 'Could not delete dials.'); return false; }
                    toast.success(`Deleted ${r.deleted} dial${r.deleted!==1?'s':''}.`);
                    this.exit();
                    Object.keys(dials_module._cache).forEach(k => delete dials_module._cache[k]);
                    await groups_module.reload(); groups_module.render();
                    return true;
                }
            });
        },
    };

    // ── Modal ─────────────────────────────────────────────────────────────────
    const modal = {
        el: null,
        show({ title, body, confirmLabel = 'Confirm', confirmClass = 'btn-primary', cancelLabel = 'Cancel', onConfirm }) {
            this.close();
            const el = document.createElement('div');
            el.className = 'modal-backdrop';
            el.innerHTML = `<div class="modal" role="dialog" aria-modal="true">
                <div class="modal-header"><h3>${escHtml(title)}</h3>
                    <button class="modal-close" type="button" aria-label="Close">×</button></div>
                <div class="modal-body">${body}</div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" id="modal-cancel" type="button">${escHtml(cancelLabel)}</button>
                    <button class="btn ${confirmClass}" id="modal-confirm" type="button">${escHtml(confirmLabel)}</button>
                </div></div>`;
            el.querySelector('.modal-close').addEventListener('click', () => this.close());
            el.querySelector('#modal-cancel').addEventListener('click', () => this.close());
            el.querySelector('#modal-confirm').addEventListener('click', async () => {
                const btn = el.querySelector('#modal-confirm');
                btn.disabled = true; btn.textContent = '…';
                const close = await onConfirm();
                if (close !== false) this.close();
                else { btn.disabled = false; btn.textContent = confirmLabel; }
            });
            el.querySelectorAll('input[type="text"], input[type="url"]').forEach(i =>
                i.addEventListener('keydown', e => { if (e.key === 'Enter') el.querySelector('#modal-confirm')?.click(); })
            );
            el.addEventListener('click', e => { if (e.target === el) this.close(); });
            const onKey = e => { if (e.key === 'Escape') this.close(); };
            document.addEventListener('keydown', onKey);
            el._onKey = onKey;
            document.body.appendChild(el);
            this.el = el;
        },
        close() {
            if (!this.el) return;
            if (this.el._onKey) document.removeEventListener('keydown', this.el._onKey);
            this.el.remove(); this.el = null;
        }
    };

    // ── Toast ─────────────────────────────────────────────────────────────────
    const toast = {
        _c() { let c = document.querySelector('.toast-container'); if (!c) { c = document.createElement('div'); c.className = 'toast-container'; document.body.appendChild(c); } return c; },
        show(msg, type = 'info', dur = 3500) {
            const icons = { success: '✓', error: '✗', info: 'ℹ' };
            const el = document.createElement('div'); el.className = `toast toast-${type}`;
            el.innerHTML = `<span class="toast-icon">${icons[type]||'ℹ'}</span><span>${escHtml(msg)}</span>`;
            this._c().appendChild(el);
            setTimeout(() => el.style.opacity = '0', dur - 300);
            setTimeout(() => el.remove(), dur);
        },
        success: (m) => toast.show(m, 'success'),
        error:   (m) => toast.show(m, 'error', 5000),
        info:    (m) => toast.show(m, 'info'),
    };

    // ── Context Menu ──────────────────────────────────────────────────────────
    const contextMenu = {
        el: null, subEl: null, subTimer: null,
        init() {
            document.addEventListener('click',   () => this.close());
            document.addEventListener('keydown', e => { if (e.key === 'Escape') this.close(); });
        },
        show(e, items) {
            this.close();
            const menu = document.createElement('div'); menu.className = 'context-menu';
            items.forEach(item => {
                if (item.separator) { const s = document.createElement('div'); s.className = 'context-menu-sep'; menu.appendChild(s); return; }
                const btn = document.createElement('button'); btn.type = 'button';
                btn.className = 'context-menu-item' + (item.danger?' danger':'') + (item.disabled?' disabled':'') + (item.submenu?' has-submenu':'');
                if (item.disabled) { btn.disabled = true; btn.style.opacity = '.4'; }
                btn.innerHTML = `<span class="context-menu-item-icon">${item.icon||''}</span>${escHtml(item.label)}`;
                if (item.submenu) { btn.addEventListener('mouseenter', (ev) => this._showSub(ev, btn, item.submenu, menu)); btn.addEventListener('click', ev => ev.stopPropagation()); }
                else { btn.addEventListener('mouseenter', () => this._hideSub()); btn.addEventListener('click', ev => { ev.stopPropagation(); this.close(); if (!item.disabled) item.action?.(); }); }
                menu.appendChild(btn);
            });
            menu.addEventListener('mouseleave', () => { this.subTimer = setTimeout(() => this._hideSub(), 150); });
            menu.addEventListener('mouseenter', () => { clearTimeout(this.subTimer); });
            document.body.appendChild(menu); this._position(menu, e.clientX, e.clientY); this.el = menu;
        },
        _showSub(triggerEv, btn, subItems) {
            clearTimeout(this.subTimer); this._hideSub();
            const sub = document.createElement('div'); sub.className = 'context-menu context-submenu';
            subItems.forEach(item => {
                const b = document.createElement('button'); b.type = 'button';
                b.className = 'context-menu-item' + (item.disabled?' disabled':'');
                if (item.disabled) { b.disabled = true; b.style.opacity = '.4'; }
                b.innerHTML = `<span class="context-menu-item-icon">${item.icon||''}</span>${escHtml(item.label)}`;
                b.addEventListener('click', ev => { ev.stopPropagation(); this.close(); if (!item.disabled) item.action?.(); });
                sub.appendChild(b);
            });
            sub.addEventListener('mouseenter', () => clearTimeout(this.subTimer));
            sub.addEventListener('mouseleave', () => { this.subTimer = setTimeout(() => this._hideSub(), 150); });
            document.body.appendChild(sub);
            sub.style.visibility = 'hidden'; sub.style.display = 'block';
            const subRect = sub.getBoundingClientRect(); sub.style.visibility = '';
            const btnRect = btn.getBoundingClientRect(); const winW = window.innerWidth; const winH = window.innerHeight;
            let x = btnRect.right + 4; if (x + subRect.width > winW - 8) x = btnRect.left - subRect.width - 4; x = Math.max(8, x);
            let y = btnRect.top; if (y + subRect.height > winH - 8) y = winH - subRect.height - 8; y = Math.max(8, y);
            sub.style.left = x + 'px'; sub.style.top = y + 'px'; this.subEl = sub;
        },
        _hideSub() { this.subEl?.remove(); this.subEl = null; },
        _position(menu, cx, cy) {
            const r = menu.getBoundingClientRect(); let x = cx, y = cy;
            if (x + r.width > window.innerWidth - 8)   x = window.innerWidth  - r.width  - 8;
            if (y + r.height > window.innerHeight - 8) y = window.innerHeight - r.height - 8;
            menu.style.left = Math.max(8, x) + 'px'; menu.style.top = Math.max(8, y) + 'px';
        },
        close() { this._hideSub(); clearTimeout(this.subTimer); this.el?.remove(); this.el = null; }
    };

    // ── Import / Export ───────────────────────────────────────────────────────
    const import_export_module = {
        init() {
            document.getElementById('btn-export')?.addEventListener('click', () => this.doExport());
            document.getElementById('btn-import')?.addEventListener('click', () => this.showImport());
            document.getElementById('btn-export-mobile')?.addEventListener('click', () => { mobile_menu.close(); this.doExport(); });
            document.getElementById('btn-import-mobile')?.addEventListener('click', () => { mobile_menu.close(); this.showImport(); });
        },
        doExport() {
            const a = document.createElement('a'); a.href = '/api/export';
            a.download = 'letadial_export_' + new Date().toISOString().slice(0,10) + '.json';
            document.body.appendChild(a); a.click(); document.body.removeChild(a);
        },
        showImport() {
            modal.show({
                title: 'Import dials',
                body: `<p style="font-size:.875rem;color:var(--text-muted);margin-bottom:1rem">Import from a JSON file.<br>Supports <strong>LetaDial JSON</strong> and other speed dial formats.<br>Existing dials are kept. Duplicate URLs per group are skipped.</p>
                    <div class="form-group"><label class="form-label">JSON file</label>
                    <input type="file" id="import-file" accept=".json,application/json" class="form-input" style="cursor:pointer"></div>
                    <div id="import-status" style="display:none;margin-top:.75rem;font-size:.875rem;padding:.6rem;border-radius:var(--radius-sm)"></div>`,
                confirmLabel: 'Import',
                onConfirm: async () => {
                    const file = document.getElementById('import-file')?.files?.[0];
                    if (!file) { toast.error('Please select a JSON file.'); return false; }
                    const status = document.getElementById('import-status');
                    status.style.display = 'block'; status.style.background = 'var(--surface-alt)'; status.textContent = 'Importing…';
                    try {
                        const text = await file.text();
                        const res  = await fetch('/api/import', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': api.csrf() }, body: text });
                        const data = await res.json();
                        if (!data.ok) { status.style.background = 'var(--error-bg)'; status.style.color = 'var(--error)'; status.textContent = '❌ ' + (data.error || 'Import failed.'); return false; }
                        const msg = `✅ Imported from ${data.format}: ${data.groups} group(s), ${data.dials} dial(s)` + (data.skipped > 0 ? `, ${data.skipped} skipped` : '') + '.';
                        status.style.background = 'var(--surface-alt)'; status.style.color = 'var(--success)'; status.textContent = msg;
                        toast.success(msg); await groups_module.reload(); groups_module.render();
                        return true;
                    } catch (err) { status.style.background = 'var(--error-bg)'; status.style.color = 'var(--error)'; status.textContent = '❌ Network error: ' + err.message; return false; }
                },
            });
        },
    };

    // ── Utilities ─────────────────────────────────────────────────────────────
    function escHtml(str) {
        return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

    // ── Mobile Menu ───────────────────────────────────────────────────────────
    const mobile_menu = {
        init() {
            const btn = document.getElementById('btn-hamburger'); const menu = document.getElementById('mobile-menu');
            if (!btn || !menu) return;
            btn.addEventListener('click', () => { const open = menu.classList.toggle('open'); btn.setAttribute('aria-expanded', String(open)); menu.setAttribute('aria-hidden', String(!open)); document.body.style.overflow = open ? 'hidden' : ''; });
            menu.addEventListener('click', e => { if (e.target === menu) this.close(); });
            document.querySelector('.groups-bar')?.addEventListener('click', () => this.close());
        },
        close() {
            const btn = document.getElementById('btn-hamburger'); const menu = document.getElementById('mobile-menu');
            if (!menu) return;
            menu.classList.remove('open'); btn?.setAttribute('aria-expanded', 'false'); menu.setAttribute('aria-hidden', 'true'); document.body.style.overflow = '';
        },
    };

    // ── Dial Width (sesja 074) ────────────────────────────────────────────────────
    function _applyDialWidth(w) {
        w = Math.max(120, Math.min(280, parseInt(w) || 175));
        document.documentElement.style.setProperty('--dial-w', w + 'px');
    }

    return { init, theme, toast, modal, api, escHtml, groups_module, dials_module, search_module, import_export_module, mobile_menu, bulk_module, keyboard_nav, sort_module, meta_module, _applyDialWidth };

})();

document.addEventListener('DOMContentLoaded', () => LetaDial.init());
