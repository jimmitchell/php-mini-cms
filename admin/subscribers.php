<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$auth->check();

use CMS\Helpers;

// ── Handle CSV export (GET) ───────────────────────────────────────────────────

if (($_GET['export'] ?? '') === 'csv') {
    $rows = $db->select(
        "SELECT email, status, source, created_at
           FROM newsletter_subscribers
           ORDER BY created_at DESC"
    );

    $activityLog->log('export', 'subscribers', null, count($rows) . ' subscribers');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="subscribers-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');

    // Prefix a leading apostrophe to any cell starting with a character that
    // Excel/LibreOffice/Numbers would evaluate as a formula, blocking CSV
    // injection when an admin opens the export in a spreadsheet app.
    $csvSafe = static function ($v): string {
        $v = (string) $v;
        return $v !== '' && str_contains("=+-@\t\r", $v[0]) ? "'" . $v : $v;
    };

    fputcsv($out, ['email', 'status', 'source', 'created_at']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $csvSafe($row['email']),
            $csvSafe($row['status']),
            $csvSafe($row['source']),
            $csvSafe($row['created_at']),
        ]);
    }
    fclose($out);
    exit;
}

// ── Handle POST ───────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth->verifyCsrf($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';
    $id     = (int) ($_POST['id'] ?? 0);

    if ($id > 0 && in_array($action, ['unsubscribe', 'resubscribe', 'delete'], true)) {
        $row = $db->selectOne("SELECT email FROM newsletter_subscribers WHERE id = :id", [':id' => $id]);

        if ($row) {
            if ($action === 'delete') {
                $db->delete('newsletter_subscribers', 'id = :id', [':id' => $id]);
                $activityLog->log('delete', 'subscriber', $id, $row['email']);
                $auth->flash('Subscriber deleted.', 'info');
            } else {
                $newStatus = $action === 'unsubscribe' ? 'unsubscribed' : 'active';
                $db->update(
                    'newsletter_subscribers',
                    ['status' => $newStatus],
                    'id = :id',
                    [':id' => $id]
                );
                $activityLog->log($action, 'subscriber', $id, $row['email']);
                $auth->flash('Subscriber ' . $newStatus . '.');
            }
        }
    }

    $filter = $_POST['return_filter'] ?? '';
    header('Location: /admin/subscribers.php' . ($filter !== '' ? '?filter=' . urlencode($filter) : ''));
    exit;
}

// ── Load data ─────────────────────────────────────────────────────────────────

$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'active', 'unsubscribed'], true)) {
    $filter = 'all';
}

if ($filter === 'all') {
    $subscribers = $db->select(
        "SELECT id, email, status, source, created_at
           FROM newsletter_subscribers
           ORDER BY created_at DESC"
    );
} else {
    $subscribers = $db->select(
        "SELECT id, email, status, source, created_at
           FROM newsletter_subscribers
           WHERE status = :status
           ORDER BY created_at DESC",
        [':status' => $filter]
    );
}

$counts = $db->selectOne(
    "SELECT
        COUNT(*)                                                      AS total,
        SUM(CASE WHEN status = 'active'       THEN 1 ELSE 0 END)      AS active,
        SUM(CASE WHEN status = 'unsubscribed' THEN 1 ELSE 0 END)      AS unsubscribed
       FROM newsletter_subscribers"
) ?? ['total' => 0, 'active' => 0, 'unsubscribed' => 0];

$flash             = $auth->getFlash();
$flashMsg          = $flash['message'] ?? '';
$flashType         = $flash['type']    ?? 'success';
$csrf              = $auth->csrfToken();
$siteTitle         = $db->getSetting('site_title', 'My CMS');
$newsletterEnabled = $db->getSetting('newsletter_enabled', '1') === '1';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Subscribers — <?= Helpers::e($siteTitle) ?></title>
    <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body class="admin-page">

<?php require __DIR__ . '/partials/nav.php'; ?>

<main class="admin-main">
    <header class="page-header">
        <h1>Subscribers</h1>
        <div>
            <a href="/admin/subscribers.php?export=csv" class="btn btn--secondary">Export CSV</a>
        </div>
    </header>

    <?php if ($flashMsg !== ''): ?>
        <p class="alert alert--<?= Helpers::e($flashType) ?>"><?= Helpers::e($flashMsg) ?></p>
    <?php endif; ?>

    <?php if (!$newsletterEnabled): ?>
        <p class="alert alert--info">
            Newsletter signups are currently <strong>disabled</strong>.
            The form is not rendered on posts and <code>/subscribe.php</code> returns 404.
            Re-enable in <a href="/admin/settings.php">Settings → Newsletter</a>.
        </p>
    <?php endif; ?>

    <div class="panel" style="margin-bottom:1rem">
        <p style="margin:0;color:var(--color-muted)">
            <strong><?= (int) $counts['total'] ?></strong> total —
            <strong><?= (int) $counts['active'] ?></strong> active,
            <strong><?= (int) $counts['unsubscribed'] ?></strong> unsubscribed
        </p>
    </div>

    <div style="display:flex;gap:.5rem;margin-bottom:1rem">
        <a href="/admin/subscribers.php"              class="btn btn--sm <?= $filter === 'all'          ? ''            : 'btn--secondary' ?>">All</a>
        <a href="/admin/subscribers.php?filter=active"       class="btn btn--sm <?= $filter === 'active'       ? ''            : 'btn--secondary' ?>">Active</a>
        <a href="/admin/subscribers.php?filter=unsubscribed" class="btn btn--sm <?= $filter === 'unsubscribed' ? ''            : 'btn--secondary' ?>">Unsubscribed</a>
    </div>

    <?php if (empty($subscribers)): ?>
    <p style="color:var(--color-muted)">No subscribers<?= $filter !== 'all' ? ' with this status' : ' yet' ?>.</p>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Email</th>
                <th>Status</th>
                <th>Source</th>
                <th>Joined</th>
                <th style="text-align:right">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($subscribers as $sub): ?>
        <tr>
            <td><?= Helpers::e($sub['email']) ?></td>
            <td><?= Helpers::e($sub['status']) ?></td>
            <td><?= $sub['source'] !== null ? '<code>' . Helpers::e($sub['source']) . '</code>' : '<span style="color:var(--color-muted)">—</span>' ?></td>
            <td><?= Helpers::e(Helpers::formatDate($sub['created_at'])) ?></td>
            <td>
                <div class="actions">
                    <form method="post" action="/admin/subscribers.php" style="display:inline">
                        <input type="hidden" name="csrf_token"    value="<?= Helpers::e($csrf) ?>">
                        <input type="hidden" name="id"            value="<?= (int) $sub['id'] ?>">
                        <input type="hidden" name="return_filter" value="<?= Helpers::e($filter) ?>">
                        <?php if ($sub['status'] === 'active'): ?>
                        <button type="submit" name="action" value="unsubscribe" class="btn btn--secondary btn--sm">Unsubscribe</button>
                        <?php else: ?>
                        <button type="submit" name="action" value="resubscribe" class="btn btn--secondary btn--sm">Resubscribe</button>
                        <?php endif; ?>
                        <button type="submit" name="action" value="delete" class="btn btn--danger btn--sm"
                                onclick="return confirm(<?= json_encode('Delete subscriber "' . $sub['email'] . '"? This cannot be undone.', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)">
                            Delete
                        </button>
                    </form>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

</main>

<script src="/admin/assets/admin.js"></script>
</body>
</html>
