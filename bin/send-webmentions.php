#!/usr/bin/env php
<?php

/**
 * Send outgoing webmentions for all published posts that have changed
 * since webmentions were last sent.
 *
 * Usage:
 *   php bin/send-webmentions.php [--force]
 *
 * Options:
 *   --force   Re-send webmentions for all published posts, ignoring the
 *             webmentions_sent_at timestamp.
 *
 * Add to cron (daily at 02:00):
 *   0 2 * * * /usr/bin/php /path/to/bin/send-webmentions.php >> /var/log/webmentions.log 2>&1
 */

define('CMS_ROOT', dirname(__DIR__));

require CMS_ROOT . '/vendor/autoload.php';

use CMS\Database;
use CMS\Post;
use CMS\Webmention;

if (!class_exists(Database::class)) {
    fwrite(STDERR, "Error: autoloader not found. Run 'composer install' first.\n");
    exit(1);
}

$config   = require CMS_ROOT . '/config.php';
$db       = new Database($config['paths']['db']);
$settings = $db->getAllSettings();

$outputDir = rtrim($config['paths']['output'], '/');
$siteUrl   = rtrim($settings['site_url'] ?? '', '/');
$force     = in_array('--force', $argv ?? [], true);

if ($siteUrl === '') {
    fwrite(STDERR, "Error: site_url is not configured in Settings.\n");
    exit(1);
}

$posts = Post::findAll($db, 'published');

$sent = $skipped = $failed = $processed = 0;

foreach ($posts as $post) {
    if (!$force
        && $post->webmentions_sent_at !== null
        && strtotime($post->updated_at) <= strtotime($post->webmentions_sent_at)) {
        $skipped++;
        continue;
    }

    $htmlPath = $outputDir . '/posts/' . Post::datePath($post->published_at, $post->slug) . '/index.html';
    if (!file_exists($htmlPath)) {
        echo "  [skip]  {$post->slug} — built HTML not found\n";
        $skipped++;
        continue;
    }

    $html    = (string) file_get_contents($htmlPath);
    $postUrl = $siteUrl . '/' . Post::datePath($post->published_at, $post->slug) . '/';
    $urls    = Webmention::extractUrls($html, $siteUrl);

    if (empty($urls)) {
        $post->markWebmentionsSent();
        $processed++;
        continue;
    }

    echo "  [post]  {$postUrl}\n";

    foreach ($urls as $target) {
        $endpoint = Webmention::discoverEndpoint($target);
        if ($endpoint === null) {
            echo "    [no endpoint] {$target}\n";
            continue;
        }
        $ok = Webmention::sendPing($postUrl, $target, $endpoint);
        echo '    [' . ($ok ? ' sent ' : 'failed') . "] {$target}\n";
        $ok ? $sent++ : $failed++;
    }

    $post->markWebmentionsSent();
    $processed++;
}

echo "\nDone: {$sent} sent across {$processed} posts; {$failed} failed; {$skipped} already up to date.\n";
exit($failed > 0 ? 1 : 0);
