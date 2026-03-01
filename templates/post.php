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
$canonical   = rtrim($siteUrl, '/') . '/posts/' . rawurlencode($post->slug) . '/';
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
</article>

<?php if ($prevPost || $nextPost): ?>
<nav class="post-nav" aria-label="Post navigation">
    <div class="post-nav__prev">
        <?php if ($prevPost): ?>
        <a class="post-nav__link" href="/posts/<?= rawurlencode($prevPost->slug) ?>/"><span class="post-nav__arrow">←</span><span><?= htmlspecialchars($prevPost->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></a>
        <?php endif; ?>
    </div>
    <div class="post-nav__next">
        <?php if ($nextPost): ?>
        <a class="post-nav__link" href="/posts/<?= rawurlencode($nextPost->slug) ?>/"><span><?= htmlspecialchars($nextPost->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><span class="post-nav__arrow">→</span></a>
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
