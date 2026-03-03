<?php

declare(strict_types=1);

/**
 * WordPress + MetaWeblog XML-RPC API endpoint.
 *
 * MarsEdit setup (WordPress mode — recommended, enables page management):
 *   API type    : WordPress
 *   Endpoint URL: https://example.com/admin/xmlrpc.php
 *   Username    : admin username from config.php
 *   Password    : admin plaintext password
 *
 * MarsEdit setup (MetaWeblog mode — posts only, no pages):
 *   API type    : MetaWeblog
 *   Endpoint URL: https://example.com/admin/xmlrpc.php
 *
 * Supported methods:
 *   WordPress API  : wp.getUsersBlogs, wp.getOptions, wp.getAuthors,
 *                    wp.getPostFormats, wp.getTaxonomies, wp.getTerms,
 *                    wp.getPosts, wp.getPost, wp.newPost, wp.editPost, wp.deletePost,
 *                    wp.getPages, wp.getPage, wp.newPage, wp.editPage, wp.deletePage,
 *                    wp.getMediaLibrary, wp.uploadFile
 *   MetaWeblog API : blogger.getUsersBlogs, metaWeblog.getRecentPosts,
 *                    metaWeblog.getPost, metaWeblog.newPost, metaWeblog.editPost,
 *                    metaWeblog.deletePost, metaWeblog.getCategories,
 *                    metaWeblog.newMediaObject
 */

// Read the raw request body BEFORE bootstrap.php starts a session or does
// anything that could consume php://input on some PHP-FPM configurations.
$_xmlrpcBody = file_get_contents('php://input');

require __DIR__ . '/bootstrap.php';
// Note: $auth->check() is intentionally NOT called.
// XML-RPC authenticates per-request via the username/password params.

use CMS\Bluesky;
use CMS\Helpers;
use CMS\Mastodon;
use CMS\Page;
use CMS\Post;
use CMS\XmlRpc;

// ── Output setup ──────────────────────────────────────────────────────────────

header('Content-Type: text/xml; charset=utf-8');

function xmlrpc_fault(int $code, string $msg): never
{
    echo XmlRpc::encodeFault($code, $msg);
    exit;
}

// Guard: simplexml is in the php8.3-xml package (separate from php8.3-fpm).
if (!function_exists('simplexml_load_string')) {
    xmlrpc_fault(500, 'Server error: simplexml extension not installed (apt install php8.3-xml).');
}

// ── Only accept POST ──────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    xmlrpc_fault(405, 'Method Not Allowed');
}

// ── Parse request ─────────────────────────────────────────────────────────────

