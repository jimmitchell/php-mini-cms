<?php
/**
 * Taxonomy archive template (categories and tags).
 * Variables: $type ('category'|'tag'), $term (assoc: id, name, slug, description?),
 *            $posts (Post[]), $settings, $navPages, $siteUrl, $render
 */

use CMS\Helpers;

$siteTitle   = $settings['site_title'] ?? 'My CMS';
$termName    = htmlspecialchars($term['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$heading     = $type === 'category'
    ? 'Category: ' . $term['name']
    : 'Tag: ' . $term['name'];
$pageTitle   = $heading . ' — ' . $siteTitle;
$description = ($type === 'category' && ($term['description'] ?? '') !== '')
    ? $term['description']
    : ($siteTitle . ' posts ' . ($type === 'category' ? 'in category ' : 'tagged ') . $term['name']);
$canonical   = rtrim($siteUrl, '/') . '/' . $type . '/' . rawurlencode($term['slug']) . '/';
$ogType      = 'website';
$ogImageUrl  = '';

ob_start();
?>
<div class="taxonomy-archive">
    <header class="taxonomy-archive__header">
        <p class="taxonomy-archive__type"><?= $type === 'category' ? 'Category' : 'Tag' ?></p>
        <h1 class="taxonomy-archive__title"><?= $termName ?></h1>
        <?php if ($type === 'category' && ($term['description'] ?? '') !== ''): ?>
        <p class="taxonomy-archive__description"><?= htmlspecialchars($term['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php endif; ?>
    </header>

    <div class="post-list">
        <?php if (empty($posts)): ?>
        <p class="post-list__empty">No published posts yet.</p>
        <?php else: ?>

        <?php foreach ($posts as $post): ?>
        <?php $postUrl = rtrim($siteUrl, '/') . '/' . CMS\Post::datePath($post->published_at, $post->slug) . '/'; ?>
        <article class="post-card h-entry">
            <h2 class="post-card__title">
                <a href="<?= htmlspecialchars($postUrl) ?>" class="u-url p-name"><?= htmlspecialchars($post->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
            </h2>
            <?php if ($post->published_at): ?>
            <time class="post-card__date dt-published" datetime="<?= htmlspecialchars($post->published_at) ?>">
                <?= Helpers::formatDate($post->published_at, 'l, F j, Y', $settings['locale'] ?? '', $settings['timezone'] ?? '') ?>
            </time>
            <?php endif; ?>
            <?php $cardExcerpt = $post->effectiveExcerpt(); ?>
            <?php if ($cardExcerpt !== null): ?>
            <p class="post-card__excerpt p-summary"><?= htmlspecialchars(strip_tags($cardExcerpt), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <?php endif; ?>
            <a href="<?= htmlspecialchars($postUrl) ?>" class="post-card__more">Read more →</a>
        </article>
        <?php endforeach; ?>

        <?php endif; ?>
    </div>
</div>
<?php
$bodyContent = ob_get_clean();
$wideLayout  = true;

echo $render('base.php', compact(
    'pageTitle', 'description', 'canonical', 'ogType', 'ogImageUrl', 'bodyContent',
    'settings', 'navPages', 'siteUrl', 'render', 'wideLayout'
));
