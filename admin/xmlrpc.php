<?php

declare(strict_types=1);

/**
 * MetaWeblog XML-RPC API endpoint.
 *
 * MarsEdit setup:
 *   API type    : MetaWeblog
 *   Endpoint URL: https://example.com/admin/xmlrpc.php
 *   Username    : admin username from config.php
 *   Password    : admin plaintext password
 *
 * Supported methods:
 *   blogger.getUsersBlogs
 *   metaWeblog.getRecentPosts
 *   metaWeblog.getPost
 *   metaWeblog.newPost
 *   metaWeblog.editPost
 *   metaWeblog.deletePost
 *   metaWeblog.getCategories
 *   metaWeblog.newMediaObject
 *
 * Pages are not exposed here — MetaWeblog covers posts only.
 */

require __DIR__ . '/bootstrap.php';
// Note: $auth->check() is intentionally NOT called.
// XML-RPC authenticates per-request via the username/password params.

use CMS\Bluesky;
use CMS\Helpers;
use CMS\Mastodon;
use CMS\Post;
use CMS\XmlRpc;

// ── Output setup ──────────────────────────────────────────────────────────────

header('Content-Type: text/xml; charset=utf-8');

function xmlrpc_fault(int $code, string $msg): never
{
    echo XmlRpc::encodeFault($code, $msg);
    exit;
}

// ── Only accept POST ──────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    xmlrpc_fault(405, 'Method Not Allowed');
}

// ── Parse request ─────────────────────────────────────────────────────────────

$body = file_get_contents('php://input');
try {
    $req = XmlRpc::parseRequest($body ?: '');
} catch (\Throwable) {
    xmlrpc_fault(400, 'Bad Request: invalid XML');
}

$method = $req['method'];
$params = $req['params'];

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Authenticate username + password from XML-RPC params.
 * Records the attempt in login_attempts for rate-limit purposes.
 */
function xmlrpc_auth(array $params, int $userIdx, int $passIdx): void
{
    global $db, $auth, $config;

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if ($auth->isLockedOut($ip)) {
        xmlrpc_fault(429, 'Too many failed login attempts. Try again later.');
    }

    $username = (string) ($params[$userIdx] ?? '');
    $password = (string) ($params[$passIdx] ?? '');

    $cfgUser = $config['admin']['username'] ?? '';
    $cfgHash = $config['admin']['password_hash'] ?? '';

    $ok = ($username === $cfgUser) && password_verify($password, $cfgHash);

    // Record the attempt (mirrors what Auth::login() does internally).
    $db->insert('login_attempts', [
        'ip'           => $ip,
        'success'      => $ok ? 1 : 0,
        'attempted_at' => date('Y-m-d H:i:s'),
    ]);

    if (!$ok) {
        xmlrpc_fault(403, 'Forbidden: incorrect username or password.');
    }
}

/**
 * Build the struct array that MarsEdit expects for a single post.
 */
function postToStruct(Post $post, string $siteUrl): array
{
    $pubAt  = $post->published_at ?? $post->created_at;
    $url    = rtrim($siteUrl, '/') . '/' . Post::datePath($pubAt, $post->slug) . '/';

    return [
        'postid'      => (string) $post->id,
        'userid'      => '1',
        'title'       => $post->title,
        'description' => $post->content,
        'mt_excerpt'  => $post->excerpt ?? '',
        'wp_slug'     => $post->slug,
        'dateCreated' => XmlRpc::isoDate($pubAt),
        'link'        => $url,
        'permaLink'   => $url,
        'categories'  => [],
        'post_status' => $post->status,
    ];
}

/**
 * Apply a MetaWeblog content struct onto a Post object.
 * Mutates $post in place; does not save.
 */
function applyStruct(Post $post, array $struct, bool $publish, string $timezone): void
{
    global $db;

    // Title
    if (isset($struct['title'])) {
        $post->title = trim((string) $struct['title']);
    }

    // Body
    if (isset($struct['description'])) {
        $post->content = (string) $struct['description'];
    }

    // Excerpt
    if (array_key_exists('mt_excerpt', $struct)) {
        $ex = trim((string) $struct['mt_excerpt']);
        $post->excerpt = $ex !== '' ? $ex : null;
    }

    // Slug
    $rawSlug = trim((string) ($struct['wp_slug'] ?? ''));
    if ($rawSlug === '' && $post->slug === '') {
        $rawSlug = $post->title;
    }
    if ($rawSlug !== '') {
        $base = Helpers::slugify($rawSlug);
        // Collision check: find a unique slug for a different post.
        $candidate = $base;
        $suffix    = 2;
        while (true) {
            $existing = Post::findBySlug($db, $candidate);
            if ($existing === null || $existing->id === $post->id) {
                break;
            }
            $candidate = $base . '-' . $suffix++;
        }
        $post->slug = $candidate;
    }

    // post_status field overrides $publish for draft detection.
    $postStatus = strtolower(trim((string) ($struct['post_status'] ?? '')));
    if ($postStatus === 'draft') {
        $publish = false;
    }

    // Date
    $rawDate = trim((string) ($struct['dateCreated'] ?? ''));
    $pubAt   = $rawDate !== '' ? XmlRpc::parseDate($rawDate, $timezone) : null;

    // Status
    if (!$publish) {
        $post->status = 'draft';
        // Preserve published_at if already set; update if caller supplied one.
        if ($pubAt !== null) {
            $post->published_at = $pubAt;
        }
    } else {
        $effectivePubAt = $pubAt ?? date('Y-m-d H:i:s');
        $post->published_at = $effectivePubAt;
        $post->status = strtotime($effectivePubAt) > time() ? 'scheduled' : 'published';
    }
}

