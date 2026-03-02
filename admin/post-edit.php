<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$auth->check();

use CMS\Post;
use CMS\Helpers;
use CMS\Mastodon;

$post    = null;
$isNew   = true;
$errors  = [];
$flash   = '';

// Mastodon config — loaded once, used in POST handler and template.
$mastodonInstance = $db->getSetting('mastodon_instance');
$mastodonToken    = $db->getSetting('mastodon_token');
$hasMastodon      = $mastodonInstance !== '' && $mastodonToken !== '';

// Load existing post if ?id= given.
if (isset($_GET['id'])) {
    $post = Post::findById($db, (int) $_GET['id']);
    if (!$post) {
        header('Location: /admin/posts.php');
        exit;
    }
    $isNew = false;
}

// ── Handle POST ───────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth->verifyCsrf($_POST['csrf_token'] ?? '');

    $action = $_POST['action'] ?? 'draft';

    // Handle delete before any save logic.
    if ($action === 'delete') {
        if ($post && $post->id) {
            $wasPublished = $post->status === 'published';
            $post->delete();
            $post->status = 'draft'; // so buildPost() takes the removal path
            $builder->buildPost($post); // removes the generated file
            if ($wasPublished) {
                $builder->buildIndex();
                $builder->buildFeed();
            }
            $auth->flash('Post deleted.', 'info');
            header('Location: /admin/posts.php');
            exit;
        }
    }

    // Populate from form.
    if ($post === null) {
        $post = new Post($db);
    }

    $post->title   = trim($_POST['title']   ?? '');
    $post->slug    = trim($_POST['slug']    ?? '');
    $post->content = $_POST['content'] ?? '';
    $post->excerpt = trim($_POST['excerpt'] ?? '') ?: null;

    // Auto-generate slug from title if blank.
    if ($post->slug === '') {
        $post->slug = Helpers::slugify($post->title);
    } else {
        $post->slug = Helpers::slugify($post->slug);
    }

    // Validation.
    if ($post->title === '') {
        $errors[] = 'Title is required.';
    }
    if ($post->slug === '' || $post->slug === 'untitled') {
        $errors[] = 'A valid slug is required.';
    }

    // Check slug uniqueness (allow saving over itself).
    $existing = Post::findBySlug($db, $post->slug);
    if ($existing && $existing->id !== $post->id) {
        $errors[] = 'That slug is already used by another post.';
    }

    if (empty($errors)) {
        // Parse the manual publish date if provided.
        // Interpret the input as local time in the configured timezone so it
        // round-trips correctly when displayed back in the list.
        $publishDateInput = trim($_POST['publish_date'] ?? '');
        $publishTs        = false;
        if ($publishDateInput !== '') {
            $cfgTzSave = $db->getSetting('timezone', '');
            if ($cfgTzSave !== '') {
                $dtParsed  = \DateTime::createFromFormat('Y-m-d\TH:i', $publishDateInput, new \DateTimeZone($cfgTzSave));
                $publishTs = $dtParsed !== false ? $dtParsed->getTimestamp() : false;
            }
            if ($publishTs === false) {
                $publishTs = strtotime($publishDateInput);
            }
        }

        // Apply status logic.
        match ($action) {
            'publish' => (function () use ($post, $publishTs) {
                $ts = ($publishTs !== false) ? $publishTs : time();
                if ($ts > time()) {
                    // Future date — schedule instead of publishing immediately.
                    $post->status       = 'scheduled';
                    $post->published_at = date('Y-m-d H:i:s', $ts);
                } else {
                    // Past or present — publish with the given date.
                    $post->status       = 'published';
                    $post->published_at = date('Y-m-d H:i:s', $ts);
                }
            })(),
            'unpublish' => (function () use ($post) {
                $post->status = 'draft';
            })(),
            default => (function () use ($post, $publishTs) {
                // 'draft' action — if the post is already published and a date
                // was provided, update published_at so the user can reorder posts.
                if ($post->status === 'published' && $publishTs !== false) {
                    $post->published_at = date('Y-m-d H:i:s', $publishTs);
                }
            })(),
        };

        // Keep status = draft for brand-new posts saved as draft.
        if ($action === 'draft' && $isNew) {
            $post->status = 'draft';
        }

        // Persist the user's opt-out choice so the checkbox stays unchecked on re-edit.
        $post->mastodon_skip = empty($_POST['send_to_mastodon']) ? 1 : 0;

        // Only toot on first publish (not when the result is 'scheduled').
        $isFirstPublish = $post->status === 'published'
            && $action === 'publish'
            && $post->tooted_at === null
            && $hasMastodon
            && $post->mastodon_skip === 0;

        $post->save();

        // Syndicate to Mastodon on first publish (unless opted out).
        if ($isFirstPublish) {
            $siteUrlForToot = $db->getSetting('site_url', '');
            $postUrl        = rtrim($siteUrlForToot, '/') . '/' . Post::datePath($post->published_at, $post->slug) . '/';
            $excerpt        = ($post->effectiveExcerpt() !== null)
                ? strip_tags($post->effectiveExcerpt())
                : Helpers::truncate($post->content, 280);
            $mastodon = new Mastodon($mastodonInstance, $mastodonToken);
            if ($mastodon->tootPost($post->title, $excerpt, $postUrl)) {
                $post->markTooted();
            }
        }

        // Trigger builds based on what actually happened.
        if (($action === 'publish' && $post->status === 'published') || $action === 'unpublish') {
            // Visibility changed — rebuild post + index + feed.
            $builder->buildPost($post);
            $builder->buildIndex();
            $builder->buildFeed();
        } elseif ($action === 'draft' && $post->status === 'published') {
            // Content or date update on a published post — rebuild post + feed.
            $builder->buildPost($post);
            $builder->buildFeed();
        }
        // Scheduled and draft-only saves don't need a build.

        $label = match (true) {
            $action === 'unpublish'                              => 'Post unpublished.',
            $action === 'publish' && $post->status === 'scheduled' => 'Post scheduled.',
            $action === 'publish'                               => 'Post published.',
            default                                             => 'Draft saved.',
        };
        $auth->flash($label);

        if ($isNew) {
            header('Location: /admin/post-edit.php?id=' . $post->id);
            exit;
        }
        header('Location: /admin/post-edit.php?id=' . $post->id);
        exit;
    }
}


