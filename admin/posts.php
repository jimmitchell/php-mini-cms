<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$auth->check();

use CMS\Post;
use CMS\Helpers;

// Handle quick-delete from the list.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $auth->verifyCsrf($_POST['csrf_token'] ?? '');
    $post = Post::findById($db, (int) ($_POST['id'] ?? 0));
    if ($post) {
        $wasPublished = $post->status === 'published';
        $post->delete();
        $builder->buildPost($post);
        if ($wasPublished) {
            $builder->buildIndex();
            $builder->buildFeed();
        }
    }
    header('Location: /admin/posts.php');
    exit;
}

$statusFilter = $_GET['status'] ?? 'all';
$posts        = Post::findAll($db, $statusFilter === 'all' ? null : $statusFilter);

// When showing all statuses, float drafts to the top.
if ($statusFilter === 'all') {
    usort($posts, function ($a, $b) {
        $aWeight = $a->status === 'draft' ? 0 : 1;
        $bWeight = $b->status === 'draft' ? 0 : 1;
        if ($aWeight !== $bWeight) {
            return $aWeight - $bWeight;
        }
        // Within the same group keep the existing date-descending order.
        $aDate = $a->published_at ?? $a->created_at;
        $bDate = $b->published_at ?? $b->created_at;
        return strcmp($bDate, $aDate);
    });
}

$counts = $db->selectOne(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status='published' THEN 1 ELSE 0 END) AS published,
        SUM(CASE WHEN status='draft'     THEN 1 ELSE 0 END) AS draft,
        SUM(CASE WHEN status='scheduled' THEN 1 ELSE 0 END) AS scheduled
     FROM posts"
);

$siteTitle = $db->getSetting('site_title', 'My CMS');
$timezone  = $db->getSetting('timezone', '');
$csrf      = $auth->csrfToken();
$flash     = $auth->getFlash();
$flashMsg  = $flash['message'] ?? '';
$flashType = $flash['type']    ?? 'success';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Posts — <?= Helpers::e($siteTitle) ?></title>
    <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body class="admin-page">

<?php require __DIR__ . '/partials/nav.php'; ?>

<main class="admin-main">
    <header class="page-header">
        <h1>Posts</h1>
        <a href="/admin/post-edit.php" class="btn">+ New post</a>
    </header>

    <?php if ($flashMsg !== ''): ?>
        <p class="alert alert--<?= Helpers::e($flashType) ?>"><?= Helpers::e($flashMsg) ?></p>
    <?php endif; ?>

    <div class="toolbar">
        <div class="filter-tabs">
            <?php
            $tabs = [
                'all'       => 'All (' . ($counts['total'] ?? 0) . ')',
                'published' => 'Published (' . ($counts['published'] ?? 0) . ')',
                'draft'     => 'Draft (' . ($counts['draft'] ?? 0) . ')',
                'scheduled' => 'Scheduled (' . ($counts['scheduled'] ?? 0) . ')',
            ];
            foreach ($tabs as $key => $label): ?>
            <a href="/admin/posts.php?status=<?= $key ?>"
               class="<?= $statusFilter === $key ? 'active' : '' ?>">
                <?= Helpers::e($label) ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="panel" style="padding:0">
        <?php if (empty($posts)): ?>
            <p style="padding:1.5rem; color:var(--color-muted)">No posts found.</p>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Status</th>
                    <th>Published</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($posts as $post): ?>
            <tr>
                <td>
                    <a href="/admin/post-edit.php?id=<?= $post->id ?>">
                        <?= Helpers::e($post->title) ?>
                    </a>
                </td>
                <td>
                    <span class="badge badge--<?= $post->status ?>">
                        <?= $post->status ?>
                    </span>
                </td>
                <td class="meta">
                    <?= $post->published_at ? Helpers::formatDate($post->published_at, 'M j, Y g:i a', '', $timezone) : '—' ?>
                </td>
                <td>
                    <div class="actions">
                        <a href="/admin/post-edit.php?id=<?= $post->id ?>" class="btn btn--sm btn--secondary">Edit</a>
                        <?php if ($post->status === 'published'): ?>
                        <a href="/posts/<?= Helpers::e($post->slug) ?>/" target="_blank" class="btn btn--sm btn--secondary">View</a>
                        <?php endif; ?>
                        <form method="post" action="/admin/posts.php"
                              onsubmit="return confirm('Delete &quot;<?= addslashes(htmlspecialchars($post->title)) ?>&quot;?')">
                            <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
                            <input type="hidden" name="action"     value="delete">
                            <input type="hidden" name="id"         value="<?= $post->id ?>">
                            <button type="submit" class="btn btn--sm btn--danger">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</main>

</body>
</html>