/**
 * Syndicate a newly-published post to Mastodon and/or Bluesky.
 * Mirrors the first-publish logic in admin/post-edit.php.
 * Only fires when status = 'published' and the post has not already been shared.
 */
function syndicatePost(Post $post): void
{
    global $db, $siteUrl, $hasMastodon, $mastodonInstance, $mastodonToken,
           $hasBluesky, $blueskyHandle, $blueskyAppPassword;

    if ($post->status !== 'published') {
        return;
    }

    $postUrl = rtrim($siteUrl, '/') . '/' . Post::datePath($post->published_at, $post->slug) . '/';
    $excerpt = ($post->effectiveExcerpt() !== null)
        ? strip_tags($post->effectiveExcerpt())
        : Helpers::truncate($post->content, 280);

    if ($hasMastodon && $post->tooted_at === null && $post->mastodon_skip === 0) {
        $mastodon = new Mastodon($mastodonInstance, $mastodonToken);
        if ($mastodon->tootPost($post->title, $excerpt, $postUrl)) {
            $post->markTooted();
        }
    }

    if ($hasBluesky && $post->bluesky_at === null && $post->bluesky_skip === 0) {
        $bluesky = new Bluesky($blueskyHandle, $blueskyAppPassword);
        if ($bluesky->postToBluesky($post->title, $excerpt, $postUrl)) {
            $post->markBluesky();
        }
    }
}

/**
 * Run the appropriate Builder calls after saving a post.
 * Mirrors the logic in admin/post-edit.php.
 */
function rebuildPost(Post $post, bool $wasPublished = false): void
{
    global $builder;

    if ($post->status === 'published') {
        $builder->buildPost($post);
        $builder->buildIndex();
        $builder->buildFeed();
    } elseif ($wasPublished) {
        // Was published, now draft/scheduled — remove the static file.
        $builder->buildPost($post);
        $builder->buildIndex();
        $builder->buildFeed();
    } else {
        // Draft/scheduled save — no public file needed.
        $builder->buildPost($post);
    }
}

// ── Site-wide settings (used by multiple handlers) ────────────────────────────

$siteUrl   = $db->getSetting('site_url', '');
$siteTitle = $db->getSetting('site_title', 'My CMS');
$timezone  = $db->getSetting('timezone', '');

$mastodonInstance   = $db->getSetting('mastodon_instance', '');
$mastodonToken      = $db->getSetting('mastodon_token', '');
$hasMastodon        = $mastodonInstance !== '' && $mastodonToken !== '';

$blueskyHandle      = $db->getSetting('bluesky_handle', '');
$blueskyAppPassword = $db->getSetting('bluesky_app_password', '');
$hasBluesky         = $blueskyHandle !== '' && $blueskyAppPassword !== '';

// ── Method dispatch ───────────────────────────────────────────────────────────