$flash     = $auth->getFlash();
$flashMsg  = $flash['message'] ?? '';
$flashType = $flash['type']    ?? 'success';

// ── Defaults for new post ─────────────────────────────────────────────────────

if ($post === null) {
    $post = new Post($db);
}

// Load media for sidebar insert panel.
$mediaItems = $db->select(
    "SELECT id, filename, mime_type, original_name
       FROM media
      ORDER BY uploaded_at DESC
      LIMIT 50"
);

$siteUrl   = $db->getSetting('site_url', '');
$siteTitle = $db->getSetting('site_title', 'My CMS');
$cfgTz     = $db->getSetting('timezone', '');
$csrf      = $auth->csrfToken();

// Convert stored UTC publish date to local time for the datetime-local input.
$pubInputVal = '';
if ($post->published_at) {
    $dt = new \DateTime($post->published_at, new \DateTimeZone('UTC'));
    if ($cfgTz !== '') {
        $dt->setTimezone(new \DateTimeZone($cfgTz));
    }
    $pubInputVal = $dt->format('Y-m-d\TH:i');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $isNew ? 'New Post' : Helpers::e($post->title) ?> — <?= Helpers::e($siteTitle) ?></title>
    <link rel="stylesheet" href="/admin/assets/admin.css">
    <link rel="stylesheet" href="/admin/assets/easymde.min.css">
    <link rel="stylesheet" href="/admin/assets/font-awesome.min.css">
</head>
<body class="admin-page">

<?php require __DIR__ . '/partials/nav.php'; ?>

<main class="admin-main">
    <header class="page-header">
        <h1><?= $isNew ? 'New Post' : 'Edit Post' ?></h1>
        <?php if (!$isNew && $post->status === 'published'): ?>
        <a href="/<?= Helpers::e(Post::datePath($post->published_at, $post->slug)) ?>/" target="_blank" class="btn btn--secondary">View post</a>
        <?php endif; ?>
    </header>

    <?php foreach ($errors as $e): ?>
        <p class="alert alert--error"><?= Helpers::e($e) ?></p>
    <?php endforeach; ?>

    <?php if ($flashMsg !== ''): ?>
        <p class="alert alert--<?= Helpers::e($flashType) ?>"><?= Helpers::e($flashMsg) ?></p>
    <?php endif; ?>

    <form method="post" action="/admin/post-edit.php<?= $post->id ? '?id=' . $post->id : '' ?>" id="post-form">
        <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
        <input type="hidden" name="action"     value="draft" id="form-action">

        <div class="editor-layout">

            <!-- Left: main content -->
            <div class="editor-main">
                <label for="title">Title</label>
                <input type="text" id="title" name="title"
                       value="<?= Helpers::e($post->title) ?>"
                       placeholder="Post title"
                       required
                       data-slug-source>

                <label for="slug">Slug</label>
                <div style="display:flex;gap:.5rem;align-items:center">
                    <span style="color:var(--color-muted);font-size:.85rem;white-space:nowrap">/<?= date('Y/m/d', strtotime($post->published_at ?? 'now')) ?>/</span>
                    <input type="text" id="slug" name="slug"
                           value="<?= Helpers::e($post->slug) ?>"
                           placeholder="auto-generated"
                           style="flex:1">
                </div>
                <p class="form-hint">Leave blank to auto-generate from title. Only lowercase letters, numbers, and hyphens.</p>

                <label for="content" style="margin-top:1.25rem">Content</label>
                <textarea id="content" name="content"><?= Helpers::e($post->content) ?></textarea>

                <label for="excerpt">Excerpt <span style="font-weight:400;color:var(--color-muted)">(optional)</span></label>
                <textarea id="excerpt" name="excerpt" style="min-height:80px"><?= Helpers::e($post->excerpt ?? '') ?></textarea>
                <p class="form-hint">Shown on the post index. Leave blank to use the start of the post content.</p>
            </div>

            <!-- Right: sidebar -->
            <div class="editor-sidebar">

                <!-- Publish controls -->
                <div class="panel">
                    <h2>Publish</h2>

                    <div style="margin-bottom:.75rem">
                        <span class="badge badge--<?= $post->status ?>"><?= $post->status ?></span>
                    </div>

                    <?php if ($hasMastodon && $post->tooted_at === null): ?>
                    <label style="display:flex;gap:.5rem;align-items:center;font-size:.875rem;font-weight:400;margin-bottom:.75rem">
                        <input type="checkbox" name="send_to_mastodon" value="1"
                               <?= $post->mastodon_skip === 0 ? 'checked' : '' ?>>
                        Post to Mastodon on publish
                    </label>
                    <?php elseif ($hasMastodon && $post->tooted_at !== null): ?>
                    <p class="form-hint" style="margin-bottom:.75rem">&#10003; Already shared to Mastodon</p>
                    <?php endif; ?>

                    <label for="publish_date" style="margin-top:0">Publish date</label>
                    <input type="datetime-local" id="publish_date" name="publish_date"
                           value="<?= Helpers::e($pubInputVal) ?>">
                    <p class="form-hint">Leave blank to use the current time. A future date will schedule the post.</p>

                    <div style="display:flex;flex-direction:column;gap:.5rem;margin-top:.75rem">
                        <button type="submit" class="btn btn--secondary"
                                onclick="setAction('draft')">
                            <?= $post->status === 'published' ? 'Update post' : 'Save draft' ?>
                        </button>

                        <?php if ($post->status !== 'published'): ?>
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

                <!-- Media insert panel -->
                <?php if (!empty($mediaItems)): ?>
                <div class="panel">
                    <h2>Insert media</h2>
                    <p class="form-hint" style="margin-bottom:.75rem">Click to insert at cursor.</p>
                    <div class="media-grid" id="media-insert-grid">
                        <?php foreach ($mediaItems as $m): ?>
                        <?php
                            $url      = '/media/' . rawurlencode($m['filename']);
                            $isImage  = str_starts_with($m['mime_type'], 'image/');
                            $isVideo  = str_starts_with($m['mime_type'], 'video/');
                            $isAudio  = str_starts_with($m['mime_type'], 'audio/');
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
                            onclick="return confirm('Delete this post? This cannot be undone.') && setAction('delete')">
                        Delete post
                    </button>
                </div>
                <?php endif; ?>

            </div><!-- /editor-sidebar -->
        </div><!-- /editor-layout -->
    </form>
</main>

<script src="/admin/assets/easymde.min.js"></script>
<script src="/admin/assets/admin.js"></script>

</body>
</html>
