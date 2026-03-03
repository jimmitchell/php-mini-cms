<?php

declare(strict_types=1);

/**
 * Admin bootstrap — loaded by every admin PHP file.
 *
 * Sets up the autoloader, reads config, creates DB + Auth instances,
 * and starts the session.
 */

define('CMS_ROOT', dirname(__DIR__));

require CMS_ROOT . '/vendor/autoload.php';

$config = require CMS_ROOT . '/config.php';

$db   = new \CMS\Database($config['paths']['data'] . '/cms.db');
$auth = new \CMS\Auth($config, $db);

$auth->startSession();

$builder = new \CMS\Builder($config, $db);

// Prune stale login attempts (~1% of requests). Keeps the table small so the
// rate-limiting query stays fast; entries older than 24 hours are never needed.
if (random_int(1, 100) === 1) {
    $db->exec("DELETE FROM login_attempts WHERE attempted_at < datetime('now', '-24 hours')");
}

// Regenerate theme.min.css if it is missing or theme.css has been modified.
$_themeCss    = CMS_ROOT . '/theme.css';
$_themeMinCss = CMS_ROOT . '/theme.min.css';
if (file_exists($_themeCss) && (!file_exists($_themeMinCss) || filemtime($_themeCss) > filemtime($_themeMinCss))) {
    $builder->buildCss();
}
unset($_themeCss, $_themeMinCss);

// Promote any scheduled posts whose publish time has passed and rebuild them.
$promotedIds = \CMS\Post::promoteScheduled($db);
foreach ($promotedIds as $pid) {
    $promoted = \CMS\Post::findById($db, $pid);
    if ($promoted) {
        $builder->buildPost($promoted);
    }
}
if (!empty($promotedIds)) {
    $builder->buildIndex();
    $builder->buildFeed();
}