switch ($method) {

    // ── blogger.getUsersBlogs(appkey, username, password) ─────────────────────
    case 'blogger.getUsersBlogs':
        xmlrpc_auth($params, 1, 2);
        echo XmlRpc::encodeResponse([[
            'blogid'   => '1',
            'blogName' => $siteTitle,
            'url'      => $siteUrl,
        ]]);
        break;

    // ── metaWeblog.getRecentPosts(blogid, username, password, numberOfPosts) ──
    case 'metaWeblog.getRecentPosts':
        xmlrpc_auth($params, 1, 2);
        $limit = max(1, (int) ($params[3] ?? 20));
        $all   = Post::findAll($db, 'published');
        $posts = array_slice($all, 0, $limit);
        $structs = array_map(fn($p) => postToStruct($p, $siteUrl), $posts);
        echo XmlRpc::encodeResponse($structs);
        break;

    // ── metaWeblog.getPost(postid, username, password) ────────────────────────
    case 'metaWeblog.getPost':
        xmlrpc_auth($params, 1, 2);
        $post = Post::findById($db, (int) ($params[0] ?? 0));
        if ($post === null) {
            xmlrpc_fault(404, 'Post not found.');
        }
        echo XmlRpc::encodeResponse(postToStruct($post, $siteUrl));
        break;

    // ── metaWeblog.newPost(blogid, username, password, struct, publish) ───────
    case 'metaWeblog.newPost':
        xmlrpc_auth($params, 1, 2);
        $struct  = (array) ($params[3] ?? []);
        $publish = (bool) ($params[4] ?? false);

        $post = new Post($db);
        applyStruct($post, $struct, $publish, $timezone);

        if ($post->title === '') {
            xmlrpc_fault(400, 'Title is required.');
        }

        $post->save();
        syndicatePost($post);
        rebuildPost($post);

        echo XmlRpc::encodeResponse((string) $post->id);
        break;

    // ── metaWeblog.editPost(postid, username, password, struct, publish) ──────
    case 'metaWeblog.editPost':
        xmlrpc_auth($params, 1, 2);
        $post = Post::findById($db, (int) ($params[0] ?? 0));
        if ($post === null) {
            xmlrpc_fault(404, 'Post not found.');
        }
        $struct      = (array) ($params[3] ?? []);
        $publish     = (bool) ($params[4] ?? false);
        $wasPublished = $post->status === 'published';

        applyStruct($post, $struct, $publish, $timezone);

        if ($post->title === '') {
            xmlrpc_fault(400, 'Title is required.');
        }

        $post->save();
        syndicatePost($post);
        rebuildPost($post, $wasPublished);

        echo XmlRpc::encodeResponse(true);
        break;

    // ── metaWeblog.deletePost(appkey, postid, username, password, publish) ────
    case 'metaWeblog.deletePost':
    case 'blogger.deletePost':
        xmlrpc_auth($params, 2, 3);
        $post = Post::findById($db, (int) ($params[1] ?? 0));
        if ($post === null) {
            xmlrpc_fault(404, 'Post not found.');
        }
        $wasPublished = $post->status === 'published';
        $post->delete();
        // Set to draft so buildPost() removes the static file rather than rebuilding.
        $post->status = 'draft';
        $builder->buildPost($post);
        if ($wasPublished) {
            $builder->buildIndex();
            $builder->buildFeed();
        }
        echo XmlRpc::encodeResponse(true);
        break;

    // ── metaWeblog.getCategories(blogid, username, password) ─────────────────
    case 'metaWeblog.getCategories':
        xmlrpc_auth($params, 1, 2);
        // No category system in this CMS.
        echo XmlRpc::encodeResponse([]);
        break;

    // ── metaWeblog.newMediaObject(blogid, username, password, struct) ─────────
    case 'metaWeblog.newMediaObject':
        xmlrpc_auth($params, 1, 2);
        $struct = (array) ($params[3] ?? []);

        $originalName = trim((string) ($struct['name'] ?? ''));
        $mimeType     = trim((string) ($struct['type'] ?? ''));
        $bits         = $struct['bits'] ?? ''; // already base64-decoded by parseRequest

        if ($originalName === '' || !is_string($bits) || $bits === '') {
            xmlrpc_fault(400, 'Invalid media object: name and bits are required.');
        }

        // MIME whitelist (mirrors Media.php)
        $allowed = [
            'image/jpeg'     => 'jpg',
            'image/png'      => 'png',
            'image/gif'      => 'gif',
            'image/webp'     => 'webp',
            'image/svg+xml'  => 'svg',
            'video/mp4'      => 'mp4',
            'video/webm'     => 'webm',
            'audio/mpeg'     => 'mp3',
            'audio/ogg'      => 'ogg',
        ];

        // Detect MIME from extension if type is blank or unrecognised.
        if (!isset($allowed[$mimeType])) {
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $mimeType = array_search($ext, $allowed, true) ?: '';
        }

        if (!isset($allowed[$mimeType])) {
            xmlrpc_fault(400, 'Unsupported file type.');
        }

        $ext = $allowed[$mimeType];

        // Build safe filename: {stem}_{8hex}.{ext}  (mirrors Media.php)
        $stem = strtolower(pathinfo($originalName, PATHINFO_FILENAME));
        $stem = preg_replace('/[^a-z0-9_-]+/', '-', $stem);
        $stem = trim($stem, '-');
        $stem = mb_substr($stem !== '' ? $stem : 'file', 0, 60);

        $mediaDir = $config['paths']['content'] . '/media';
        if (!is_dir($mediaDir)) {
            mkdir($mediaDir, 0775, true);
        }

        $filename = '';
        for ($i = 0; $i < 10; $i++) {
            $candidate = $stem . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!file_exists($mediaDir . '/' . $candidate)) {
                $filename = $candidate;
                break;
            }
        }

        if ($filename === '') {
            xmlrpc_fault(500, 'Could not generate a unique filename.');
        }

        if (file_put_contents($mediaDir . '/' . $filename, $bits) === false) {
            xmlrpc_fault(500, 'Failed to write media file.');
        }

        $db->insert('media', [
            'filename'      => $filename,
            'original_name' => $originalName,
            'mime_type'     => $mimeType,
            'size'          => strlen($bits),
            'uploaded_at'   => date('Y-m-d H:i:s'),
        ]);

        $url = rtrim($siteUrl, '/') . '/media/' . $filename;
        echo XmlRpc::encodeResponse(['url' => $url]);
        break;

    // ── Unknown method ────────────────────────────────────────────────────────
    default:
        xmlrpc_fault(404, 'Unknown method: ' . $method);
}
