<?php

/**
 * Analytics tracking beacon.
 *
 * Receives POST requests (via navigator.sendBeacon) from public site pages and
 * records page view data in the page_views table.
 *
 * Privacy:
 *  - IP address is stored as an HMAC-SHA256 hash only (never raw).
 *  - No cookies are set or read.
 *  - Owner opt-out: visit /?ti=exclude to set a localStorage flag that
 *    prevents the beacon JS from sending requests.
 */

declare(strict_types=1);

// Only accept POST from navigator.sendBeacon.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// Parse JSON body sent by sendBeacon.
$raw  = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);

if (!is_array($data)) {
    http_response_code(400);
    exit;
}

// Sanitise URL — keep only the path component, strip control characters.
$url = (string) ($data['url'] ?? '');
$url = parse_url($url, PHP_URL_PATH) ?: '/';
$url = preg_replace('/[\x00-\x1F\x7F]/', '', $url);
$url = mb_substr($url, 0, 500);

// Sanitise referrer — keep origin + path only, strip query strings and control characters.
$referrer = (string) ($data['referrer'] ?? '');
if ($referrer !== '') {
    $parts    = parse_url($referrer);
    $referrer = ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '') . ($parts['path'] ?? '');
    $referrer = preg_replace('/[\x00-\x1F\x7F]/', '', $referrer);
    $referrer = mb_substr($referrer, 0, 500);
    if ($referrer === '://') {
        $referrer = '';
    }
}

$is404 = !empty($data['is404']) ? 1 : 0;

// Detect device type from User-Agent.
$ua         = $_SERVER['HTTP_USER_AGENT'] ?? '';
$deviceType = 'desktop';
if (preg_match('/tablet|ipad|playbook|silk/i', $ua)) {
    $deviceType = 'tablet';
} elseif (preg_match('/mobile|android|iphone|ipod|blackberry|opera mini|iemobile|wpdesktop/i', $ua)) {
    $deviceType = 'mobile';
}

$timestamp = time();

// Raw PDO — skips the autoloader and migration check for minimal overhead.
try {
    $pdo = new PDO(
        'sqlite:' . __DIR__ . '/data/cms.db',
        null,
        null,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $pdo->exec('PRAGMA journal_mode=WAL');

    // Get or generate the HMAC salt stored in the settings table.
    // Generated once on the first beacon call and reused thereafter.
    $saltRow = $pdo->query("SELECT value FROM settings WHERE key = 'analytics_salt'")->fetch();
    $salt    = $saltRow['value'] ?? '';
    if ($salt === '') {
        $salt = bin2hex(random_bytes(32));
        $pdo->prepare(
            "INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES ('analytics_salt', ?, CURRENT_TIMESTAMP)"
        )->execute([$salt]);
    }

    // Hash IP with HMAC-SHA256 using the server-side salt.
    // The salt makes enumeration of the IPv4 space infeasible without the secret.
    $ipHash = hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? '', $salt);

    // Rate limit: max 30 beacons per IP per minute.
    $rateStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM page_views WHERE ip_hash = ? AND timestamp > ?"
    );
    $rateStmt->execute([$ipHash, $timestamp - 60]);
    if ((int) $rateStmt->fetchColumn() > 30) {
        http_response_code(429);
        exit;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO page_views (url, referrer, device_type, is_404, ip_hash, timestamp)
         VALUES (:url, :referrer, :device_type, :is_404, :ip_hash, :timestamp)'
    );
    $stmt->execute([
        ':url'         => $url,
        ':referrer'    => $referrer !== '' ? $referrer : null,
        ':device_type' => $deviceType,
        ':is_404'      => $is404,
        ':ip_hash'     => $ipHash,
        ':timestamp'   => $timestamp,
    ]);
} catch (\Throwable $e) {
    // Log the error but never expose DB path or details to the caller.
    error_log('track.php error: ' . $e->getMessage());
}

http_response_code(204);
