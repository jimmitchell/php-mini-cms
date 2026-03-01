<?php
/**
 * Base layout template.
 *
 * Expected variables (set by each content template):
 *   $pageTitle   — full <title> string (already escaped if needed; we re-escape here)
 *   $description — meta description plain text
 *   $canonical   — absolute canonical URL
 *   $bodyContent — pre-rendered inner HTML (safe)
 *   $ogType      — Open Graph type (default: 'website')
 *
 * Available from Builder context:
 *   $settings    — site settings array
 *   $navPages    — published Page objects sorted by nav_order
 *   $siteUrl     — site URL without trailing slash
 */

$ogType      = $ogType      ?? 'website';
$description = $description ?? ($settings['site_description'] ?? '');
$siteTitle   = $settings['site_title']       ?? 'My CMS';
$footerText  = $settings['footer_text']      ?? '';
$ogImageUrl  = $ogImageUrl  ?? '';

// Mastodon: parse @user@instance handle into a profile URL + meta value.
$mastodonUrl  = '';
$mastodonMeta = '';
$rawHandle    = $settings['mastodon_handle'] ?? '';
if ($rawHandle !== '') {
    $stripped = ltrim($rawHandle, '@');
    if (substr_count($stripped, '@') === 1) {
        [$_mUser, $_mInstance] = explode('@', $stripped, 2);
        if ($_mUser !== '' && $_mInstance !== '') {
            $mastodonUrl  = 'https://' . $_mInstance . '/@' . $_mUser;
            $mastodonMeta = '@' . $stripped;
        }
    }
}

