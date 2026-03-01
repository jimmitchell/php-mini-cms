<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$auth->check();

use CMS\Helpers;

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth->verifyCsrf($_POST['csrf_token'] ?? '');

    $fields = [
        'site_title'         => trim($_POST['site_title']         ?? ''),
        'site_description'   => trim($_POST['site_description']   ?? ''),
        'site_url'           => rtrim(trim($_POST['site_url'] ?? ''), '/'),
        'footer_text'        => trim($_POST['footer_text']        ?? ''),
        'timezone'           => trim($_POST['timezone']           ?? ''),
        'locale'             => trim($_POST['locale']             ?? ''),
        'posts_per_page'     => (string) max(1, (int) ($_POST['posts_per_page'] ?? 10)),
        'feed_post_count'    => (string) max(1, (int) ($_POST['feed_post_count'] ?? 20)),
        'mastodon_handle'    => trim($_POST['mastodon_handle']    ?? ''),
        'mastodon_instance'  => rtrim(trim($_POST['mastodon_instance'] ?? ''), '/'),
        'mastodon_token'     => trim($_POST['mastodon_token']     ?? ''),
        'tinylytics_code'    => trim($_POST['tinylytics_code']    ?? ''),
    ];

    if ($fields['site_title'] === '') {
        $errors[] = 'Site title is required.';
    }

    if ($fields['site_url'] !== '' && !filter_var($fields['site_url'], FILTER_VALIDATE_URL)) {
        $errors[] = 'Site URL must be a valid URL (e.g. https://example.com).';
    }

    if ($fields['timezone'] !== '' && !in_array($fields['timezone'], timezone_identifiers_list(), true)) {
        $errors[] = 'Timezone must be a valid PHP timezone identifier (e.g. America/New_York).';
    }

    if ($fields['mastodon_handle'] !== '') {
        $stripped = ltrim($fields['mastodon_handle'], '@');
        if (substr_count($stripped, '@') !== 1 || str_starts_with($stripped, '@') || str_ends_with($stripped, '@')) {
            $errors[] = 'Mastodon handle must be in the form @username@instance.social.';
        }
    }

    if (empty($errors)) {
        foreach ($fields as $key => $value) {
            // Don't overwrite a saved token when the field is left blank.
            if ($key === 'mastodon_token' && $value === '') {
                continue;
            }
            $db->upsertSetting($key, $value);
        }

        // Rebuild index and feed so new title/footer etc. is reflected.
        $builder->buildIndex();
        $builder->buildFeed();

        $auth->flash('Settings saved and site rebuilt.');
        header('Location: /admin/settings.php');
        exit;
    }
}

$settings  = $db->getAllSettings();
$siteTitle = $settings['site_title'] ?? 'My CMS';
$csrf      = $auth->csrfToken();
$flash     = $auth->getFlash();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Settings — <?= Helpers::e($siteTitle) ?></title>
    <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body class="admin-page">

<?php require __DIR__ . '/partials/nav.php'; ?>