$body = $_xmlrpcBody;
unset($_xmlrpcBody);
try {
    $req = XmlRpc::parseRequest($body ?: '');
} catch (\Throwable $e) {
    xmlrpc_fault(400, 'Bad Request: ' . $e->getMessage());
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
 * Save binary media data to content/media/ and register it in the DB.
 * Mirrors the filename pattern from Media.php: {stem}_{8hex}.{ext}
 * Returns ['url' => string] struct on success; calls xmlrpc_fault() on error.
 */
function xmlrpc_save_media(string $originalName, string $mimeType, string $bits): array
{
    global $db, $config, $siteUrl;

    // MIME whitelist (mirrors Media.php)
    $allowed = [
        'image/jpeg'    => 'jpg',
        'image/png'     => 'png',
        'image/gif'     => 'gif',
        'image/webp'    => 'webp',
        'image/svg+xml' => 'svg',
        'video/mp4'     => 'mp4',
        'video/webm'    => 'webm',
        'audio/mpeg'    => 'mp3',
        'audio/ogg'     => 'ogg',
    ];

    if (!isset($allowed[$mimeType])) {
        $ext      = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mimeType = array_search($ext, $allowed, true) ?: '';
    }

    if (!isset($allowed[$mimeType])) {
        xmlrpc_fault(400, 'Unsupported file type.');
    }

    $ext      = $allowed[$mimeType];
    $stem     = strtolower(pathinfo($originalName, PATHINFO_FILENAME));
    $stem     = preg_replace('/[^a-z0-9_-]+/', '-', $stem);
    $stem     = trim($stem, '-');
    $stem     = mb_substr($stem !== '' ? $stem : 'file', 0, 60);

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

    return ['url' => rtrim($siteUrl, '/') . '/media/' . $filename];
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

// ── WordPress API helpers ─────────────────────────────────────────────────────

/** Map CMS status → WordPress status string */
function wpStatus(string $s): string
{
    return match ($s) {
        'published' => 'publish',
        'scheduled' => 'future',
        default     => 'draft',
    };
}

/** Map WordPress status string → CMS status */
function cmsStatusFromWp(string $s): string
{
    return match ($s) {
        'publish' => 'published',
        'future'  => 'scheduled',
        default   => 'draft',
    };
}

/**
 * Build the WordPress-style struct for a single post.
 */
function wpPostToStruct(Post $post, string $siteUrl): array
{
    $pubAt = $post->published_at ?? $post->created_at;
    $url   = rtrim($siteUrl, '/') . '/' . Post::datePath($pubAt, $post->slug) . '/';

    return [
        'post_id'           => (string) $post->id,
        'post_title'        => $post->title,
        'post_content'      => $post->content,
        'post_excerpt'      => $post->excerpt ?? '',
        'post_name'         => $post->slug,
        'post_status'       => wpStatus($post->status),
        'post_type'         => 'post',
        'post_date'         => XmlRpc::isoDate($pubAt),
        'post_date_gmt'     => XmlRpc::isoDate($pubAt),
        'post_modified'     => XmlRpc::isoDate($post->updated_at),
        'post_modified_gmt' => XmlRpc::isoDate($post->updated_at),
        'link'              => $url,
        'guid'              => $url,
        'post_author'       => '1',
        'comment_status'    => 'closed',
        'ping_status'       => 'closed',
        'post_format'       => 'standard',
        'post_thumbnail'    => '',
        'terms'             => [],
        'custom_fields'     => [],
    ];
}

/**
 * Apply a WordPress-style content struct onto a Post object.
 * Mutates $post in place; does not save.
 */
function applyWpPostStruct(Post $post, array $struct, string $timezone): void
{
    global $db;

    if (isset($struct['post_title'])) {
        $post->title = trim((string) $struct['post_title']);
    }

    if (isset($struct['post_content'])) {
        $post->content = (string) $struct['post_content'];
    }

    if (array_key_exists('post_excerpt', $struct)) {
        $ex = trim((string) $struct['post_excerpt']);
        $post->excerpt = $ex !== '' ? $ex : null;
    }

    // Slug — auto-generate from title when absent; collision-check same as applyStruct().
    $rawSlug = trim((string) ($struct['post_name'] ?? ''));
    if ($rawSlug === '' && $post->slug === '') {
        $rawSlug = $post->title;
    }
    if ($rawSlug !== '') {
        $base      = Helpers::slugify($rawSlug);
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

    // Date
    $rawDate = trim((string) ($struct['post_date'] ?? ''));
    $pubAt   = $rawDate !== '' ? XmlRpc::parseDate($rawDate, $timezone) : null;

    // Status
    $wpStat = strtolower(trim((string) ($struct['post_status'] ?? 'publish')));

    if ($wpStat === 'draft') {
        $post->status = 'draft';
        if ($pubAt !== null) {
            $post->published_at = $pubAt;
        }
    } else {
        // 'publish', 'future', or anything else → attempt to publish/schedule.
        $effectivePubAt     = $pubAt ?? date('Y-m-d H:i:s');
        $post->published_at = $effectivePubAt;
        $post->status       = strtotime($effectivePubAt) > time() ? 'scheduled' : 'published';
    }
}

/**
 * Build the WordPress-style struct for a single page.
 */
function wpPageToStruct(Page $page, string $siteUrl): array
{
    $url = rtrim($siteUrl, '/') . '/' . $page->slug . '/';

    return [
        'post_id'           => (string) $page->id,
        'post_title'        => $page->title,
        'post_content'      => $page->content,
        'post_name'         => $page->slug,
        'post_status'       => $page->status === 'published' ? 'publish' : 'draft',
        'post_type'         => 'page',
        'post_date'         => XmlRpc::isoDate($page->created_at),
        'post_date_gmt'     => XmlRpc::isoDate($page->created_at),
        'post_modified'     => XmlRpc::isoDate($page->updated_at),
        'post_modified_gmt' => XmlRpc::isoDate($page->updated_at),
        'link'              => $url,
        'guid'              => $url,
        'post_author'       => '1',
        'menu_order'        => $page->nav_order,
        'comment_status'    => 'closed',
        'ping_status'       => 'closed',
        'custom_fields'     => [],
    ];
}

/**
 * Apply a WordPress-style struct onto a Page object.
 * Mutates $page in place; does not save.
 */
function applyWpPageStruct(Page $page, array $struct): void
{
    global $db;

    if (isset($struct['post_title'])) {
        $page->title = trim((string) $struct['post_title']);
    }

    if (isset($struct['post_content'])) {
        $page->content = (string) $struct['post_content'];
    }

    // Slug
    $rawSlug = trim((string) ($struct['post_name'] ?? ''));
    if ($rawSlug === '' && $page->slug === '') {
        $rawSlug = $page->title;
    }
    if ($rawSlug !== '') {
        $base      = Helpers::slugify($rawSlug);
        $candidate = $base;
        $suffix    = 2;
        while (true) {
            $existing = Page::findBySlug($db, $candidate);
            if ($existing === null || $existing->id === $page->id) {
                break;
            }
            $candidate = $base . '-' . $suffix++;
        }
        $page->slug = $candidate;
    }

    // Status ('publish' → published, anything else → draft; no scheduling for pages)
    $wpStat = strtolower(trim((string) ($struct['post_status'] ?? '')));
    if ($wpStat !== '') {
        $page->status = $wpStat === 'publish' ? 'published' : 'draft';
    }

    // Nav order
    if (array_key_exists('menu_order', $struct)) {
        $page->nav_order = (int) $struct['menu_order'];
    }
}

/**
 * Run the appropriate Builder calls after saving a page.
 * Always rebuilds index when status changes (pages appear in nav).
 */
function rebuildPage(Page $page, bool $wasPublished): void
{
    global $builder;

    $builder->buildPage($page);

    if ($page->status === 'published' || $wasPublished) {
        $builder->buildIndex();
    }
}

// ── Site-wide settings (used by multiple handlers) ────────────────────────────

$siteUrl         = $db->getSetting('site_url', '');
$siteTitle       = $db->getSetting('site_title', 'My CMS');
$siteDescription = $db->getSetting('site_description', '');
$timezone        = $db->getSetting('timezone', '');

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
        $struct       = (array) ($params[3] ?? []);
        $originalName = trim((string) ($struct['name'] ?? ''));
        $mimeType     = trim((string) ($struct['type'] ?? ''));
        $bits         = $struct['bits'] ?? '';

        if ($originalName === '' || !is_string($bits) || $bits === '') {
            xmlrpc_fault(400, 'Invalid media object: name and bits are required.');
        }

        echo XmlRpc::encodeResponse(xmlrpc_save_media($originalName, $mimeType, $bits));
        break;

    // ════════════════════════════════════════════════════════════════════════
    // Movable Type API stubs
    // MarsEdit calls these during blog discovery regardless of API mode.
    // ════════════════════════════════════════════════════════════════════════

    // ── mt.supportedMethods() ────────────────────────────────────────────────
    case 'mt.supportedMethods':
        // No auth required by spec — return list of all implemented methods.
        echo XmlRpc::encodeResponse([
            'blogger.getUsersBlogs',
            'metaWeblog.getRecentPosts', 'metaWeblog.getPost',
            'metaWeblog.newPost', 'metaWeblog.editPost', 'metaWeblog.deletePost',
            'metaWeblog.getCategories', 'metaWeblog.newMediaObject',
            'mt.supportedMethods', 'mt.supportedTextFilters',
            'mt.getCategoryList', 'mt.getPostCategories', 'mt.setPostCategories',
            'wp.getUsersBlogs', 'wp.getOptions', 'wp.getAuthors',
            'wp.getPostFormats', 'wp.getTaxonomies', 'wp.getTerms',
            'wp.getTags',
            'wp.getPosts', 'wp.getPost', 'wp.newPost', 'wp.editPost', 'wp.deletePost',
            'wp.getPages', 'wp.getPage', 'wp.newPage', 'wp.editPage', 'wp.deletePage',
            'wp.getMediaLibrary', 'wp.uploadFile',
        ]);
        break;

    // ── mt.supportedTextFilters(blogid, username, password) ──────────────────
    case 'mt.supportedTextFilters':
        xmlrpc_auth($params, 1, 2);
        echo XmlRpc::encodeResponse([]);
        break;

    // ── mt.getCategoryList(blogid, username, password) ────────────────────────
    case 'mt.getCategoryList':
        xmlrpc_auth($params, 1, 2);
        echo XmlRpc::encodeResponse([]);
        break;

    // ── mt.getPostCategories(postid, username, password) ─────────────────────
    case 'mt.getPostCategories':
        xmlrpc_auth($params, 1, 2);
        echo XmlRpc::encodeResponse([]);
        break;

    // ── mt.setPostCategories(postid, username, password, categories) ──────────
    case 'mt.setPostCategories':
        xmlrpc_auth($params, 1, 2);
        echo XmlRpc::encodeResponse(true);
        break;

    // ════════════════════════════════════════════════════════════════════════
    // WordPress XML-RPC API
    // ════════════════════════════════════════════════════════════════════════

    // ── wp.getUsersBlogs(appkey, username, password) ──────────────────────────
    case 'wp.getUsersBlogs':
        xmlrpc_auth($params, 1, 2);
        echo XmlRpc::encodeResponse([[
            'blogid'   => '1',
            'blogName' => $siteTitle,
            'url'      => $siteUrl,
            'xmlrpc'   => rtrim($siteUrl, '/') . '/admin/xmlrpc.php',
            'isAdmin'  => true,
        ]]);
        break;

    // ── wp.getOptions(blogid, username, password[, options]) ──────────────────
    case 'wp.getOptions':
        xmlrpc_auth($params, 1, 2);
        $opt = fn(string $desc, bool $ro, string $val): array =>
            ['desc' => $desc, 'readonly' => $ro, 'value' => $val];
        echo XmlRpc::encodeResponse([
            'software_name'    => $opt('Software Name',    true,  'WordPress'),
            'software_version' => $opt('Software Version', true,  '6.4'),
            'blog_url'         => $opt('Blog URL',         true,  $siteUrl),
            'home_url'         => $opt('Home URL',         true,  $siteUrl),
            'blog_title'       => $opt('Blog Title',       false, $siteTitle),
            'blog_tagline'     => $opt('Tagline',          false, $siteDescription),
            'time_zone'        => $opt('Time Zone',        true,  $timezone),
            'date_format'      => $opt('Date Format',      true,  'Y-m-d'),
            'time_format'      => $opt('Time Format',      true,  'H:i:s'),
            'upload_path'      => $opt('Upload Path',      true,  '/media/'),
            'thumbnail_size_w' => $opt('Thumbnail Width',  true,  '150'),
            'thumbnail_size_h' => $opt('Thumbnail Height', true,  '150'),
        ]);
        break;

    // ── wp.getAuthors(blogid, username, password) ─────────────────────────────
    case 'wp.getAuthors':
        xmlrpc_auth($params, 1, 2);
        echo XmlRpc::encodeResponse([[
            'user_id'      => '1',
            'user_login'   => $config['admin']['username'] ?? '',
            'display_name' => $config['admin']['username'] ?? '',
            'user_email'   => '',
            'meta_value'   => '',
        ]]);
        break;

    // ── wp.getPostFormats(blogid, username, password[, filter]) ───────────────
    case 'wp.getPostFormats':
        xmlrpc_auth($params, 1, 2);
        echo XmlRpc::encodeResponse(['standard' => 'Standard']);
        break;

    // ── wp.getTaxonomies(blogid, username, password) ──────────────────────────
    case 'wp.getTaxonomies':
        xmlrpc_auth($params, 1, 2);
        echo XmlRpc::encodeResponse([]);
        break;

    // ── wp.getTerms(blogid, username, password, taxonomy[, filter]) ───────────
    case 'wp.getTerms':
        xmlrpc_auth($params, 1, 2);
        echo XmlRpc::encodeResponse([]);
        break;

    // ── wp.getTags(blogid, username, password) ────────────────────────────────
    case 'wp.getTags':
        xmlrpc_auth($params, 1, 2);
        echo XmlRpc::encodeResponse([]);
        break;

    // ── wp.getPosts(blogid, username, password[, filter]) ─────────────────────
    case 'wp.getPosts':
        xmlrpc_auth($params, 1, 2);
        $filter    = (array) ($params[3] ?? []);
        $wpStat    = strtolower(trim((string) ($filter['post_status'] ?? 'any')));
        $limit     = max(1, (int) ($filter['number'] ?? 20));
        $status    = ($wpStat === 'any' || $wpStat === '') ? null : cmsStatusFromWp($wpStat);
        $all       = Post::findAll($db, $status);
        $sliced    = array_slice($all, (int) ($filter['offset'] ?? 0), $limit);
        echo XmlRpc::encodeResponse(array_map(fn($p) => wpPostToStruct($p, $siteUrl), $sliced));
        break;

    // ── wp.getPost(blogid, username, password, post_id) ───────────────────────
    case 'wp.getPost':
        xmlrpc_auth($params, 1, 2);
        $post = Post::findById($db, (int) ($params[3] ?? 0));
        if ($post === null) {
            xmlrpc_fault(404, 'Post not found.');
        }
        echo XmlRpc::encodeResponse(wpPostToStruct($post, $siteUrl));
        break;

    // ── wp.newPost(blogid, username, password, content) ───────────────────────
    case 'wp.newPost':
        xmlrpc_auth($params, 1, 2);
        $struct = (array) ($params[3] ?? []);

        $post = new Post($db);
        applyWpPostStruct($post, $struct, $timezone);

        if ($post->title === '') {
            xmlrpc_fault(400, 'Title is required.');
        }

        $post->save();
        syndicatePost($post);
        rebuildPost($post);

        echo XmlRpc::encodeResponse((string) $post->id);
        break;

    // ── wp.editPost(blogid, username, password, post_id, content) ────────────
    case 'wp.editPost':
        xmlrpc_auth($params, 1, 2);
        $post = Post::findById($db, (int) ($params[3] ?? 0));
        if ($post === null) {
            xmlrpc_fault(404, 'Post not found.');
        }
        $wasPublished = $post->status === 'published';
        $struct       = (array) ($params[4] ?? []);

        applyWpPostStruct($post, $struct, $timezone);

        if ($post->title === '') {
            xmlrpc_fault(400, 'Title is required.');
        }

        $post->save();
        syndicatePost($post);
        rebuildPost($post, $wasPublished);

        echo XmlRpc::encodeResponse(true);
        break;

    // ── wp.deletePost(blogid, username, password, post_id) ────────────────────
    case 'wp.deletePost':
        xmlrpc_auth($params, 1, 2);
        $post = Post::findById($db, (int) ($params[3] ?? 0));
        if ($post === null) {
            xmlrpc_fault(404, 'Post not found.');
        }
        $wasPublished = $post->status === 'published';
        $post->delete();
        $post->status = 'draft';
        $builder->buildPost($post);
        if ($wasPublished) {
            $builder->buildIndex();
            $builder->buildFeed();
        }
        echo XmlRpc::encodeResponse(true);
        break;

    // ── wp.getPages(blogid, username, password[, num_pages]) ──────────────────
    case 'wp.getPages':
        xmlrpc_auth($params, 1, 2);
        $limit = isset($params[3]) ? max(1, (int) $params[3]) : PHP_INT_MAX;
        $all   = Page::findAll($db);        // all statuses — MarsEdit needs drafts
        $pages = array_slice($all, 0, $limit);
        echo XmlRpc::encodeResponse(array_map(fn($pg) => wpPageToStruct($pg, $siteUrl), $pages));
        break;

    // ── wp.getPage(blogid, username, password, page_id) ───────────────────────
    case 'wp.getPage':
        xmlrpc_auth($params, 1, 2);
        $page = Page::findById($db, (int) ($params[3] ?? 0));
        if ($page === null) {
            xmlrpc_fault(404, 'Page not found.');
        }
        echo XmlRpc::encodeResponse(wpPageToStruct($page, $siteUrl));
        break;

    // ── wp.newPage(blogid, username, password, content[, publish]) ────────────
    case 'wp.newPage':
        xmlrpc_auth($params, 1, 2);
        $struct  = (array) ($params[3] ?? []);
        $publish = (bool) ($params[4] ?? true);

        $page = new Page($db);
        applyWpPageStruct($page, $struct);

        // Honour explicit $publish=false even if struct says 'publish'.
        if (!$publish) {
            $page->status = 'draft';
        }

        if ($page->title === '') {
            xmlrpc_fault(400, 'Title is required.');
        }

        $page->save();
        rebuildPage($page, false);

        echo XmlRpc::encodeResponse((string) $page->id);
        break;

    // ── wp.editPage(blogid, username, password, page_id, content[, publish]) ──
    case 'wp.editPage':
        xmlrpc_auth($params, 1, 2);
        $page = Page::findById($db, (int) ($params[3] ?? 0));
        if ($page === null) {
            xmlrpc_fault(404, 'Page not found.');
        }
        $wasPublished = $page->status === 'published';
        $struct       = (array) ($params[4] ?? []);
        $publish      = isset($params[5]) ? (bool) $params[5] : null;

        applyWpPageStruct($page, $struct);

        // Explicit $publish param overrides struct's post_status.
        if ($publish === false) {
            $page->status = 'draft';
        } elseif ($publish === true) {
            $page->status = 'published';
        }

        if ($page->title === '') {
            xmlrpc_fault(400, 'Title is required.');
        }

        $page->save();
        rebuildPage($page, $wasPublished);

        echo XmlRpc::encodeResponse(true);
        break;

    // ── wp.deletePage(blogid, username, password, page_id) ────────────────────
    case 'wp.deletePage':
        xmlrpc_auth($params, 1, 2);
        $page = Page::findById($db, (int) ($params[3] ?? 0));
        if ($page === null) {
            xmlrpc_fault(404, 'Page not found.');
        }
        $wasPublished = $page->status === 'published';
        $page->delete();
        $page->status = 'draft';
        $builder->buildPage($page);     // removes the static file
        if ($wasPublished) {
            $builder->buildIndex();     // refresh nav on all pages
        }
        echo XmlRpc::encodeResponse(true);
        break;

    // ── wp.getMediaLibrary(blogid, username, password[, filter]) ─────────────
    case 'wp.getMediaLibrary':
        xmlrpc_auth($params, 1, 2);
        $filter  = (array) ($params[3] ?? []);
        $limit   = max(1, (int) ($filter['number'] ?? 20));
        $offset  = max(0, (int) ($filter['offset'] ?? 0));
        $rows    = $db->select(
            "SELECT * FROM media ORDER BY uploaded_at DESC LIMIT :lim OFFSET :off",
            ['lim' => $limit, 'off' => $offset]
        );
        $items = [];
        foreach ($rows as $row) {
            $url      = rtrim($siteUrl, '/') . '/media/' . $row['filename'];
            $isImage  = str_starts_with((string) $row['mime_type'], 'image/');
            $items[] = [
                'attachment_id'   => (string) $row['id'],
                'date_created_gmt' => XmlRpc::isoDate($row['uploaded_at']),
                'parent'          => 0,
                'link'            => $url,
                'title'           => $row['original_name'],
                'caption'         => '',
                'description'     => '',
                'metadata'        => [
                    'width'  => 0,
                    'height' => 0,
                    'file'   => $row['filename'],
                    'sizes'  => [],
                ],
                'thumbnail'       => $isImage ? $url : '',
                'mime_type'       => $row['mime_type'],
            ];
        }
        echo XmlRpc::encodeResponse($items);
        break;

    // ── wp.uploadFile(blogid, username, password, data) ───────────────────────
    case 'wp.uploadFile':
        xmlrpc_auth($params, 1, 2);
        $data         = (array) ($params[3] ?? []);
        $originalName = trim((string) ($data['name'] ?? ''));
        $mimeType     = trim((string) ($data['type'] ?? ''));
        $bits         = $data['bits'] ?? '';

        if ($originalName === '' || !is_string($bits) || $bits === '') {
            xmlrpc_fault(400, 'Invalid upload: name and bits are required.');
        }

        echo XmlRpc::encodeResponse(xmlrpc_save_media($originalName, $mimeType, $bits));
        break;

    // ── Unknown method ────────────────────────────────────────────────────────
    default:
        xmlrpc_fault(404, 'Unknown method: ' . $method);
}
