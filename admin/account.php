<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$auth->check();

use CMS\Helpers;

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth->verifyCsrf($_POST['csrf_token'] ?? '');

    $currentPw = $_POST['current_password'] ?? '';
    $newPw     = $_POST['new_password']     ?? '';
    $confirmPw = $_POST['confirm_password'] ?? '';

    if ($currentPw === '' || $newPw === '' || $confirmPw === '') {
        $errors[] = 'All fields are required.';
    } elseif (!password_verify($currentPw, $config['admin']['password_hash'] ?? '')) {
        $errors[] = 'Current password is incorrect.';
    } elseif ($newPw !== $confirmPw) {
        $errors[] = 'New passwords do not match.';
    } elseif (strlen($newPw) < 12) {
        $errors[] = 'New password must be at least 12 characters.';
    }

    if (empty($errors)) {
        $hash       = password_hash($newPw, PASSWORD_BCRYPT);
        $configPath = dirname(__DIR__) . '/config.php';
        $src        = file_get_contents($configPath);
        $updated    = preg_replace_callback(
            "/'password_hash'\s*=>\s*'[^']*'/",
            fn($m) => "'password_hash' => '{$hash}'",
            $src
        );

        if ($updated === null || $updated === $src) {
            $errors[] = 'Could not write config.php — check file permissions.';
        } else {
            file_put_contents($configPath, $updated);
            $auth->flash('Password changed successfully.');
            header('Location: /admin/account.php');
            exit;
        }
    }
}

$siteTitle = $db->getSetting('site_title', 'My CMS');
$csrf      = $auth->csrfToken();
$flash     = $auth->getFlash();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Account — <?= Helpers::e($siteTitle) ?></title>
    <link rel="stylesheet" href="/admin/assets/admin.css">
    <link rel="stylesheet" href="/admin/assets/font-awesome.min.css">
</head>
<body class="admin-page">

<?php require __DIR__ . '/partials/nav.php'; ?>

<main class="admin-main">
    <header class="page-header">
        <h1>Account</h1>
    </header>

    <?php foreach ($errors as $e): ?>
        <p class="alert alert--error"><?= Helpers::e($e) ?></p>
    <?php endforeach; ?>

    <?php if (($flash['message'] ?? '') !== ''): ?>
        <p class="alert alert--<?= Helpers::e($flash['type'] ?? 'success') ?>"><?= Helpers::e($flash['message']) ?></p>
    <?php endif; ?>

    <form method="post" action="/admin/account.php">
        <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">

        <div class="panel">
            <h2>Change Password</h2>

            <label for="current_password">Current password</label>
            <input type="password" id="current_password" name="current_password"
                   autocomplete="current-password">

            <label for="new_password" style="margin-top:1rem">New password</label>
            <input type="password" id="new_password" name="new_password"
                   autocomplete="new-password">
            <p class="form-hint">Minimum 12 characters.</p>

            <label for="confirm_password">Confirm new password</label>
            <input type="password" id="confirm_password" name="confirm_password"
                   autocomplete="new-password">

            <div style="margin-top:1.25rem">
                <button type="submit" class="btn">Change password</button>
            </div>
        </div>
    </form>
</main>

</body>
</html>
