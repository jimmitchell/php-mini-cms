<?php

declare(strict_types=1);

namespace CMS;

class ActivityLog
{
    public function __construct(private Database $db) {}

    /**
     * Record an admin action.
     *
     * @param string   $action      e.g. 'create', 'update', 'publish', 'delete', 'upload', 'settings', 'password'
     * @param string   $objectType  e.g. 'post', 'page', 'media', 'settings', 'account'
     * @param int|null $objectId    DB id of the affected record (null for settings/account)
     * @param string   $detail      Human-readable label (post title, filename, etc.)
     */
    public function log(
        string $action,
        string $objectType,
        ?int   $objectId = null,
        string $detail   = ''
    ): void {
        $this->db->insert('activity_log', [
            'action'      => $action,
            'object_type' => $objectType,
            'object_id'   => $objectId,
            'detail'      => $detail,
            'ip'          => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        ]);
    }
}
