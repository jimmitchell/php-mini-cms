<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

// Already authenticated → go to dashboard.
if ($auth->isAuthenticated()) {
    header('Location: /admin/dashboard.php');
    exit;
}

// Allow cancelling a pending TOTP step (returns to password form).
if (isset($_GET['cancel']) && $auth->isTotpPending()) {
    unset($_SESSION['totp_pending'], $_SESSION['totp_pending_user']);
    header('Location: /admin/');
    exit;
}

$error   = '';
$lockout = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth->verifyCsrf($_POST['csrf_token'] ?? '');

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if ($auth->isTotpPending()) {
        // Step 2: verify TOTP code or backup code.
        $code = trim($_POST['totp_code'] ?? '');

        if ($auth->isTotpLockedOut($ip)) {
            $lockout = true;
            $error   = 'Too many failed attempts. Try again later.';
        } elseif ($auth->verifyTotp($code) || $auth->verifyBackupCode($code)) {
            $auth->recordTotpAttempt($ip, true);
            $auth->completeTotpLogin();
            header('Location: /admin/dashboard.php');
            exit;
        } else {
            $auth->recordTotpAttempt($ip, false);
            if ($auth->isTotpLockedOut($ip)) {
                $lockout = true;
                $error   = 'Too many failed attempts. Try again later.';
            } else {
                $error = 'Invalid code. Please try again.';
            }
        }
    } else {
        // Step 1: verify password.
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($auth->isLockedOut($ip)) {
            $lockout = true;
            $secs    = $auth->lockoutSecondsRemaining($ip);
            $mins    = (int) ceil($secs / 60);
            $error   = "Too many failed attempts. Try again in {$mins} minute(s).";
        } elseif ($auth->login($username, $password)) {
            if ($auth->isTotpPending()) {
                // 2FA required — fall through to render TOTP form.
            } else {
                header('Location: /admin/dashboard.php');
                exit;
            }
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
}

$step      = $auth->isTotpPending() ? 'totp' : 'password';
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

    <?php if ($step === 'totp'): ?>

        <p style="margin-bottom:1rem;color:var(--text-muted,#6b7280)">
            Enter the 6-digit code from your authenticator app, or a backup code.
        </p>

        <form method="post" action="/admin/" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

            <label for="totp_code">Authentication code</label>
            <input
                type="text"
                id="totp_code"
                name="totp_code"
                inputmode="numeric"
                autocomplete="one-time-code"
                maxlength="20"
                required
                autofocus
                <?= $lockout ? 'disabled' : '' ?>>

            <button type="submit" <?= $lockout ? 'disabled' : '' ?>>Verify</button>
        </form>

        <p style="margin-top:1rem;font-size:.875rem">
            <a href="/admin/?cancel=1">← Use a different account</a>
        </p>

    <?php else: ?>

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

    <?php endif; ?>
</div>

</body>
</html>
