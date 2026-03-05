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

// ── Sidebar toggle + tooltip ──────────────────────────────────────────────────

(function initSidebarToggle() {
    const STORAGE_KEY = 'cms_nav_collapsed';
    const btn = document.getElementById('nav-toggle');
    if (!btn) return;

    function setCollapsed(collapsed) {
        document.body.classList.toggle('nav-collapsed', collapsed);
        try { localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0'); } catch (e) {}
    }

    btn.addEventListener('click', () => {
        setCollapsed(!document.body.classList.contains('nav-collapsed'));
        hideTip(); // hide any open tooltip on toggle
    });

    // ── Hover tooltips (bypass overflow: hidden via fixed position) ──────────

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

    const nav = document.getElementById('admin-nav');
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
