/* Admin JS — EasyMDE init, slug generation, media insert, sidebar toggle */

'use strict';

// ── Slug helpers ──────────────────────────────────────────────────────────────

function slugify(text) {
    return text
        .toLowerCase()
        .replace(/['"]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '') || 'untitled';
}

(function initSlugField() {
    const titleInput = document.querySelector('[data-slug-source]');
    const slugInput  = document.getElementById('slug');
    if (!titleInput || !slugInput) return;

    // Only auto-update if the slug hasn't been manually edited yet.
    let userEditedSlug = slugInput.value !== '';

    slugInput.addEventListener('input', () => { userEditedSlug = true; });

    titleInput.addEventListener('input', () => {
        if (!userEditedSlug) {
            slugInput.value = slugify(titleInput.value);
        }
    });
})();

// ── Form action helper ────────────────────────────────────────────────────────

function setAction(action) {
    const el = document.getElementById('form-action');
    if (el) el.value = action;
    return true;
}

// ── EasyMDE ───────────────────────────────────────────────────────────────────

(function initEditor() {
    const textarea = document.getElementById('content');
    if (!textarea || typeof EasyMDE === 'undefined') return;

    window._editor = new EasyMDE({
        element: textarea,
        autoDownloadFontAwesome: false,
        spellChecker: false,
        autofocus: false,
        autosave: { enabled: false },
        onChange: function () {
            const btn = document.getElementById('update-btn');
            if (btn) btn.disabled = false;
        },
        toolbar: [
            'bold', 'italic', 'heading', '|',
            'quote', 'unordered-list', 'ordered-list', '|',
            'link', 'image', 'table', '|',
            'preview', 'side-by-side', 'fullscreen', '|',
            'guide'
        ],
        minHeight: '360px',
        renderingConfig: { singleLineBreaks: false },
    });
})();

// ── Update-button dirty tracking ─────────────────────────────────────────────
// Enables #update-btn (present only on published posts/pages) once any
// change has been made, so it stays disabled until there is something to save.

(function initDirtyTracking() {
    const btn = document.getElementById('update-btn');
    if (!btn) return;

    function enable() { btn.disabled = false; }

    const form = btn.closest('form');
    if (form) {
        form.addEventListener('input',  enable);
        form.addEventListener('change', enable);
    }
})();

// ── Media insert helper ───────────────────────────────────────────────────────

(function initMediaInsert() {
    const grid = document.getElementById('media-insert-grid');
    if (!grid) return;

    grid.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-url]');
        if (!btn) return;

        const url   = btn.dataset.url;
        const type  = btn.dataset.type;   // image | video | audio
        const name  = btn.dataset.name || '';

        let markdown;
        if (type === 'image') {
            markdown = `![${name}](${url})`;
        } else if (type === 'video') {
            markdown = `<video src="${url}" controls></video>`;
        } else {
            markdown = `<audio src="${url}" controls></audio>`;
        }

        const editor = window._editor;
        if (editor) {
            const cm = editor.codemirror;
            const pos = cm.getCursor();
            cm.replaceRange('\n' + markdown + '\n', pos);
            cm.focus();
        } else {
            // Fallback: append to plain textarea.
            const ta = document.getElementById('content');
            if (ta) {
                const start = ta.selectionStart;
                ta.value = ta.value.slice(0, start) + '\n' + markdown + '\n' + ta.value.slice(start);
            }
        }
    });
})();

// ── Tag pill widget ───────────────────────────────────────────────────────────
// Progressively enhances the tags_csv text input into a pill-based tag editor.

