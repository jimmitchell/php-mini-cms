<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$auth->check();

use CMS\Helpers;
use OTPHP\TOTP;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth->verifyCsrf($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? 'change_password';

    // ── Change password ───────────────────────────────────────────────────────
    if ($action === 'change_password') {
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
                $activityLog->log('password', 'account');
                $auth->flash('Password changed successfully.');
                header('Location: /admin/account.php');
                exit;
            }
        }

    // ── Begin 2FA setup: generate secret, store in session, redirect ──────────
    } elseif ($action === 'totp_setup_begin') {
        $_SESSION['totp_setup_secret']    = $auth->generateTotpSecret();
        $_SESSION['totp_setup_secret_ts'] = time();
        header('Location: /admin/account.php');
        exit;

    // ── Confirm 2FA setup: verify code, enable, generate backup codes ─────────
    } elseif ($action === 'totp_setup_confirm') {
        $secret = $_SESSION['totp_setup_secret'] ?? '';
        if ($secret !== '' && (time() - ($_SESSION['totp_setup_secret_ts'] ?? 0)) > 900) {
            unset($_SESSION['totp_setup_secret'], $_SESSION['totp_setup_secret_ts']);
            $secret = '';
        }
        $code   = trim($_POST['totp_code'] ?? '');

        if ($secret === '') {
            $errors[] = 'Setup session expired. Please start again.';
        } else {
            $totp = TOTP::createFromSecret($secret);
            if ($totp->verify($code, null, 1)) {
                $auth->enableTotp($secret);
                $_SESSION['totp_new_codes'] = $auth->generateBackupCodes();
                unset($_SESSION['totp_setup_secret']);
                $activityLog->log('2fa_enable', 'account');
                $auth->flash('Two-factor authentication enabled.', 'success');
                header('Location: /admin/account.php');
                exit;
            } else {
                $errors[] = 'Invalid verification code. Please try again.';
            }
        }

    // ── Regenerate backup codes (requires password) ───────────────────────────
    } elseif ($action === 'totp_regen_codes') {
        $pw = $_POST['confirm_pw'] ?? '';
        if (!password_verify($pw, $config['admin']['password_hash'] ?? '')) {
            $errors[] = 'Password is incorrect.';
        } else {
            $_SESSION['totp_new_codes'] = $auth->generateBackupCodes();
            $activityLog->log('2fa_regen_codes', 'account');
            header('Location: /admin/account.php');
            exit;
        }

    // ── Cancel 2FA setup ─────────────────────────────────────────────────────
    } elseif ($action === 'totp_setup_cancel') {
        unset($_SESSION['totp_setup_secret'], $_SESSION['totp_setup_secret_ts']);
        header('Location: /admin/account.php');
        exit;

    // ── Disable 2FA (requires password) ──────────────────────────────────────
    } elseif ($action === 'totp_disable') {
        $pw = $_POST['confirm_pw'] ?? '';
        if (!password_verify($pw, $config['admin']['password_hash'] ?? '')) {
            $errors[] = 'Password is incorrect.';
        } else {
            $auth->disableTotp();
            unset($_SESSION['totp_setup_secret'], $_SESSION['totp_setup_secret_ts']);
            $activityLog->log('2fa_disable', 'account');
            $auth->flash('Two-factor authentication disabled.', 'success');
            header('Location: /admin/account.php');
            exit;
        }
    }
}

$siteTitle = $db->getSetting('site_title', 'My CMS');
$csrf      = $auth->csrfToken();
$flash     = $auth->getFlash();

// 2FA state — expire the in-progress setup secret after 15 minutes
if (isset($_SESSION['totp_setup_secret_ts']) && (time() - $_SESSION['totp_setup_secret_ts']) > 900) {
    unset($_SESSION['totp_setup_secret'], $_SESSION['totp_setup_secret_ts']);
}
$totpEnabled    = $auth->isTotpEnabled();
$setupSecret    = $_SESSION['totp_setup_secret'] ?? '';
$setupMode      = $setupSecret !== '';
$newBackupCodes = $_SESSION['totp_new_codes'] ?? [];
unset($_SESSION['totp_new_codes']);

