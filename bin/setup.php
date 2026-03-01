#!/usr/bin/env php
<?php

/**
 * CMS Setup Script
 *
 * Usage:
 *   php bin/setup.php
 *
 * Generates a bcrypt password hash and writes it into config.php,
 * then initialises the database schema.
 */

define('CMS_ROOT', dirname(__DIR__));

require CMS_ROOT . '/vendor/autoload.php';

$config = require CMS_ROOT . '/config.php';

// ── Check autoloader ──────────────────────────────────────────────────────────

if (!class_exists(\CMS\Database::class)) {
    fwrite(STDERR, "Error: autoloader not found. Run 'composer install' first.\n");
    exit(1);
}

// ── Prompt for password ───────────────────────────────────────────────────────

echo "\n=== PHP Mini CMS Setup ===\n\n";

$username = $config['admin']['username'] ?? 'admin';
echo "Admin username: {$username}\n";

$password = '';
while ($password === '') {
    echo 'Enter admin password: ';

    // Hide input on Unix-like systems.
    if (PHP_OS_FAMILY !== 'Windows') {
        system('stty -echo');
        $password = trim((string) fgets(STDIN));
        system('stty echo');
        echo "\n";
    } else {
        $password = trim((string) fgets(STDIN));
    }

    if ($password === '') {
        echo "Password cannot be empty. Please try again.\n";
    }
}

$confirm = '';
echo 'Confirm password: ';
if (PHP_OS_FAMILY !== 'Windows') {
    system('stty -echo');
    $confirm = trim((string) fgets(STDIN));
    system('stty echo');
    echo "\n";
} else {
    $confirm = trim((string) fgets(STDIN));
}

if ($password !== $confirm) {
    fwrite(STDERR, "Error: Passwords do not match.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_BCRYPT);

// ── Write hash into config.php ────────────────────────────────────────────────

$configPath    = CMS_ROOT . '/config.php';
$configContent = file_get_contents($configPath);

// Replace the empty password_hash value.
// Use preg_replace_callback so the bcrypt hash ($2y$10$...) is never
// interpreted as a regex backreference by the replacement engine.
$updated = preg_replace_callback(
    "/'password_hash'\s*=>\s*'[^']*'/",
    fn($m) => "'password_hash' => '{$hash}'",
    $configContent
);

if ($updated === null || $updated === $configContent) {
    // Fallback: just print the hash for manual pasting.
    echo "\nCould not auto-update config.php. Paste this hash manually:\n";
    echo "    'password_hash' => '{$hash}',\n";
} else {
    file_put_contents($configPath, $updated);
    echo "\nPassword hash written to config.php.\n";
}

// ── Initialise database ───────────────────────────────────────────────────────

$dataDir = $config['paths']['data'];
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0775, true);
    echo "Created data/ directory.\n";
}

$db = new \CMS\Database($dataDir . '/cms.db');
echo "Database schema initialised.\n";

// ── Seed default settings if not already present ──────────────────────────────

$title = $db->getSetting('site_title', '');
if ($title === '' || $title === 'My CMS') {
    echo "\nSite title [My CMS]: ";
    $input = trim((string) fgets(STDIN));
    $db->upsertSetting('site_title', $input ?: 'My CMS');

    echo 'Site URL (e.g. https://example.com): ';
    $url = rtrim(trim((string) fgets(STDIN)), '/');
    if ($url !== '') {
        $db->upsertSetting('site_url', $url);
    }
}

echo "\nSetup complete. Visit /admin/ to log in.\n\n";
