<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

// Already authenticated → go to dashboard.
if ($auth->isAuthenticated()) {
    header('Location: /admin/dashboard.php');
    exit;
}

$error   = '';
$lockout = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token    = $_POST['csrf_token'] ?? '';
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // CSRF guard — for login we check the token is at least present and matches.
    $auth->verifyCsrf($token);

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if ($auth->isLockedOut($ip)) {
        $lockout = true;
        $secs    = $auth->lockoutSecondsRemaining($ip);
        $mins    = (int) ceil($secs / 60);
        $error   = "Too many failed attempts. Try again in {$mins} minute(s).";
    } elseif ($auth->login($username, $password)) {
        header('Location: /admin/dashboard.php');
        exit;
    } else {
        if ($auth->isLockedOut($ip)) {
            $lockout = true;
            $secs    = $auth->lockoutSecondsRemaining($ip);
            $mins    = (int) ceil($secs / 60);
            $error   = "Too many failed attempts. Try again in {$mins} minute(s).";
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

$csrf      = $auth->csrfToken();
$siteTitle = $db->getSetting('site_title', 'My CMS');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login</title>
    <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body class="login-page">

<div class="login-box">
    <h1>Login to <?= htmlspecialchars($siteTitle) ?></h1>

    <?php if ($error !== ''): ?>
        <p class="alert alert--error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="post" action="/admin/" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

        <label for="username">Username</label>
        <input
            type="text"
            id="username"
            name="username"
            autocomplete="username"
            required
            <?= $lockout ? 'disabled' : '' ?>
            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">

        <label for="password">Password</label>
        <input
            type="password"
            id="password"
            name="password"
            autocomplete="current-password"
            required
            <?= $lockout ? 'disabled' : '' ?>>

        <button type="submit" <?= $lockout ? 'disabled' : '' ?>>Log in</button>
    </form>
</div>

</body>
</html>
