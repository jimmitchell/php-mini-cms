/* Media library — drag-and-drop upload, delete, copy URL */

'use strict';

(function () {

    const dropTarget    = document.getElementById('drop-target');
    const fileInput     = document.getElementById('file-input');
    const uploadForm    = document.getElementById('upload-form');
    const progressList  = document.getElementById('upload-progress-list');
    const libraryGrid   = document.getElementById('media-library-grid');

    if (!dropTarget || !fileInput || !uploadForm) return;

    // ── CSRF token (read from the hidden input in the upload form) ────────────
    const csrfToken = () => uploadForm.querySelector('[name="csrf_token"]')?.value ?? '';

    // ── Drag and drop ─────────────────────────────────────────────────────────

    ['dragenter', 'dragover'].forEach(evt =>
        dropTarget.addEventListener(evt, e => {
            e.preventDefault();
            e.stopPropagation();
            dropTarget.classList.add('is-dragging');
        })
    );

    ['dragleave', 'drop'].forEach(evt =>
        dropTarget.addEventListener(evt, e => {
            e.preventDefault();
            e.stopPropagation();
            dropTarget.classList.remove('is-dragging');
        })
    );

    dropTarget.addEventListener('drop', e => {
        const files = e.dataTransfer?.files;
        if (files && files.length) uploadFiles(files);
    });

    // ── File input (browse button) ────────────────────────────────────────────

    fileInput.addEventListener('change', () => {
        if (fileInput.files.length) uploadFiles(fileInput.files);
        fileInput.value = ''; // reset so the same file can be re-selected
    });

    // ── Upload handler ────────────────────────────────────────────────────────

    async function uploadFiles(fileList) {
        const files = Array.from(fileList);

        for (const file of files) {
            const item = addProgressItem(file.name);

            const fd = new FormData();
            fd.append('csrf_token', csrfToken());
            fd.append('action', 'upload');
            fd.append('files[]', file);

            try {
                const res  = await fetch('/admin/media.php', {
                    method:  'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body:    fd,
                });

                const data = await res.json();

                if (data.uploaded && data.uploaded.length) {
                    item.setDone();
                    data.uploaded.forEach(u => prependCard(u, file));
                } else {
                    const msg = data.errors?.[0] ?? 'Upload failed.';
                    item.setError(msg);
                }
            } catch (err) {
                item.setError('Network error: ' + err.message);
            }
        }
    }

    // ── Progress list item ────────────────────────────────────────────────────

    function addProgressItem(name) {
        const li = document.createElement('li');
        li.className = 'upload-progress-item upload-progress-item--pending';
        li.textContent = name;
        progressList.prepend(li);

        return {
            setDone()        { li.className = 'upload-progress-item upload-progress-item--done'; },
            setError(msg)    {
                li.className   = 'upload-progress-item upload-progress-item--error';
                li.textContent = name + ' — ' + msg;
            },
        };
    }

    // ── Prepend a new card to the grid ────────────────────────────────────────

    function prependCard(uploaded, file) {
        // Show the grid if it was hidden (empty state).
        if (!libraryGrid) {
            location.reload();
            return;
        }

        const isImage = file.type.startsWith('image/');
        const isVideo = file.type.startsWith('video/');
        const ext     = uploaded.filename.split('.').pop().toUpperCase();
        const size    = formatBytes(file.size);
        const url     = uploaded.url;

        const card = document.createElement('div');
        card.className = 'media-card';
        card.dataset.id = uploaded.id;

        card.innerHTML = `
            <div class="media-card__thumb">
                ${isImage
                    ? `<img src="${escHtml(url)}" alt="${escHtml(file.name)}" loading="lazy">`
                    : `<span class="media-card__icon">${isVideo ? '▶' : '♪'}</span>`
                }
            </div>
            <div class="media-card__info">
                <span class="media-card__name" title="${escHtml(file.name)}">${escHtml(file.name)}</span>
                <span class="media-card__meta">${escHtml(size)} · ${escHtml(ext)}</span>
            </div>
            <div class="media-card__actions">
                <button type="button" class="btn btn--sm btn--secondary js-copy-url"
                        data-url="${escHtml(url)}" title="Copy URL">Copy URL</button>
                <button type="button" class="btn btn--sm btn--danger js-delete"
                        data-id="${uploaded.id}"
                        data-name="${escHtml(file.name)}"
                        data-csrf="${escHtml(csrfToken())}"
                        title="Delete">Delete</button>
            </div>`;

        libraryGrid.prepend(card);
    }

    // ── Copy URL ──────────────────────────────────────────────────────────────

    document.addEventListener('click', async e => {
        const btn = e.target.closest('.js-copy-url');
        if (!btn) return;

        const url = btn.dataset.url;
        try {
            await navigator.clipboard.writeText(url);
            const orig = btn.textContent;
            btn.textContent = 'Copied!';
            setTimeout(() => { btn.textContent = orig; }, 1500);
        } catch {
            // Fallback: select a temporary input.
            const inp = document.createElement('input');
            inp.value = url;
            document.body.appendChild(inp);
            inp.select();
            document.execCommand('copy');
            inp.remove();
            btn.textContent = 'Copied!';
            setTimeout(() => { btn.textContent = 'Copy URL'; }, 1500);
        }
    });

    // ── Delete ────────────────────────────────────────────────────────────────

    document.addEventListener('click', async e => {
        const btn = e.target.closest('.js-delete');
        if (!btn) return;

        const name = btn.dataset.name ?? 'this file';
        if (!confirm(`Delete "${name}"? This cannot be undone.`)) return;

        const id   = btn.dataset.id;
        const csrf = btn.dataset.csrf;

        const fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('action', 'delete');
        fd.append('id', id);

        try {
            const res  = await fetch('/admin/media.php', {
                method:  'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body:    fd,
            });
            const data = await res.json();

            if (data.ok) {
                const card = document.querySelector(`.media-card[data-id="${id}"]`);
                if (card) {
                    card.style.transition = 'opacity .2s';
                    card.style.opacity    = '0';
                    setTimeout(() => card.remove(), 200);
                }
            } else {
                alert('Delete failed.');
            }
        } catch (err) {
            alert('Network error: ' + err.message);
        }
    });

    // ── Utilities ─────────────────────────────────────────────────────────────

    function formatBytes(b) {
        if (b >= 1_048_576) return (b / 1_048_576).toFixed(1) + ' MB';
        if (b >= 1024)      return (b / 1024).toFixed(1) + ' KB';
        return b + ' B';
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

})();
