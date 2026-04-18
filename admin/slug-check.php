<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$auth->check();

header('Content-Type: application/json; charset=utf-8');

$type = $_GET['type'] ?? '';
$slug = trim($_GET['slug'] ?? '');
$id   = isset($_GET['id']) && ctype_digit($_GET['id']) ? (int) $_GET['id'] : null;

if (!in_array($type, ['post', 'page'], true) || $slug === '') {
    http_response_code(400);
    echo json_encode(['error' => 'type and slug are required']);
    exit;
}

if ($type === 'post') {
    $existing = \CMS\Post::findBySlug($db, $slug);
} else {
    $existing = \CMS\Page::findBySlug($db, $slug);
}

// Available if nothing found, or if found but it's the record being edited.
$available = $existing === null || $existing->id === $id;

echo json_encode(['available' => $available]);
