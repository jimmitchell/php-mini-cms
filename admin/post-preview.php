<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$auth->check();

use CMS\Post;

if (!isset($_GET['id'])) {
    http_response_code(400);
    exit('No post ID provided.');
}

$post = Post::findById($db, (int) $_GET['id']);
if (!$post) {
    http_response_code(404);
    exit('Post not found.');
}

header('Content-Type: text/html; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow');

echo $builder->renderPostPreview($post);
