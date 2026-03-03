<?php
/**
 * 404 Not Found page template.
 * Variables: $settings, $navPages, $siteUrl, $render
 */

$siteTitle   = $settings['site_title'] ?? 'My CMS';
$pageTitle   = '404 Not Found — ' . $siteTitle;
$description = 'The page you requested could not be found.';
$canonical   = '';
$ogType      = 'website';

ob_start();
?>
<div class="error-page">
    <h1 class="error-page__code">404</h1>
    <p class="error-page__message">Page not found.</p>
    <p class="error-page__hint">The page you're looking for doesn't exist or may have moved.</p>
    <a href="<?= htmlspecialchars(rtrim($siteUrl, '/') . '/') ?>" class="btn">← Back to home</a>
</div>
<?php
$bodyContent = ob_get_clean();

echo $render('base.php', compact(
    'pageTitle', 'description', 'canonical', 'ogType', 'bodyContent',
    'settings', 'navPages', 'siteUrl', 'render'
));
