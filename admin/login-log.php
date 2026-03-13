<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$auth->check();

$siteTitle = $db->getSetting('site_title', 'My CMS');

// Last 200 login attempts, newest first.
$attempts = $db->select(
    "SELECT ip, success, attempted_at
       FROM login_attempts
      ORDER BY attempted_at DESC
      LIMIT 200"
);

// Summary stats for the last 24 h.
$stats = $db->selectOne(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) AS successes,
        SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS failures
       FROM login_attempts
      WHERE attempted_at >= datetime('now', '-24 hours')"
);

// Last 200 activity log entries, newest first.
$activity = $db->select(
    "SELECT action, object_type, object_id, detail, ip, created_at
       FROM activity_log
      ORDER BY created_at DESC
      LIMIT 200"
);

$cfgTz = $db->getSetting('timezone', '');
$tz    = $cfgTz !== '' ? new \DateTimeZone($cfgTz) : new \DateTimeZone('UTC');

// Convert a stored UTC datetime string to the configured timezone for display.
$fmtDate = function (string $utc) use ($tz): string {
    $dt = new \DateTime($utc, new \DateTimeZone('UTC'));
    $dt->setTimezone($tz);
    return $dt->format('Y-m-d H:i:s');
};

$actionLabels = [
    'create'    => 'Created',
    'update'    => 'Updated',
    'publish'   => 'Published',
    'unpublish' => 'Unpublished',
    'schedule'  => 'Scheduled',
    'delete'    => 'Deleted',
    'upload'    => 'Uploaded',
    'settings'  => 'Settings saved',
    'password'  => 'Password changed',
    'rebuild'   => 'Site rebuilt',
];

$typeLabels = [
    'post'     => 'Post',
    'page'     => 'Page',
    'media'    => 'Media',
    'settings' => 'Settings',
    'account'  => 'Account',
    'site'     => 'Site',
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Logs — <?= htmlspecialchars($siteTitle) ?></title>
    <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body class="admin-page">

<?php require __DIR__ . '/partials/nav.php'; ?>

<main class="admin-main">
    <header class="page-header">
        <h1>Logs</h1>
    </header>

    <section class="stats-grid" style="margin-bottom:1.5rem">
        <div class="stat-card">
            <span class="stat-card__number"><?= (int) ($stats['total'] ?? 0) ?></span>
            <span class="stat-card__label">Attempts (24 h)</span>
        </div>
        <div class="stat-card">
            <span class="stat-card__number"><?= (int) ($stats['successes'] ?? 0) ?></span>
            <span class="stat-card__label">Successful (24 h)</span>
        </div>
        <div class="stat-card">
            <span class="stat-card__number"><?= (int) ($stats['failures'] ?? 0) ?></span>
            <span class="stat-card__label">Failed (24 h)</span>
        </div>
    </section>

    <section class="panel">
        <h2>Activity log (last 200)</h2>
        <?php if (empty($activity)): ?>
            <p class="form-hint">No activity recorded yet.</p>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date / Time</th>
                    <th>Action</th>
                    <th>Detail</th>
                    <th style="text-align:right">IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($activity as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($fmtDate($row['created_at'])) ?></td>
                    <td>
                        <?= htmlspecialchars($actionLabels[$row['action']] ?? ucfirst($row['action'])) ?>
                        <span class="meta" style="display:block;font-size:.75rem">
                            <?= htmlspecialchars($typeLabels[$row['object_type']] ?? $row['object_type']) ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($row['detail']) ?></td>
                    <td style="text-align:right"><?= htmlspecialchars($row['ip']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Login attempts (last 200)</h2>
        <?php if (empty($attempts)): ?>
            <p class="form-hint">No login attempts recorded yet.</p>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date / Time</th>
                    <th>IP Address</th>
                    <th style="text-align:right">Result</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($attempts as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($fmtDate($row['attempted_at'])) ?></td>
                    <td><?= htmlspecialchars($row['ip']) ?></td>
                    <td style="text-align:right">
                        <?php if ($row['success']): ?>
                            <span class="badge badge--success">Success</span>
                        <?php else: ?>
                            <span class="badge badge--error">Failed</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>
</main>
<script src="/admin/assets/admin.js"></script>
</body>
</html>