if (!function_exists('_e')) {
    function _e(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= _e($pageTitle) ?></title>
    <?php if ($description !== ''): ?>
    <meta name="description" content="<?= _e($description) ?>">
    <?php endif; ?>
    <!-- Open Graph -->
    <meta property="og:title"       content="<?= _e($pageTitle) ?>">
    <meta property="og:type"        content="<?= _e($ogType) ?>">
    <meta property="og:url"         content="<?= _e($canonical ?? $siteUrl . '/') ?>">
    <?php if ($ogImageUrl !== ''): ?>
    <meta property="og:image"       content="<?= _e($ogImageUrl) ?>">
    <?php endif; ?>
    <?php if ($description !== ''): ?>
    <meta property="og:description" content="<?= _e($description) ?>">
    <?php endif; ?>
    <?php if ($mastodonMeta !== ''): ?>
    <meta name="fediverse:creator" content="<?= _e($mastodonMeta) ?>">
    <?php endif; ?>
    <!-- Font preloads — must come before the stylesheet -->
    <link rel="preload" href="/fonts/Inter-Regular.woff2"
          as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/Inter-Bold.woff2"
          as="font" type="font/woff2" crossorigin>
    <!-- Atom feed -->
    <link rel="alternate" type="application/atom+xml"
          title="<?= _e($siteTitle) ?>"
          href="<?= _e($siteUrl . '/feed.xml') ?>">
    <!-- Anti-FOUC: apply saved/system theme before CSS renders to avoid flash -->
    <script>(function(){var t=localStorage.getItem('theme');if(t==='dark'||(t===null&&window.matchMedia('(prefers-color-scheme:dark)').matches)){document.documentElement.setAttribute('data-theme','dark');}else if(t==='light'){document.documentElement.setAttribute('data-theme','light');}})();</script>
    <link rel="stylesheet" href="/theme.css">
</head>
<body>

<header class="site-header">
    <div class="site-header__inner">
        <a href="/" class="site-header__title"><?= _e($siteTitle) ?></a>

        <div class="site-header__right">
            <?php if (!empty($navPages)): ?>
            <nav class="site-nav" aria-label="Site navigation">
                <?php foreach ($navPages as $navPage): ?>
                <a href="<?= _e($siteUrl . '/' . $navPage->slug . '/') ?>">
                    <?= _e($navPage->title) ?>
                </a>
                <?php endforeach; ?>
            </nav>
            <?php endif; ?>
            <a href="<?= _e($siteUrl . '/search/') ?>" class="search-toggle" aria-label="Search">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round"
                     stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
            </a>
            <button class="theme-toggle" id="theme-toggle" aria-label="Toggle dark mode"></button>
        </div>
    </div>
</header>

<main class="site-main<?= !empty($wideLayout) ? ' site-main--wide' : '' ?>">
    <?= $bodyContent ?>
</main>

<footer class="site-footer">
    <div class="site-footer__inner">
        <?php if ($footerText !== ''): ?>
        <span><?= _e($footerText) ?></span>
        <?php else: ?>
        <span>&copy; <?= date('Y') ?> <?= _e($siteTitle) ?></span>
        <?php endif; ?>
        <div class="site-footer__links">
            <?php if ($mastodonUrl !== ''): ?>
            <a href="<?= _e($mastodonUrl) ?>" class="site-footer__mastodon"
               rel="me noopener" target="_blank" aria-label="Mastodon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M23.193 7.879c0-5.206-3.411-6.732-3.411-6.732C18.062.357 15.108.025 12.041 0h-.076c-3.069.025-6.02.357-7.74 1.147 0 0-3.411 1.526-3.411 6.732 0 1.192-.023 2.618.015 4.129.124 5.092.934 10.109 5.641 11.355 2.17.574 4.034.695 5.535.612 2.722-.15 4.25-.972 4.25-.972l-.09-1.975s-1.945.613-4.13.539c-2.165-.074-4.449-.233-4.801-2.891a5.499 5.499 0 0 1-.048-.745s2.125.52 4.818.643c1.646.075 3.19-.096 4.758-.283 3.007-.359 5.625-2.212 5.954-3.905.517-2.665.475-6.507.475-6.507zm-4.024 6.709h-2.497v-6.12c0-2.666-3.43-2.769-3.43.37v3.35H10.76v-3.35c0-3.139-3.43-3.036-3.43-.37v6.12H4.833c0-6.546-.28-7.919.985-9.374 1.388-1.55 4.28-1.652 5.561.327l.635 1.046.635-1.046c1.282-1.98 4.172-1.878 5.562-.327 1.265 1.455.985 2.828.985 9.374z" fill="currentColor"/></svg>
            </a>
            <?php endif; ?>
            <a href="<?= _e($siteUrl . '/feed.xml') ?>" class="site-footer__feed" aria-label="RSS feed">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 11a9 9 0 0 1 9 9"/><path d="M4 4a16 16 0 0 1 16 16"/><circle cx="5" cy="19" r="1.5" fill="currentColor" stroke="none"/></svg>
            </a>
        </div>
    </div>
</footer>

<script>
(function () {
    var COPY_ICON  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
    var CHECK_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';

    document.querySelectorAll('.prose pre').forEach(function (pre) {
        // Wrap the <pre> in a div so the copy button is outside the
        // scroll container and stays fixed at the top-right corner.
        var wrap = document.createElement('div');
        wrap.className = 'code-block' + (pre.classList.contains('syntax-hl') ? ' code-block--dark' : '');
        pre.parentNode.insertBefore(wrap, pre);
        wrap.appendChild(pre);

        var btn = document.createElement('button');
        btn.className = 'code-copy';
        btn.setAttribute('aria-label', 'Copy code');
        btn.innerHTML = COPY_ICON;
        wrap.appendChild(btn);

        btn.addEventListener('click', function () {
            var code = pre.querySelector('code');
            var text = (code || pre).textContent;
            navigator.clipboard.writeText(text).then(function () {
                btn.innerHTML = CHECK_ICON;
                btn.classList.add('code-copy--copied');
                setTimeout(function () {
                    btn.innerHTML = COPY_ICON;
                    btn.classList.remove('code-copy--copied');
                }, 2000);
            });
        });
    });

    // ── Lightbox ────────────────────────────────────────────────────────────
    var overlay = document.createElement('div');
    overlay.className = 'lightbox';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', 'Image lightbox');

    var img = document.createElement('img');
    img.className = 'lightbox__img';
    img.setAttribute('alt', '');

    var closeBtn = document.createElement('button');
    closeBtn.className = 'lightbox__close';
    closeBtn.setAttribute('aria-label', 'Close lightbox');
    closeBtn.textContent = '×';

    overlay.appendChild(img);
    overlay.appendChild(closeBtn);
    document.body.appendChild(overlay);

    function openLightbox(src, alt, naturalW, naturalH) {
        img.src = src;
        img.alt = alt || '';
        img.style.maxWidth  = naturalW > 0 ? 'min(' + naturalW + 'px, 100%)' : '';
        img.style.maxHeight = naturalH > 0 ? 'min(' + naturalH + 'px, 100%)' : '';
        overlay.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        closeBtn.focus();
    }

    function closeLightbox() {
        overlay.classList.remove('is-open');
        document.body.style.overflow = '';
        img.src = '';
        img.style.maxWidth  = '';
        img.style.maxHeight = '';
    }

    document.querySelectorAll('.prose img').forEach(function (el) {
        el.addEventListener('click', function () {
            openLightbox(el.src, el.alt, el.naturalWidth, el.naturalHeight);
        });
    });

    closeBtn.addEventListener('click', closeLightbox);

    // Click on backdrop (not the image) closes lightbox
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) { closeLightbox(); }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('is-open')) {
            closeLightbox();
        }
    });

    // ── Theme toggle ─────────────────────────────────────────────────────────
    var MOON_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
    var SUN_ICON  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>';

    function _isDark() {
        return document.documentElement.getAttribute('data-theme') === 'dark';
    }

    function _applyTheme(dark) {
        document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
        localStorage.setItem('theme', dark ? 'dark' : 'light');
        _updateToggleIcon(dark);
    }

    function _updateToggleIcon(dark) {
        var tb = document.getElementById('theme-toggle');
        if (!tb) { return; }
        tb.innerHTML = dark ? SUN_ICON : MOON_ICON;
        tb.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
    }

    // Set correct icon immediately (theme attribute already set by head script)
    _updateToggleIcon(_isDark());

    var themeBtn = document.getElementById('theme-toggle');
    if (themeBtn) {
        themeBtn.addEventListener('click', function () { _applyTheme(!_isDark()); });
    }

}());
</script>
<?php $tinylyticsCode = $settings['tinylytics_code'] ?? ''; ?>
<?php if ($tinylyticsCode !== ''): ?>
<script src="https://tinylytics.app/embed/<?= _e($tinylyticsCode) ?>.js" defer></script>
<?php endif; ?>
</body>
</html>
