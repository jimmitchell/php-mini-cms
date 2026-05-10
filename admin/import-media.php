<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$auth->check();

use CMS\Helpers;
use CMS\Media;

$mediaStorage = CMS_ROOT . '/content/media';
$mediaMaxBytes = (int) ($config['media']['max_bytes'] ?? 52_428_800);
$media = new Media($db, $mediaStorage, $mediaMaxBytes);

$siteUrl = (string) $db->getSetting('site_url', '');

// ── Handle batch download ─────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth->verifyCsrf($_POST['csrf_token'] ?? '');

    @set_time_limit(0);

    $rows = $db->select(
        "SELECT id, content FROM posts WHERE content LIKE '%<img%' ORDER BY id ASC"
    );

    $downloaded   = 0;
    $reused       = 0;
    $postsUpdated = 0;
    $errors       = [];

    /** @var array<string, array{id:int,filename:string,url:string,reused:bool}|false> $cache */
    $cache = [];

    foreach ($rows as $row) {
        $postId = (int) $row['id'];
        $content = (string) $row['content'];

        $urls = Media::extractExternalImageUrls($content, $siteUrl);
        if (empty($urls)) {
            continue;
        }

        $urlMap = [];
        foreach ($urls as $url) {
            if (array_key_exists($url, $cache)) {
                $cached = $cache[$url];
                if ($cached !== false) {
                    $urlMap[$url] = $cached['url'];
                }
                continue;
            }

            try {
                $result = $media->ingestFromUrl($url);
                $cache[$url] = $result;
                $urlMap[$url] = $result['url'];
                if ($result['reused']) {
                    $reused++;
                } else {
                    $downloaded++;
                }
            } catch (\Throwable $e) {
                $cache[$url] = false;
                $errors[] = "post {$postId}: " . $e->getMessage();
            }
        }

        if (empty($urlMap)) {
            continue;
        }

        $newContent = Media::rewriteImageUrls($content, $urlMap);
        if ($newContent !== $content) {
            $db->update(
                'posts',
                ['content' => $newContent, 'updated_at' => date('Y-m-d H:i:s')],
                'id = :id',
                ['id' => $postId]
            );
            $postsUpdated++;
        }
    }

    if ($postsUpdated > 0) {
        $builder->rebuildPosts();
        $builder->rebuildSharedResources();
    }

    $detail = "Media import: {$downloaded} downloaded, {$reused} reused, {$postsUpdated} post(s) updated";
    if (!empty($errors)) $detail .= ', ' . count($errors) . ' error(s)';
    $activityLog->log('import', 'media-batch', null, $detail);

    $flashType = !empty($errors) ? 'error' : 'success';
    $flashMsg  = $detail . '.';
    if (!empty($errors)) {
        $flashMsg .= ' First errors: ' . implode(' | ', array_slice($errors, 0, 3));
    }
    $auth->flash($flashMsg, $flashType);
    header('Location: /admin/import-media.php');
    exit;
}

// ── Stats for the GET render ──────────────────────────────────────────────────

$postsWithImg = (int) ($db->selectOne(
    "SELECT COUNT(*) AS n FROM posts WHERE content LIKE '%<img%'"
)['n'] ?? 0);

$rehostedCount = (int) ($db->selectOne(
    "SELECT COUNT(*) AS n FROM media WHERE source_url IS NOT NULL"
)['n'] ?? 0);

// Distinct external image URLs across all posts. Cap at counting (we don't
// list them here) so the page stays fast even with thousands of posts.
$distinctExternal = 0;
$postsAffected    = 0;
$seen = [];
$rows = $db->select("SELECT content FROM posts WHERE content LIKE '%<img%'");
foreach ($rows as $r) {
    $urls = Media::extractExternalImageUrls((string) $r['content'], $siteUrl);
    if (!empty($urls)) {
        $postsAffected++;
        foreach ($urls as $u) $seen[$u] = true;
    }
}
$distinctExternal = count($seen);
unset($rows, $seen);

$siteTitle = $db->getSetting('site_title', 'My CMS');
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
    <title>Import media — <?= Helpers::e($siteTitle) ?></title>
    <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body class="admin-page">

<?php require __DIR__ . '/partials/nav.php'; ?>

<main class="admin-main">
    <header class="page-header">
        <h1>Import media</h1>
    </header>

    <?php if ($flashMsg !== ''): ?>
        <p class="alert alert--<?= Helpers::e($flashType) ?>"><?= Helpers::e($flashMsg) ?></p>
    <?php endif; ?>

    <div class="panel" style="max-width:560px">
        <h2>Download external images</h2>
        <p>Scan every post for <code>&lt;img&gt;</code> URLs that point off-site, download the file into your media library, and rewrite the post HTML to use the local URL. Safe to re-run — already-downloaded URLs are skipped.</p>

        <table class="data-table" style="margin-bottom:1.25rem">
            <tbody>
                <tr><td>Posts containing <code>&lt;img&gt;</code></td><td style="text-align:right"><?= $postsWithImg ?></td></tr>
                <tr><td>Posts with external image URLs</td><td style="text-align:right"><?= $postsAffected ?></td></tr>
                <tr><td>Distinct external image URLs</td><td style="text-align:right"><?= $distinctExternal ?></td></tr>
                <tr><td>Media items previously imported by URL</td><td style="text-align:right"><?= $rehostedCount ?></td></tr>
            </tbody>
        </table>

        <?php if ($distinctExternal === 0): ?>
            <p style="color:var(--muted, #666);margin:0">Nothing to do — no external image URLs found.</p>
        <?php else: ?>
            <form method="post" action="/admin/import-media.php"
                  onsubmit="return confirm('This may take several minutes for a large backlog. Continue?');">
                <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
                <p style="font-size:.875rem;color:var(--muted, #666);margin:0 0 1rem">
                    Long-running operation. Behind a reverse proxy, ensure <code>proxy_read_timeout</code> /
                    <code>fastcgi_read_timeout</code> are large enough (default 60s is not). Failures
                    are logged and skipped — re-run to retry only those.
                </p>
                <button type="submit" class="btn">Download external images now</button>
            </form>
        <?php endif; ?>
    </div>

</main>

<script src="/admin/assets/admin.js"></script>
</body>
</html>
