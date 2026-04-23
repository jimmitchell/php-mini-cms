<?php

/**
 * Newsletter signup endpoint.
 *
 * Receives POST requests from the public newsletter form and stores the
 * email address in the newsletter_subscribers table.
 *
 * Privacy & abuse controls:
 *  - Raw PDO, skips autoloader — same lightweight pattern as track.php.
 *  - Honeypot field: bots that fill the hidden "website" input are silently
 *    accepted without writing to the DB (they don't learn it was rejected).
 *  - Rate limit: max 5 signups per IP per hour.
 *  - IP stored as HMAC-SHA256 hash only, reusing the analytics_salt.
 *  - Duplicate email: INSERT OR IGNORE — silently succeeds, never leaks
 *    whether an address is already subscribed.
 *
 * Response: 303 redirect to the Referer with ?subscribed=1 or ?subscribed=err,
 * so the static post page can show an inline banner via a tiny inline script.
 */

declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$referer = $_SERVER['HTTP_REFERER'] ?? '/';
// Strip any existing ?subscribed=... so we don't double up.
$refererBase = preg_replace('/[?&]subscribed=[^&]*/', '', $referer);
$refererBase = rtrim($refererBase, '?&');

$redirect = function (string $flag) use ($refererBase): void {
    $sep = str_contains($refererBase, '?') ? '&' : '?';
    header('Location: ' . $refererBase . $sep . 'subscribed=' . $flag, true, 303);
    exit;
};

// Honeypot: real browsers never fill a hidden input. Bots do.
// Silently "succeed" so the bot learns nothing.
if (!empty($_POST['website'])) {
    $redirect('1');
}

$email = trim((string) ($_POST['email'] ?? ''));
$email = mb_substr($email, 0, 254); // RFC 5321 max length

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $redirect('err');
}

$source = trim((string) ($_POST['source'] ?? ''));
$source = preg_replace('/[\x00-\x1F\x7F]/', '', $source);
$source = mb_substr($source, 0, 100);

try {
    $pdo = new PDO(
        'sqlite:' . __DIR__ . '/data/cms.db',
        null,
        null,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $pdo->exec('PRAGMA journal_mode=WAL');

    // Feature toggle: reject signups if the newsletter is disabled. Static
    // pages rendered while enabled may still have the form; this is the gate
    // that actually stops new rows being written.
    $enabledRow = $pdo->query("SELECT value FROM settings WHERE key = 'newsletter_enabled'")->fetch();
    if (($enabledRow['value'] ?? '1') !== '1') {
        http_response_code(404);
        exit;
    }

    // Reuse the same HMAC salt as the analytics beacon.
    $saltRow = $pdo->query("SELECT value FROM settings WHERE key = 'analytics_salt'")->fetch();
    $salt    = $saltRow['value'] ?? '';
    if ($salt === '') {
        $salt = bin2hex(random_bytes(32));
        $pdo->prepare(
            "INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES ('analytics_salt', ?, CURRENT_TIMESTAMP)"
        )->execute([$salt]);
    }
    $ipHash = hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? '', $salt);

    // Rate limit: max 5 signups per IP per hour.
    $rateStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM newsletter_subscribers
         WHERE ip_hash = ? AND created_at > datetime('now', '-1 hour')"
    );
    $rateStmt->execute([$ipHash]);
    if ((int) $rateStmt->fetchColumn() >= 5) {
        http_response_code(429);
        exit;
    }

    // INSERT OR IGNORE on the UNIQUE email column — duplicates silently no-op.
    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO newsletter_subscribers (email, status, source, ip_hash)
         VALUES (:email, :status, :source, :ip_hash)'
    );
    $stmt->execute([
        ':email'   => $email,
        ':status'  => 'active',
        ':source'  => $source !== '' ? $source : null,
        ':ip_hash' => $ipHash,
    ]);
} catch (\Throwable $e) {
    error_log('subscribe.php error: ' . $e->getMessage());
    $redirect('err');
}

$redirect('1');
