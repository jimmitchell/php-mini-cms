<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/');
    exit;
}

$auth->verifyCsrf($_POST['csrf_token'] ?? '');
$auth->logout(); // redirects to /admin/
