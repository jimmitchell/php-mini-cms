<?php
// $db and $auth are available from the including page's bootstrap.
$siteTitle  = $db->getSetting('site_title', 'My CMS');
$currentUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

$navItems = [
    '/admin/dashboard.php' => 'Dashboard',
    '/admin/posts.php'     => 'Posts',
    '/admin/pages.php'     => 'Pages',
    '/admin/media.php'     => 'Media',
    '/admin/settings.php'  => 'Settings',
];
?>
<nav class="admin-nav">
    <div class="admin-nav__brand">
        <a href="/admin/dashboard.php"><?= htmlspecialchars($siteTitle) ?></a>
    </div>
    <ul class="admin-nav__links">
        <?php foreach ($navItems as $href => $label): ?>
        <li>
            <a href="<?= htmlspecialchars($href) ?>"
               class="<?= $currentUri === $href ? 'active' : '' ?>">
                <?= htmlspecialchars($label) ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
    <div class="admin-nav__actions">
        <a href="/" target="_blank" class="btn-link">View site</a>
        <form method="post" action="/admin/logout.php" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($auth->csrfToken()) ?>">
            <button type="submit" class="btn-link">Log out</button>
        </form>
    </div>
</nav>
