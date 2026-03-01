<?php

declare(strict_types=1);

namespace CMS;

class Page
{
    public ?int    $id           = null;
    public string  $title        = '';
    public string  $slug         = '';
    public string  $content      = '';
    public int     $nav_order    = 0;
    public string  $status       = 'draft';
    public string  $created_at   = '';
    public string  $updated_at   = '';
    public ?string $built_at     = null;
    public ?string $content_hash = null;

    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // ── Finders ───────────────────────────────────────────────────────────────

    /**
     * Return all pages, optionally filtered by status.
     * Ordered by nav_order ASC, then title ASC.
     *
     * @return self[]
     */
    public static function findAll(Database $db, ?string $status = null): array
    {
        if ($status !== null) {
            $rows = $db->select(
                "SELECT * FROM pages WHERE status = :status ORDER BY nav_order ASC, title ASC",
                ['status' => $status]
            );
        } else {
            $rows = $db->select(
                "SELECT * FROM pages ORDER BY nav_order ASC, title ASC"
            );
        }

        return array_map(fn($row) => self::fromRow($db, $row), $rows);
    }

    public static function findById(Database $db, int $id): ?self
    {
        $row = $db->selectOne("SELECT * FROM pages WHERE id = :id", ['id' => $id]);
        return $row ? self::fromRow($db, $row) : null;
    }

    public static function findBySlug(Database $db, string $slug): ?self
    {
        $row = $db->selectOne("SELECT * FROM pages WHERE slug = :slug", ['slug' => $slug]);
        return $row ? self::fromRow($db, $row) : null;
    }

    // ── Persistence ───────────────────────────────────────────────────────────

    public function save(): bool
    {
        $data = [
            'title'     => $this->title,
            'slug'      => $this->slug,
            'content'   => $this->content,
            'nav_order' => $this->nav_order,
            'status'    => $this->status,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($this->id === null) {
            $this->id = $this->db->insert('pages', $data);
            return $this->id > 0;
        }

        $affected = $this->db->update('pages', $data, 'id = :id', ['id' => $this->id]);
        return $affected >= 0;
    }

    public function delete(): bool
    {
        if ($this->id === null) {
            return false;
        }
        return $this->db->delete('pages', 'id = :id', ['id' => $this->id]) > 0;
    }

    // ── Build helpers ─────────────────────────────────────────────────────────

    public function markBuilt(string $contentHash): void
    {
        $now              = date('Y-m-d H:i:s');
        $this->built_at   = $now;
        $this->content_hash = $contentHash;

        $this->db->update(
            'pages',
            ['built_at' => $now, 'content_hash' => $contentHash],
            'id = :id',
            ['id' => $this->id]
        );
    }

    public function needsRebuild(): bool
    {
        if ($this->built_at === null || $this->content_hash === null) {
            return true;
        }
        return strtotime($this->updated_at) > strtotime($this->built_at);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private static function fromRow(Database $db, array $row): self
    {
        $page               = new self($db);
        $page->id           = (int) $row['id'];
        $page->title        = $row['title'];
        $page->slug         = $row['slug'];
        $page->content      = $row['content'];
        $page->nav_order    = (int) ($row['nav_order'] ?? 0);
        $page->status       = $row['status'];
        $page->created_at   = $row['created_at'] ?? '';
        $page->updated_at   = $row['updated_at'] ?? '';
        $page->built_at     = $row['built_at'] ?? null;
        $page->content_hash = $row['content_hash'] ?? null;
        return $page;
    }
}