<main class="admin-main">
    <header class="page-header">
        <h1>Settings</h1>
    </header>

    <?php if ($flash): ?>
        <p class="alert alert--<?= Helpers::e($flash['type']) ?>"><?= Helpers::e($flash['message']) ?></p>
    <?php endif; ?>

    <?php foreach ($errors as $e): ?>
        <p class="alert alert--error"><?= Helpers::e($e) ?></p>
    <?php endforeach; ?>

    <form method="post" action="/admin/settings.php">
        <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">

        <div class="panel">
            <h2>Site identity</h2>

            <label for="site_title">Site title <span class="required">*</span></label>
            <input type="text" id="site_title" name="site_title"
                   value="<?= Helpers::e($_POST['site_title'] ?? $settings['site_title'] ?? '') ?>"
                   required>

            <label for="site_description">Site description</label>
            <input type="text" id="site_description" name="site_description"
                   value="<?= Helpers::e($_POST['site_description'] ?? $settings['site_description'] ?? '') ?>"
                   placeholder="Shown in <meta name=description> and the Atom feed">

            <label for="site_url">Site URL</label>
            <input type="url" id="site_url" name="site_url"
                   value="<?= Helpers::e($_POST['site_url'] ?? $settings['site_url'] ?? '') ?>"
                   placeholder="https://example.com">
            <p class="form-hint">Used for canonical URLs, OG tags, and the Atom feed. No trailing slash.</p>

            <label for="footer_text">Footer text</label>
            <input type="text" id="footer_text" name="footer_text"
                   value="<?= Helpers::e($_POST['footer_text'] ?? $settings['footer_text'] ?? '') ?>"
                   placeholder="© 2026 My Site  (leave blank for default)">

            <label for="timezone">Timezone</label>
            <input type="text" id="timezone" name="timezone"
                   value="<?= Helpers::e($_POST['timezone'] ?? $settings['timezone'] ?? '') ?>"
                   placeholder="America/New_York"
                   style="max-width:240px">
            <p class="form-hint">
                PHP timezone identifier used for post date display.
                Examples: <code>America/New_York</code>, <code>America/Los_Angeles</code>,
                <code>Europe/London</code>, <code>Asia/Tokyo</code>.
                Leave blank to use the server default (UTC).
                <a href="https://www.php.net/manual/en/timezones.php" target="_blank" rel="noopener">Full list</a>.
            </p>

            <label for="locale">Locale</label>
            <input type="text" id="locale" name="locale"
                   value="<?= Helpers::e($_POST['locale'] ?? $settings['locale'] ?? '') ?>"
                   placeholder="en_US"
                   style="max-width:160px">
            <p class="form-hint">
                BCP 47 / ICU locale code used for date formatting.
                Examples: <code>en_US</code>, <code>en_GB</code>, <code>fr_FR</code>,
                <code>de_DE</code>, <code>ja_JP</code>.
                Leave blank to use the server default (English).
            </p>
        </div>

        <div class="panel">
            <h2>Content</h2>

            <label for="posts_per_page">Posts per page</label>
            <input type="number" id="posts_per_page" name="posts_per_page"
                   value="<?= (int) ($_POST['posts_per_page'] ?? $settings['posts_per_page'] ?? 10) ?>"
                   min="1" max="100" style="max-width:120px">
            <p class="form-hint">Number of posts shown on each listing page.</p>

            <label for="feed_post_count">Posts in RSS feed</label>
            <input type="number" id="feed_post_count" name="feed_post_count"
                   value="<?= (int) ($_POST['feed_post_count'] ?? $settings['feed_post_count'] ?? 20) ?>"
                   min="1" max="100" style="max-width:120px">
            <p class="form-hint">Number of posts included in <code>feed.xml</code>.</p>
        </div>

        <div class="panel">
            <h2>Mastodon</h2>
            <p class="form-hint" style="margin-bottom:1rem">
                When both fields are set, new posts will be automatically tooted on first publish.
            </p>

            <label for="mastodon_handle">Your Mastodon handle</label>
            <input type="text" id="mastodon_handle" name="mastodon_handle"
                   value="<?= Helpers::e($_POST['mastodon_handle'] ?? $settings['mastodon_handle'] ?? '') ?>"
                   placeholder="@username@mastodon.social"
                   style="max-width:320px">
            <p class="form-hint">
                Adds a <code>fediverse:creator</code> meta tag to every page and shows a Mastodon link in the footer.
                Format: <code>@username@instance.social</code>
            </p>

            <label for="mastodon_instance">Instance URL</label>
            <input type="url" id="mastodon_instance" name="mastodon_instance"
                   value="<?= Helpers::e($_POST['mastodon_instance'] ?? $settings['mastodon_instance'] ?? '') ?>"
                   placeholder="https://indieweb.social"
                   style="max-width:320px">

            <label for="mastodon_token">Access token</label>
            <input type="password" id="mastodon_token" name="mastodon_token"
                   value=""
                   placeholder="<?= ($settings['mastodon_token'] ?? '') !== '' ? '(saved — leave blank to keep)' : 'Paste your token here' ?>"
                   autocomplete="new-password"
                   style="max-width:360px">
            <p class="form-hint">
                Create a token in your Mastodon account under
                Preferences → Development → New application.
                Only the <code>write:statuses</code> scope is needed.
            </p>
        </div>

        <div class="panel">
            <h2>Analytics</h2>

            <label for="tinylytics_code">Tinylytics site ID</label>
            <input type="text" id="tinylytics_code" name="tinylytics_code"
                   value="<?= Helpers::e($_POST['tinylytics_code'] ?? $settings['tinylytics_code'] ?? '') ?>"
                   placeholder="MMxGnYf8Pf5h66K_eim4"
                   style="max-width:280px">
            <p class="form-hint">
                Your Tinylytics site ID. When set, the tracking script is added to every page.
                Leave blank to disable tracking.
            </p>
        </div>

        <div style="display:flex; gap:.75rem; margin-top:1rem; margin-bottom:2rem">
            <button type="submit" class="btn">Save settings</button>
            <a href="/admin/dashboard.php" class="btn btn--secondary">Cancel</a>
        </div>
    </form>
</main>

</body>
</html>