// Generate QR SVG when in setup mode
$setupQrSvg = '';
if ($setupMode) {
    $totp = TOTP::createFromSecret($setupSecret);
    $totp->setLabel($config['admin']['username'] ?? 'admin');
    $totp->setIssuer($siteTitle);
    $provisioningUri = $totp->getProvisioningUri();

    $renderer   = new ImageRenderer(new RendererStyle(280), new SvgImageBackEnd());
    $writer     = new Writer($renderer);
    $setupQrSvg = $writer->writeString($provisioningUri);
}

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

    <!-- ── Change Password ──────────────────────────────────────────────── -->
    <form method="post" action="/admin/account.php">
        <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
        <input type="hidden" name="action" value="change_password">

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

    <!-- ── Two-Factor Authentication ────────────────────────────────────── -->
    <div class="panel" style="margin-top:1.5rem">
        <h2>Two-Factor Authentication</h2>

        <?php if (!$totpEnabled && !$setupMode): ?>

            <p style="margin-bottom:1rem">
                <span class="badge badge--error">Not configured</span>
                &nbsp; Add a second layer of security using an authenticator app
                (Google Authenticator, Authy, 1Password, etc.).
            </p>

            <form method="post" action="/admin/account.php">
                <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
                <input type="hidden" name="action" value="totp_setup_begin">
                <button type="submit" class="btn">Set up two-factor authentication</button>
            </form>

        <?php elseif ($setupMode): ?>

            <p style="margin-bottom:1rem">
                Scan this QR code with your authenticator app, then enter the
                6-digit code below to confirm setup.
            </p>

            <div style="margin-bottom:1rem">
                <?= $setupQrSvg ?>
            </div>

            <p class="form-hint" style="margin-bottom:1rem">
                <strong>Manual entry key:</strong><br>
                <code style="font-size:.9rem;letter-spacing:.1em"><?= Helpers::e($setupSecret) ?></code>
            </p>

            <form method="post" action="/admin/account.php">
                <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
                <input type="hidden" name="action" value="totp_setup_confirm">

                <label for="totp_verify_code">Verification code</label>
                <input
                    type="text"
                    id="totp_verify_code"
                    name="totp_code"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    maxlength="6"
                    required
                    placeholder="000000"
                    style="max-width:10rem">

                <div style="margin-top:1rem;display:flex;gap:.75rem;align-items:center">
                    <button type="submit" class="btn">Confirm &amp; enable</button>
                    <a href="/admin/account.php?cancel_totp=1" style="font-size:.875rem">Cancel</a>
                </div>
            </form>

        <?php else: ?>

            <p style="margin-bottom:1.25rem">
                <span class="badge badge--success">Enabled</span>
                &nbsp; Your account is protected with two-factor authentication.
            </p>

            <?php if (!empty($newBackupCodes)): ?>
                <div class="alert alert--info" style="margin-bottom:1.25rem">
                    <strong>Save these backup codes now.</strong>
                    They will not be shown again. Each code can only be used once.
                    <pre style="margin-top:.75rem;font-family:monospace;font-size:.9rem;line-height:1.8"><?php
                        foreach ($newBackupCodes as $c) echo Helpers::e($c) . "\n";
                    ?></pre>
                    <button type="button" class="btn btn--secondary" style="margin-top:.5rem"
                        onclick="navigator.clipboard.writeText(<?= htmlspecialchars(json_encode(implode("\n", $newBackupCodes)), ENT_QUOTES) ?>)">
                        Copy codes
                    </button>
                </div>
            <?php endif; ?>

            <!-- Regenerate backup codes -->
            <details style="margin-bottom:1rem">
                <summary style="cursor:pointer;font-weight:600;margin-bottom:.75rem">
                    Regenerate backup codes
                </summary>
                <p class="form-hint" style="margin-bottom:.75rem">
                    This will invalidate all existing backup codes.
                </p>
                <form method="post" action="/admin/account.php">
                    <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
                    <input type="hidden" name="action" value="totp_regen_codes">
                    <label for="confirm_pw_regen">Confirm with your password</label>
                    <input type="password" id="confirm_pw_regen" name="confirm_pw"
                           autocomplete="current-password" style="max-width:20rem">
                    <div style="margin-top:.75rem">
                        <button type="submit" class="btn btn--secondary">Regenerate codes</button>
                    </div>
                </form>
            </details>

            <!-- Disable 2FA -->
            <details>
                <summary style="cursor:pointer;font-weight:600;color:var(--danger,#dc2626);margin-bottom:.75rem">
                    Disable two-factor authentication
                </summary>
                <p class="form-hint" style="margin-bottom:.75rem">
                    This will remove 2FA from your account. You will only need your password to log in.
                </p>
                <form method="post" action="/admin/account.php">
                    <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
                    <input type="hidden" name="action" value="totp_disable">
                    <label for="confirm_pw_disable">Confirm with your password</label>
                    <input type="password" id="confirm_pw_disable" name="confirm_pw"
                           autocomplete="current-password" style="max-width:20rem">
                    <div style="margin-top:.75rem">
                        <button type="submit" class="btn btn--danger">Disable 2FA</button>
                    </div>
                </form>
            </details>

        <?php endif; ?>
    </div>
</main>

<script>
// Cancel 2FA setup by clearing the session secret.
document.querySelectorAll('a[href*="cancel_totp=1"]').forEach(function(link) {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '/admin/account.php';
        form.innerHTML =
            '<input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">' +
            '<input type="hidden" name="action" value="totp_setup_cancel">';
        document.body.appendChild(form);
        form.submit();
    });
});
</script>

<script src="/admin/assets/admin.js"></script>
</body>
</html>