(function initTagPills() {
    const csvInput = document.querySelector('input[name="tags_csv"]');
    if (!csvInput) return;

    // Build the widget container.
    const widget = document.createElement('div');
    widget.className = 'tag-pill-widget';

    // Visible text input inside the widget.
    const textInput = document.createElement('input');
    textInput.type = 'text';
    textInput.className = 'tag-pill-widget__input';
    textInput.placeholder = csvInput.placeholder || 'Add a tag…';
    widget.appendChild(textInput);

    // Hide the original input but keep it in the form.
    csvInput.type = 'hidden';
    csvInput.insertAdjacentElement('afterend', widget);

    let tags = csvInput.value
        ? csvInput.value.split(',').map(t => t.trim()).filter(Boolean)
        : [];

    function sync() {
        csvInput.value = tags.join(', ');
    }

    function renderPill(tag) {
        const pill = document.createElement('span');
        pill.className = 'tag-pill';
        pill.textContent = tag;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'tag-pill__remove';
        btn.setAttribute('aria-label', 'Remove ' + tag);
        btn.textContent = '×';
        btn.addEventListener('click', () => {
            tags = tags.filter(t => t !== tag);
            pill.remove();
            sync();
        });

        pill.appendChild(btn);
        widget.insertBefore(pill, textInput);
    }

    function addTag(raw) {
        const name = raw.trim();
        if (!name || tags.includes(name)) return;
        tags.push(name);
        renderPill(name);
        sync();
    }

    // Render initial tags.
    tags.forEach(renderPill);
    tags = [...tags]; // keep reference clean after render

    // Re-sync tags array from pills (avoids duplication on initial render).
    tags = csvInput.value
        ? csvInput.value.split(',').map(t => t.trim()).filter(Boolean)
        : [];

    textInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ',') {
            e.preventDefault();
            addTag(textInput.value);
            textInput.value = '';
        } else if (e.key === 'Backspace' && textInput.value === '' && tags.length) {
            // Remove last pill on backspace when input is empty.
            const last = tags[tags.length - 1];
            tags.pop();
            widget.querySelector(`.tag-pill:last-of-type`)?.remove();
            sync();
        }
    });

    textInput.addEventListener('blur', () => {
        if (textInput.value.trim()) {
            addTag(textInput.value);
            textInput.value = '';
        }
    });

    // Clicking anywhere in the widget focuses the text input.
    widget.addEventListener('click', () => textInput.focus());
})();

// ── Sidebar toggle + tooltip ──────────────────────────────────────────────────

(function initSidebarToggle() {
    const STORAGE_KEY = 'cms_nav_collapsed';
    const MOBILE_BP   = 700; // must match the CSS breakpoint
    const btn = document.getElementById('nav-toggle');
    const nav = document.getElementById('admin-nav');
    if (!btn) return;

    // ── Backdrop (mobile only) ───────────────────────────────────────────────
    const backdrop = document.createElement('div');
    backdrop.className = 'mobile-nav-backdrop';
    document.body.appendChild(backdrop);

    // ── Helpers ──────────────────────────────────────────────────────────────
    function isMobile() {
        return window.innerWidth <= MOBILE_BP;
    }

    function setCollapsed(collapsed) {
        document.body.classList.toggle('nav-collapsed', collapsed);
        try { localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0'); } catch (e) {}
    }

    function closeMobileNav() {
        document.body.classList.remove('mobile-nav-open');
    }

    // ── Toggle button ────────────────────────────────────────────────────────
    btn.addEventListener('click', () => {
        if (isMobile()) {
            document.body.classList.toggle('mobile-nav-open');
        } else {
            setCollapsed(!document.body.classList.contains('nav-collapsed'));
            hideTip();
        }
    });

    // ── Close mobile drawer on backdrop tap ──────────────────────────────────
    backdrop.addEventListener('click', closeMobileNav);

    // ── Close mobile drawer when a nav link is tapped ────────────────────────
    if (nav) {
        nav.addEventListener('click', (e) => {
            if (!isMobile()) return;
            if (e.target.closest('a[href], button[type="submit"]')) {
                // Small delay lets the browser start the navigation first.
                setTimeout(closeMobileNav, 80);
            }
        });
    }

    // ── Clean up mobile state when resizing to desktop ───────────────────────
    window.addEventListener('resize', () => {
        if (!isMobile()) {
            closeMobileNav();
        }
    }, { passive: true });

    // ── Hover tooltips (desktop collapsed mode only) ─────────────────────────
    let tip = null;

    function getTip() {
        if (!tip) {
            tip = document.createElement('div');
            tip.className = 'nav-tooltip';
            document.body.appendChild(tip);
        }
        return tip;
    }

    function showTip(el) {
        if (!document.body.classList.contains('nav-collapsed')) return;
        if (isMobile()) return;
        const label = el.dataset.label;
        if (!label) return;
        const rect = el.getBoundingClientRect();
        const t = getTip();
        t.textContent = label;
        t.style.top  = (rect.top + rect.height / 2) + 'px';
        t.style.left = (rect.right + 10) + 'px';
        t.classList.add('visible');
    }

    function hideTip() {
        if (tip) tip.classList.remove('visible');
    }

    if (nav) {
        nav.addEventListener('mouseover', (e) => {
            const el = e.target.closest('[data-label]');
            if (el) showTip(el);
        });
        nav.addEventListener('mouseout', (e) => {
            const el = e.target.closest('[data-label]');
            if (el) hideTip();
        });
    }
})();
