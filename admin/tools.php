<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$auth->check();

use CMS\Helpers;

$tabs = [
    'import'       => 'Import',
    'import-media' => 'Import media',
    'export'       => 'Export',
];

$activeTab = (string) ($_GET['tab'] ?? 'import');
if (!isset($tabs[$activeTab])) {
    $activeTab = 'import';
}

$basePath  = '/admin/tools.php';
$pageTitle = 'Tools';

// Handler runs first — it may exit (redirect after POST, or stream a download).
require __DIR__ . '/partials/tools/' . $activeTab . '.handler.php';

$siteTitle = $db->getSetting('site_title', 'My CMS');
$flash     = $auth->getFlash();
$flashMsg  = $flash['message'] ?? '';
$flashType = $flash['type']    ?? 'success';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= Helpers::e($pageTitle) ?> — <?= Helpers::e($siteTitle) ?></title>
    <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body class="admin-page">

<?php require __DIR__ . '/partials/nav.php'; ?>

<main class="admin-main">
    <header class="page-header">
        <h1><?= Helpers::e($pageTitle) ?></h1>
    </header>

    <?php require __DIR__ . '/partials/page-tabs.php'; ?>

    <?php if ($flashMsg !== ''): ?>
        <p class="alert alert--<?= Helpers::e($flashType) ?>"><?= Helpers::e($flashMsg) ?></p>
    <?php endif; ?>

    <?php require __DIR__ . '/partials/tools/' . $activeTab . '.view.php'; ?>
</main>

<script src="/admin/assets/admin.js"></script>
</body>
</html>
