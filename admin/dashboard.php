<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$auth->check();

use CMS\Post;
use CMS\Webmention;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $auth->verifyCsrf($_POST['csrf_token'] ?? '');

    if ($_POST['action'] === 'rebuild') {
        $builder->buildAll();
        $auth->flash('Full site rebuild complete.');
        header('Location: /admin/dashboard.php');
        exit;
    }

    if ($_POST['action'] === 'send_webmentions') {
        $outputDir = $config['paths']['output'];
        $siteUrl   = rtrim($settings['site_url'] ?? '', '/');
        $posts     = Post::findAll($db, 'published');

        $sent = $skipped = $failed = $processed = 0;

        foreach ($posts as $post) {
            // Skip if already sent and post hasn't changed since.
            if ($post->webmentions_sent_at !== null
                && strtotime($post->updated_at) <= strtotime($post->webmentions_sent_at)) {
                $skipped++;
                continue;
            }

            $htmlPath = $outputDir . '/posts/' . Post::datePath($post->published_at, $post->slug) . '/index.html';
            if (!file_exists($htmlPath)) {
                $skipped++;
                continue;
            }

            $html    = (string) file_get_contents($htmlPath);
            $postUrl = $siteUrl . '/' . Post::datePath($post->published_at, $post->slug) . '/';
            $urls    = Webmention::extractUrls($html, $siteUrl);

            foreach ($urls as $target) {
                $endpoint = Webmention::discoverEndpoint($target);
                if ($endpoint === null) {
                    continue;
                }
                Webmention::sendPing($postUrl, $target, $endpoint) ? $sent++ : $failed++;
            }

            $post->markWebmentionsSent();
            $processed++;
        }

        $auth->flash("Webmentions: {$sent} sent across {$processed} posts; {$failed} failed; {$skipped} already up to date.");
        header('Location: /admin/dashboard.php');
        exit;
    }
}

$flash     = $auth->getFlash();
$flashMsg  = $flash['message'] ?? '';
$flashType = $flash['type']    ?? 'success';

// Quick stats.
$postStats = $db->selectOne(
    "SELECT
        SUM(CASE WHEN status = 'published'  THEN 1 ELSE 0 END) AS published,
        SUM(CASE WHEN status = 'draft'      THEN 1 ELSE 0 END) AS draft,
        SUM(CASE WHEN status = 'scheduled'  THEN 1 ELSE 0 END) AS scheduled,
        COUNT(*) AS total
     FROM posts"
);

$pageCount  = (int) ($db->selectOne("SELECT COUNT(*) AS cnt FROM pages")['cnt'] ?? 0);
$mediaCount = (int) ($db->selectOne("SELECT COUNT(*) AS cnt FROM media")['cnt'] ?? 0);

// Scheduled posts due soon (next 24 h).
$dueSoon = $db->select(
    "SELECT id, title, published_at
       FROM posts
      WHERE status = 'scheduled'
        AND published_at <= datetime('now', '+24 hours')
      ORDER BY published_at ASC"
);

// All drafts, newest first.
$drafts = $db->select(
    "SELECT id, title, updated_at
       FROM posts
      WHERE status = 'draft'
      ORDER BY updated_at DESC"
);

// All scheduled posts, soonest first.
$scheduled = $db->select(
    "SELECT id, title, published_at
       FROM posts
      WHERE status = 'scheduled'
      ORDER BY published_at ASC"
);

$siteTitle = $db->getSetting('site_title', 'My CMS');
$csrf      = $auth->csrfToken();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard — <?= htmlspecialchars($siteTitle) ?></title>
    <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body class="admin-page">

<?php require __DIR__ . '/partials/nav.php'; ?>

<main class="admin-main">
    <header class="page-header">
        <h1>Dashboard</h1>
    </header>

    <?php if ($flashMsg !== ''): ?>
        <p class="alert alert--<?= htmlspecialchars($flashType) ?>"><?= htmlspecialchars($flashMsg) ?></p>
    <?php endif; ?>

    <section class="stats-grid">
        <div class="stat-card">
            <span class="stat-card__number"><?= (int) ($postStats['published'] ?? 0) ?></span>
            <span class="stat-card__label">Published Posts</span>
        </div>
        <div class="stat-card">
            <span class="stat-card__number"><?= (int) ($postStats['draft'] ?? 0) ?></span>
            <span class="stat-card__label">Drafts</span>
        </div>
        <div class="stat-card">
            <span class="stat-card__number"><?= (int) ($postStats['scheduled'] ?? 0) ?></span>
            <span class="stat-card__label">Scheduled</span>
        </div>
        <div class="stat-card">
            <span class="stat-card__number"><?= $pageCount ?></span>
            <span class="stat-card__label">Pages</span>
        </div>
        <div class="stat-card">
            <span class="stat-card__number"><?= $mediaCount ?></span>
            <span class="stat-card__label">Media Files</span>
        </div>
    </section>

    <?php if ($dueSoon): ?>
    <section class="panel">
        <h2>Scheduled — due within 24 h</h2>
        <ul class="item-list">
            <?php foreach ($dueSoon as $post): ?>
            <li>
                <a href="/admin/post-edit.php?id=<?= (int) $post['id'] ?>">
                    <?= htmlspecialchars($post['title']) ?>
                </a>
                <span class="meta"><?= htmlspecialchars($post['published_at']) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if ($drafts): ?>
    <section class="panel">
        <h2>Drafts</h2>
        <ul class="item-list">
            <?php foreach ($drafts as $post): ?>
            <li>
                <a href="/admin/post-edit.php?id=<?= (int) $post['id'] ?>">
                    <?= htmlspecialchars($post['title']) ?>
                </a>
                <span class="meta">Updated <?= htmlspecialchars($post['updated_at']) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if ($scheduled): ?>
    <section class="panel">
        <h2>Scheduled</h2>
        <ul class="item-list">
            <?php foreach ($scheduled as $post): ?>
            <li>
                <a href="/admin/post-edit.php?id=<?= (int) $post['id'] ?>">
                    <?= htmlspecialchars($post['title']) ?>
                </a>
                <span class="meta">Publishes <?= htmlspecialchars($post['published_at']) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <section class="panel">
        <h2>Actions</h2>
        <form method="post" action="/admin/dashboard.php" style="display:inline-block;margin-right:.5rem">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="rebuild">
            <button type="submit" class="btn btn--secondary">Rebuild entire site</button>
        </form>
        <form method="post" action="/admin/dashboard.php" style="display:inline-block">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="send_webmentions">
            <button type="submit" class="btn btn--secondary">Send webmentions</button>
        </form>
    </section>
</main>
<script src="/admin/assets/admin.js"></script>
</body>
</html>
