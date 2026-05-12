<?php
// POST handler + GET-side data prep for Settings → Micropub.
// Included from admin/settings.php after auth check.

$micropubFlash      = '';
$micropubFlashType  = 'success';
$justIssued         = '';
$errors             = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth->verifyCsrf($_POST['csrf_token'] ?? '');

    $action = $_POST['action'] ?? '';

    if ($action === 'generate') {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $db->upsertSetting('micropub_token', $token);
        $activityLog->log('settings', 'settings', null, 'micropub token issued');
        // Rebuild static pages so <link rel="micropub"> appears for client discovery.
        $builder->rebuildPosts();
        $builder->rebuildPages();
        $builder->rebuildSharedResources();
        $justIssued    = $token;
        $micropubFlash = 'New Micropub token generated. Copy it now — it will not be shown again.';
    } elseif ($action === 'revoke') {
        $db->upsertSetting('micropub_token', '');
        $activityLog->log('settings', 'settings', null, 'micropub token revoked');
        // Rebuild so the discovery link is removed from static pages.
        $builder->rebuildPosts();
        $builder->rebuildPages();
        $builder->rebuildSharedResources();
        $micropubFlash = 'Micropub token revoked.';
    } else {
        $errors[] = 'Unknown action.';
    }
}

$siteUrl  = rtrim($db->getSetting('site_url', ''), '/');
$endpoint = ($siteUrl !== '' ? $siteUrl : '') . '/micropub.php';
$hasToken = $db->getSetting('micropub_token', '') !== '';
$csrf     = $auth->csrfToken();
