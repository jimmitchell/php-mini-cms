<?php

declare(strict_types=1);

/**
 * Micropub endpoint — accepts new posts from Micropub clients (iA Writer,
 * Quill, MarsEdit, Drafts, etc.).
 *
 * Auth: Bearer token, generated in Settings → Micropub.
 *
 * Supported request types:
 *   GET  /micropub.php?q=config           configuration discovery
 *   POST /micropub.php  (form-encoded)    h=entry, name=…, content=…, category[]=…
 *   POST /micropub.php  (JSON)            {type:["h-entry"], properties:{…}}
 *   POST /micropub.php  (multipart)       same as form-encoded plus photo[] uploads
 *
 * Response:
 *   201 Created + Location: <new post URL> on success
 *   400 / 401 / 429 / 500 + JSON {error, error_description} on failure
 */

// Read raw body before any session-starting code consumes it.
$_mpRawBody = (string) file_get_contents('php://input');

define('CMS_ROOT', __DIR__);
require CMS_ROOT . '/vendor/autoload.php';

$config      = require CMS_ROOT . '/config.php';
$db          = new \CMS\Database($config['paths']['data'] . '/cms.db');
$builder     = new \CMS\Builder($config, $db);
$activityLog = new \CMS\ActivityLog($db);

\CMS\Post::promoteScheduled($db);

// ── Response helpers ────────────────────────────────────────────────────────

