<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$auth->check();

use CMS\Page;
use CMS\Helpers;

$page   = null;
$isNew  = true;
$errors = [];
$flash  = '';

// Load existing page if ?id= given.
if (isset($_GET['id'])) {
    $page = Page::findById($db, (int) $_GET['id']);
    if (!$page) {
        header('Location: /admin/pages.php');
        exit;
    }
    $isNew = false;
}

// ── Handle POST ───────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth->verifyCsrf($_POST['csrf_token'] ?? '');

    $action = $_POST['action'] ?? 'draft';

    if ($page === null) {
        $page = new Page($db);
    }

    $page->title     = trim($_POST['title']     ?? '');
    $page->slug      = trim($_POST['slug']      ?? '');
    $page->content   = $_POST['content'] ?? '';
    $page->nav_order = (int) ($_POST['nav_order'] ?? 0);

    if ($page->slug === '') {
        $page->slug = Helpers::slugify($page->title);
    } else {
        $page->slug = Helpers::slugify($page->slug);
    }

    if ($page->title === '') {
        $errors[] = 'Title is required.';
    }
    if ($page->slug === '' || $page->slug === 'untitled') {
        $errors[] = 'A valid slug is required.';
    }

    $existing = Page::findBySlug($db, $page->slug);
    if ($existing && $existing->id !== $page->id) {
        $errors[] = 'That slug is already used by another page.';
    }

    if (empty($errors)) {
        $page->status = match ($action) {
            'publish'   => 'published',
            'unpublish' => 'draft',
            default     => $page->status ?: 'draft',
        };

        $page->save();
        $builder->buildAll();

        $label = match ($action) {
            'publish'   => 'Page published.',
            'unpublish' => 'Page unpublished.',
            default     => 'Draft saved.',
        };
        $auth->flash($label);
        header('Location: /admin/page-edit.php?id=' . $page->id);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if ($page && $page->id) {
        $page->delete();
        $builder->buildPage($page);
        $auth->flash('Page deleted.', 'info');
        header('Location: /admin/pages.php');
        exit;
    }
}

$flash     = $auth->getFlash();
$flashMsg  = $flash['message'] ?? '';
$flashType = $flash['type']    ?? 'success';

if ($page === null) {
    $page = new Page($db);
}

// Media sidebar.
$mediaItems = $db->select(
    "SELECT id, filename, mime_type, original_name
       FROM media
      ORDER BY uploaded_at DESC
      LIMIT 50"
);

$siteTitle = $db->getSetting('site_title', 'My CMS');
$csrf      = $auth->csrfToken();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $isNew ? 'New Page' : Helpers::e($page->title) ?> — <?= Helpers::e($siteTitle) ?></title>
    <link rel="stylesheet" href="/admin/assets/admin.css">
    <link rel="stylesheet" href="/admin/assets/easymde.min.css">
    <link rel="stylesheet" href="/admin/assets/font-awesome.min.css">
</head>
<body class="admin-page">

<?php require __DIR__ . '/partials/nav.php'; ?>

