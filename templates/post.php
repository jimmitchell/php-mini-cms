<?php
/**
 * Single post template.
 * Variables: $post (Post), $html (rendered Markdown), $settings, $navPages, $siteUrl, $render
 */

use CMS\Helpers;

$siteTitle   = $settings['site_title'] ?? 'My CMS';
$pageTitle   = $post->title . ' — ' . $siteTitle;
$effectiveExcerpt = $post->effectiveExcerpt();
$description = $effectiveExcerpt !== null
    ? strip_tags($effectiveExcerpt)
    : Helpers::truncate($html, 160);
$canonical   = rtrim($siteUrl, '/') . '/' . CMS\Post::datePath($post->published_at, $post->slug) . '/';
$ogType      = 'article';

ob_start();
?>
<article class="post">
    <header class="post__header">
        <h1 class="post__title"><?= htmlspecialchars($post->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
        <?php if ($post->published_at): ?>
        <time class="post__date" datetime="<?= htmlspecialchars($post->published_at) ?>">
            <?= Helpers::formatDate($post->published_at, 'l, F j, Y', $settings['locale'] ?? '', $settings['timezone'] ?? '') ?>
        </time>
        <?php endif; ?>
    </header>

    <div class="post__content prose">
        <?= $html ?>
    </div>
    <?php if ($post->mastodon_url || $post->bluesky_url): ?>
    <footer class="post__syndication">
        <span>Also on:</span>
        <?php if ($post->mastodon_url): ?>
        <a href="<?= htmlspecialchars($post->mastodon_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
           target="_blank" rel="noopener noreferrer">Mastodon</a>
        <?php endif; ?>
        <?php if ($post->bluesky_url): ?>
        <a href="<?= htmlspecialchars($post->bluesky_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
           target="_blank" rel="noopener noreferrer">Bluesky</a>
        <?php endif; ?>
    </footer>
    <?php endif; ?>
</article>

<?php if (($settings['webmention_domain'] ?? '') !== ''): ?>
<section class="webmentions" id="webmentions" data-url="<?= htmlspecialchars($canonical, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <h2 class="webmentions__title">Webmentions</h2>
    <div class="webmentions__body"></div>
</section>
<?php endif; ?>
<?php if ($prevPost || $nextPost): ?>
<nav class="post-nav" aria-label="Post navigation">
    <div class="post-nav__prev">
        <?php if ($prevPost): ?>
        <a class="post-nav__link" href="/<?= CMS\Post::datePath($prevPost->published_at, $prevPost->slug) ?>/"><span class="post-nav__arrow">←</span><span><?= htmlspecialchars($prevPost->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></a>
        <?php endif; ?>
    </div>
    <div class="post-nav__next">
        <?php if ($nextPost): ?>
        <a class="post-nav__link" href="/<?= CMS\Post::datePath($nextPost->published_at, $nextPost->slug) ?>/"><span><?= htmlspecialchars($nextPost->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><span class="post-nav__arrow">→</span></a>
        <?php endif; ?>
    </div>
</nav>
<?php endif; ?>
<?php
$bodyContent = ob_get_clean();

echo $render('base.php', compact(
    'pageTitle', 'description', 'canonical', 'ogType', 'ogImageUrl', 'bodyContent',
    'settings', 'navPages', 'siteUrl', 'render'
));