function mp_json(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mp_error(string $code, string $description = '', int $status = 400): never
{
    $payload = ['error' => $code];
    if ($description !== '') {
        $payload['error_description'] = $description;
    }
    mp_json($payload, $status);
}

// ── Auth ────────────────────────────────────────────────────────────────────

function mp_extract_bearer_token(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header  = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    if ($header !== '' && preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
        return trim($m[1]);
    }

    // Spec also allows access_token in the form body (form-encoded only).
    if (!empty($_POST['access_token']) && is_string($_POST['access_token'])) {
        return $_POST['access_token'];
    }

    return '';
}

function mp_authenticate(\CMS\Database $db, array $config): void
{
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $max = (int) ($config['security']['max_login_attempts'] ?? 5);
    $win = (int) ($config['security']['lockout_minutes'] ?? 15);

    $row = $db->selectOne(
        "SELECT COUNT(*) AS cnt
           FROM login_attempts
          WHERE ip      = :ip
            AND success = 0
            AND attempted_at >= datetime('now', :w)",
        ['ip' => $ip, 'w' => "-{$win} minutes"]
    );

    if (($row['cnt'] ?? 0) >= $max) {
        mp_error('rate_limited', 'Too many failed attempts. Try again later.', 429);
    }

    $stored = $db->getSetting('micropub_token', '');
    $token  = mp_extract_bearer_token();

    $ok = $stored !== '' && $token !== '' && hash_equals($stored, $token);

    if (!$ok) {
        $db->insert('login_attempts', ['ip' => $ip, 'success' => 0]);
        header('WWW-Authenticate: Bearer realm="Micropub"');
        mp_error('unauthorized', 'Invalid or missing access token', 401);
    }
}

// ── GET: configuration queries ──────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    mp_authenticate($db, $config);

    $q       = $_GET['q'] ?? '';
    $siteUrl = rtrim($db->getSetting('site_url', ''), '/');

    if ($q === 'config') {
        mp_json([
            'media-endpoint' => $siteUrl . '/micropub.php',
            'syndicate-to'   => [],
        ]);
    }

    if ($q === 'syndicate-to') {
        mp_json(['syndicate-to' => []]);
    }

    mp_json([]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    mp_error('invalid_request', 'Method not allowed', 405);
}

// ── POST: create a new post ─────────────────────────────────────────────────

mp_authenticate($db, $config);

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$properties  = [];
$photoFiles  = [];

if (stripos($contentType, 'application/json') !== false) {
    $body = json_decode($_mpRawBody, true);
    if (!is_array($body)) {
        mp_error('invalid_request', 'Malformed JSON body');
    }

    $type = $body['type'][0] ?? null;
    if ($type !== 'h-entry') {
        mp_error('invalid_request', 'Only h-entry is supported');
    }

    $rawProps = $body['properties'] ?? [];
    if (!is_array($rawProps)) {
        mp_error('invalid_request', 'properties must be an object');
    }

    foreach ($rawProps as $key => $value) {
        $properties[$key] = is_array($value) ? array_values($value) : [$value];
    }
} else {
    if (($_POST['h'] ?? '') !== 'entry') {
        mp_error('invalid_request', 'Only h=entry is supported');
    }

    foreach ($_POST as $key => $value) {
        if ($key === 'h' || $key === 'access_token') continue;
        $properties[$key] = is_array($value) ? array_values($value) : [(string) $value];
    }

    if (!empty($_FILES['photo'])) {
        $f = $_FILES['photo'];
        if (is_array($f['name'])) {
            $count = count($f['name']);
            for ($i = 0; $i < $count; $i++) {
                if ($f['error'][$i] === UPLOAD_ERR_OK) {
                    $photoFiles[] = [
                        'name'     => $f['name'][$i],
                        'tmp_name' => $f['tmp_name'][$i],
                        'size'     => (int) $f['size'][$i],
                        'error'    => (int) $f['error'][$i],
                    ];
                }
            }
        } elseif ($f['error'] === UPLOAD_ERR_OK) {
            $photoFiles[] = $f;
        }
    }
}

// ── Property accessors ──────────────────────────────────────────────────────

function mp_first(array $properties, string $key, string $default = ''): string
{
    $val = $properties[$key][0] ?? null;
    if ($val === null) {
        return $default;
    }
    if (is_array($val)) {
        // {markdown: …} | {html: …} | {value: …, html: …}
        $picked = $val['markdown'] ?? $val['html'] ?? $val['value'] ?? '';
        return is_string($picked) ? $picked : $default;
    }
    return (string) $val;
}

$title      = trim(mp_first($properties, 'name'));
$content    = mp_first($properties, 'content');
$slugInput  = trim(mp_first($properties, 'mp-slug'));
$published  = trim(mp_first($properties, 'published'));
$postStatus = trim(mp_first($properties, 'post-status'));
$categories = isset($properties['category']) && is_array($properties['category'])
    ? array_values(array_filter(array_map('strval', $properties['category']), fn($c) => $c !== ''))
    : [];

if ($content === '' && empty($photoFiles)) {
    mp_error('invalid_request', 'content or photo is required');
}

// ── Photos: upload and prepend Markdown image lines ─────────────────────────

if (!empty($photoFiles)) {
    $mediaService  = new \CMS\Media($db, $config['paths']['content'] . '/media');
    $imageMarkdown = '';
    foreach ($photoFiles as $photo) {
        try {
            $result        = $mediaService->upload($photo);
            $imageMarkdown .= '![](' . $result['url'] . ")\n\n";
        } catch (\RuntimeException $e) {
            mp_error('invalid_request', 'photo upload failed: ' . $e->getMessage(), 422);
        }
    }
    $content = $imageMarkdown . $content;
}

// ── Title fallback ──────────────────────────────────────────────────────────

if ($title === '') {
    $plain = trim((string) preg_replace('/\s+/', ' ', strip_tags($content)));
    if ($plain !== '') {
        $title = mb_substr($plain, 0, 80);
        if (mb_strlen($plain) > 80) {
            $title = rtrim($title) . '…';
        }
    }
}
if ($title === '') {
    $title = 'Untitled';
}

// ── Slug + uniqueness ───────────────────────────────────────────────────────

$slug = \CMS\Helpers::slugify($slugInput !== '' ? $slugInput : $title);
$base = $slug;
$n    = 2;
while (\CMS\Post::findBySlug($db, $slug) !== null) {
    $slug = $base . '-' . $n++;
}

// ── Status + published_at ───────────────────────────────────────────────────

$publishTs = $published !== '' ? strtotime($published) : false;
$now       = time();

if ($postStatus === 'draft') {
    $status      = 'draft';
    $publishedAt = $publishTs !== false ? date('Y-m-d H:i:s', $publishTs) : null;
} elseif ($publishTs !== false && $publishTs > $now) {
    $status      = 'scheduled';
    $publishedAt = date('Y-m-d H:i:s', $publishTs);
} else {
    $status      = 'published';
    $publishedAt = date('Y-m-d H:i:s', $publishTs !== false ? $publishTs : $now);
}

// ── Resolve categories: existing-category-slug → category, else → tag ───────

$categoryIds = [];
$tagIds      = [];
foreach ($categories as $cat) {
    $catSlug = \CMS\Helpers::slugify($cat);
    if ($catSlug === '' || $catSlug === 'untitled') continue;

    $existingCat = $db->selectOne('SELECT id FROM categories WHERE slug = :slug', [':slug' => $catSlug]);
    if ($existingCat) {
        $categoryIds[] = (int) $existingCat['id'];
        continue;
    }

    $existingTag = $db->selectOne('SELECT id FROM tags WHERE slug = :slug', [':slug' => $catSlug]);
    if ($existingTag) {
        $tagIds[] = (int) $existingTag['id'];
    } else {
        $tagIds[] = (int) $db->insert('tags', ['name' => $cat, 'slug' => $catSlug]);
    }
}

// ── Save ────────────────────────────────────────────────────────────────────

$post               = new \CMS\Post($db);
$post->title        = $title;
$post->slug         = $slug;
$post->content      = $content;
$post->status       = $status;
$post->published_at = $publishedAt;

if (!$post->save()) {
    mp_error('server_error', 'Failed to save post', 500);
}

$post->saveTerms($categoryIds, $tagIds);

// ── Build static output + neighbors + shared resources ──────────────────────

if ($status === 'published') {
    $builder->buildPost($post);
    if ($prev = \CMS\Post::findPrev($db, $post)) $builder->buildPost($prev);
    if ($next = \CMS\Post::findNext($db, $post)) $builder->buildPost($next);
    $builder->rebuildSharedResources();
}

// ── Syndicate to Mastodon / Bluesky on first publish ────────────────────────

if ($status === 'published') {
    $cfgTz              = $db->getSetting('timezone', '');
    $mastodonInstance   = $db->getSetting('mastodon_instance');
    $mastodonToken      = $db->getSetting('mastodon_token');
    $hasMastodon        = $mastodonInstance !== '' && $mastodonToken !== '';
    $blueskyHandle      = $db->getSetting('bluesky_handle');
    $blueskyAppPassword = $db->getSetting('bluesky_app_password');
    $hasBluesky         = $blueskyHandle !== '' && $blueskyAppPassword !== '';

    if (($hasMastodon && $post->mastodon_skip === 0) || ($hasBluesky && $post->bluesky_skip === 0)) {
        $postUrl = rtrim($db->getSetting('site_url', ''), '/')
                 . '/' . \CMS\Post::datePath($post->published_at, $post->slug, $cfgTz) . '/';

        $effective = $post->effectiveExcerpt();
        $excerpt   = $effective !== null
            ? strip_tags($effective)
            : \CMS\Helpers::truncate($post->content, 280);

        if ($hasMastodon && $post->mastodon_skip === 0 && $post->tooted_at === null) {
            $mastodon = new \CMS\Mastodon($mastodonInstance, $mastodonToken);
            if ($tootUrl = $mastodon->tootPost($post->title, $excerpt, $postUrl)) {
                $post->markTooted($tootUrl);
            }
        }
        if ($hasBluesky && $post->bluesky_skip === 0 && $post->bluesky_at === null) {
            $bluesky = new \CMS\Bluesky($blueskyHandle, $blueskyAppPassword);
            if ($bskyUrl = $bluesky->postToBluesky($post->title, $excerpt, $postUrl)) {
                $post->markBluesky($bskyUrl);
            }
        }
    }
}

// ── Activity log ────────────────────────────────────────────────────────────

$logAction = match ($status) {
    'published' => 'publish',
    'scheduled' => 'schedule',
    default     => 'create',
};
$activityLog->log($logAction, 'post', $post->id, $post->title . ' (via micropub)');

// ── Response ────────────────────────────────────────────────────────────────

$siteUrl  = rtrim($db->getSetting('site_url', ''), '/');
$cfgTz    = $db->getSetting('timezone', '');
$location = $publishedAt !== null && $siteUrl !== ''
    ? $siteUrl . '/' . \CMS\Post::datePath($publishedAt, $post->slug, $cfgTz) . '/'
    : ($siteUrl !== '' ? $siteUrl . '/admin/post-edit.php?id=' . $post->id : '/admin/post-edit.php?id=' . $post->id);

http_response_code(201);
header('Location: ' . $location);
exit;