<main class="admin-main">
    <header class="page-header">
        <h1><?= $isNew ? 'New Page' : 'Edit Page' ?></h1>
        <?php if (!$isNew && $page->status === 'published'): ?>
        <a href="/<?= Helpers::e($page->slug) ?>/" target="_blank" class="btn btn--secondary">View page</a>
        <?php endif; ?>
    </header>

    <?php foreach ($errors as $e): ?>
        <p class="alert alert--error"><?= Helpers::e($e) ?></p>
    <?php endforeach; ?>

    <?php if ($flashMsg !== ''): ?>
        <p class="alert alert--<?= Helpers::e($flashType) ?>"><?= Helpers::e($flashMsg) ?></p>
    <?php endif; ?>

    <form method="post" action="/admin/page-edit.php<?= $page->id ? '?id=' . $page->id : '' ?>" id="post-form">
        <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
        <input type="hidden" name="action"     value="draft" id="form-action">

        <div class="editor-layout">

            <div class="editor-main">
                <label for="title">Title</label>
                <input type="text" id="title" name="title"
                       value="<?= Helpers::e($page->title) ?>"
                       placeholder="Page title"
                       required
                       data-slug-source>

                <label for="slug">Slug</label>
                <div style="display:flex;gap:.5rem;align-items:center">
                    <span style="color:var(--color-muted);font-size:.85rem;white-space:nowrap">/</span>
                    <input type="text" id="slug" name="slug"
                           value="<?= Helpers::e($page->slug) ?>"
                           placeholder="auto-generated"
                           style="flex:1">
                </div>
                <p class="form-hint">Leave blank to auto-generate from title.</p>

                <label for="content" style="margin-top:1.25rem">Content</label>
                <textarea id="content" name="content"><?= Helpers::e($page->content) ?></textarea>
            </div>

            <div class="editor-sidebar">

                <!-- Publish controls -->
                <div class="panel">
                    <h2>Publish</h2>

                    <div style="margin-bottom:.75rem">
                        <span class="badge badge--<?= $page->status ?>"><?= $page->status ?></span>
                    </div>

                    <div style="display:flex;flex-direction:column;gap:.5rem">
                        <button type="submit" class="btn btn--secondary"
                                onclick="setAction('draft')"
                                <?= $page->status === 'published' ? 'id="update-btn" disabled' : '' ?>>
                            <?= $page->status === 'published' ? 'Update page' : 'Save draft' ?>
                        </button>

                        <?php if ($page->status !== 'published'): ?>
                        <button type="submit" class="btn"
                                onclick="setAction('publish')">
                            Publish
                        </button>
                        <?php else: ?>
                        <button type="submit" class="btn btn--secondary"
                                onclick="setAction('unpublish')">
                            Unpublish
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Nav order -->
                <div class="panel">
                    <h2>Navigation</h2>
                    <label for="nav_order">Nav order</label>
                    <input type="number" id="nav_order" name="nav_order"
                           value="<?= $page->nav_order ?>" min="0" step="1">
                    <p class="form-hint">Lower numbers appear first in the header navigation.</p>
                </div>

                <!-- Media insert -->
                <?php if (!empty($mediaItems)): ?>
                <div class="panel">
                    <h2>Insert media</h2>
                    <p class="form-hint" style="margin-bottom:.75rem">Click to insert at cursor.</p>
                    <div class="media-grid" id="media-insert-grid">
                        <?php foreach ($mediaItems as $m): ?>
                        <?php
                            $url     = '/media/' . rawurlencode($m['filename']);
                            $isImage = str_starts_with($m['mime_type'], 'image/');
                            $isVideo = str_starts_with($m['mime_type'], 'video/');
                        ?>
                        <button type="button" class="media-thumb"
                                data-url="<?= Helpers::e($url) ?>"
                                data-type="<?= $isImage ? 'image' : ($isVideo ? 'video' : 'audio') ?>"
                                data-name="<?= Helpers::e($m['original_name']) ?>"
                                title="<?= Helpers::e($m['original_name']) ?>">
                            <?php if ($isImage): ?>
                                <img src="<?= Helpers::e($url) ?>" alt="<?= Helpers::e($m['original_name']) ?>">
                            <?php elseif ($isVideo): ?>
                                <span class="media-icon">▶</span>
                            <?php else: ?>
                                <span class="media-icon">♪</span>
                            <?php endif; ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <a href="/admin/media.php" class="form-hint" style="display:block;margin-top:.5rem">Manage media →</a>
                </div>
                <?php endif; ?>

                <!-- Danger zone -->
                <?php if (!$isNew): ?>
                <div class="panel">
                    <h2>Danger</h2>
                    <button type="submit" class="btn btn--danger"
                            style="width:100%"
                            onclick="return confirm('Delete this page? This cannot be undone.') && setAction('delete')">
                        Delete page
                    </button>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </form>
</main>

<script src="/admin/assets/easymde.min.js"></script>
<script src="/admin/assets/admin.js"></script>

</body>
</html>
