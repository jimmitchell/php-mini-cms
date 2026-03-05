<?php

declare(strict_types=1);

namespace CMS;

class Post
{
    public ?int    $id           = null;
    public string  $title        = '';
    public string  $slug         = '';
    public string  $content      = '';
    public ?string $excerpt      = null;
    public string  $status       = 'draft';
    public ?string $published_at = null;
    public string  $created_at   = '';
    public string  $updated_at   = '';
    public ?string $built_at     = null;
    public ?string $content_hash = null;
    public ?string $tooted_at    = null;
    public ?string $mastodon_url = null;
    public int     $mastodon_skip = 0;
    public ?string $bluesky_at   = null;
    public ?string $bluesky_url  = null;
    public int     $bluesky_skip  = 0;
    public ?string $og_image_hash      = null;
    public ?string $webmentions_sent_at = null;

    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // ── Finders ───────────────────────────────────────────────────────────────

    /**
     * Return all posts, optionally filtered by status.
     * Ordered by published_at DESC, then created_at DESC.
     *
     * @return self[]
     */
    public static function findAll(Database $db, ?string $status = null): array
    {
        if ($status !== null) {
            $rows = $db->select(
                "SELECT * FROM posts WHERE status = :status ORDER BY published_at DESC, created_at DESC",
                ['status' => $status]
            );
        } else {
            $rows = $db->select(
                "SELECT * FROM posts ORDER BY published_at DESC, created_at DESC"
            );
        }

        return array_map(fn($row) => self::fromRow($db, $row), $rows);
    }

    public static function findById(Database $db, int $id): ?self
    {
        $row = $db->selectOne("SELECT * FROM posts WHERE id = :id", ['id' => $id]);
        return $row ? self::fromRow($db, $row) : null;
    }

    public static function findBySlug(Database $db, string $slug): ?self
    {
        $row = $db->selectOne("SELECT * FROM posts WHERE slug = :slug", ['slug' => $slug]);
        return $row ? self::fromRow($db, $row) : null;
    }

    // ── Persistence ───────────────────────────────────────────────────────────

    /**
     * Insert (new) or update (existing) the post. Returns true on success.
     */
    public function save(): bool
    {
        $data = [
            'title'         => $this->title,
            'slug'          => $this->slug,
            'content'       => $this->content,
            'excerpt'       => $this->excerpt,
            'status'        => $this->status,
            'published_at'  => $this->published_at,
            'mastodon_skip' => $this->mastodon_skip,
            'bluesky_skip'  => $this->bluesky_skip,
            'updated_at'    => date('Y-m-d H:i:s'),
        ];

        if ($this->id === null) {
            $this->id = $this->db->insert('posts', $data);
            return $this->id > 0;
        }

        $affected = $this->db->update('posts', $data, 'id = :id', ['id' => $this->id]);
        return $affected >= 0; // 0 rows affected is still "ok" (no changes)
    }

    public function delete(): bool
    {
        if ($this->id === null) {
            return false;
        }
        $affected = $this->db->delete('posts', 'id = :id', ['id' => $this->id]);
        return $affected > 0;
    }

    // ── Build helpers ─────────────────────────────────────────────────────────

    /**
     * Mark this post as built: store the hash and timestamp.
     */
    public function markBuilt(string $contentHash): void
    {
        $now              = date('Y-m-d H:i:s');
        $this->built_at   = $now;
        $this->content_hash = $contentHash;

        $this->db->update(
            'posts',
            ['built_at' => $now, 'content_hash' => $contentHash],
            'id = :id',
            ['id' => $this->id]
        );
    }

    /**
     * Record that an OG image was generated with the given hash.
     */
    public function markOgBuilt(string $hash): void
    {
        $this->og_image_hash = $hash;

        $this->db->update(
            'posts',
            ['og_image_hash' => $hash],
            'id = :id',
            ['id' => $this->id]
        );
    }

    /**
     * Record that this post was successfully posted to Bluesky.
     * Optionally stores the canonical bsky.app URL.
     */
    public function markBluesky(string $url = ''): void
    {
        $now              = date('Y-m-d H:i:s');
        $this->bluesky_at = $now;
        $cols = ['bluesky_at' => $now];

        if ($url !== '') {
            $this->bluesky_url = $url;
            $cols['bluesky_url'] = $url;
        }

        $this->db->update('posts', $cols, 'id = :id', ['id' => $this->id]);
    }

    /**
     * Record that outgoing webmentions were sent for this post.
     */
    public function markWebmentionsSent(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->webmentions_sent_at = $now;
        $this->db->update('posts', ['webmentions_sent_at' => $now], 'id = :id', ['id' => $this->id]);
    }

    /**
     * Record that this post was successfully tooted to Mastodon.
     * Optionally stores the canonical toot URL.
     */
    public function markTooted(string $url = ''): void
    {
        $now             = date('Y-m-d H:i:s');
        $this->tooted_at = $now;
        $cols = ['tooted_at' => $now];

        if ($url !== '') {
            $this->mastodon_url = $url;
            $cols['mastodon_url'] = $url;
        }

        $this->db->update('posts', $cols, 'id = :id', ['id' => $this->id]);
    }

    /**
     * Returns true if the rendered HTML would differ from the stored hash.
     * The actual hash comparison is done by Builder; this is a quick shortcut
     * based on updated_at vs built_at.
     */
    public function needsRebuild(): bool
    {
        if ($this->built_at === null || $this->content_hash === null) {
            return true;
        }
        return strtotime($this->updated_at) > strtotime($this->built_at);
    }

    // ── Excerpt resolution ────────────────────────────────────────────────────

    /**
     * Returns the effective excerpt for display:
     *  1. Explicit excerpt (stored, user-entered plain text) — returned as-is.
     *  2. Text before <!--more--> in the post content — Markdown stripped.
     *  3. Auto-generated: first 200 characters of plain-text content with ellipsis.
     */
    public function effectiveExcerpt(): ?string
    {
        if ($this->excerpt !== null && $this->excerpt !== '') {
            return $this->excerpt;
        }

        $pos = strpos($this->content, '<!--more-->');
        if ($pos !== false) {
            $plain = self::plaintextFromMarkdown(substr($this->content, 0, $pos));
            return $plain !== '' ? $plain : null;
        }

        // Auto-generate from the full content.
        $plain = self::plaintextFromMarkdown($this->content);
        if ($plain === '') {
            return null;
        }
        if (mb_strlen($plain) <= 200) {
            return $plain;
        }
        $truncated = mb_substr($plain, 0, 200);
        $lastSpace = mb_strrpos($truncated, ' ');
        if ($lastSpace !== false) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }
        return rtrim($truncated) . '…';
    }

    /**
     * Strip common Markdown syntax and HTML tags, returning normalized plain text.
     */
    private static function plaintextFromMarkdown(string $md): string
    {
        $text = strip_tags($md);
        $text = preg_replace('/^#{1,6}\h+/m', '', $text);              // headings
        $text = preg_replace('/(\*{1,3}|_{1,3})(.*?)\1/s', '$2', $text); // bold/italic
        $text = preg_replace('/~~(.+?)~~/s', '$1', $text);             // strikethrough
        $text = preg_replace('/`+[^`]*`+/', '', $text);                // inline code
        $text = preg_replace('/!\[[^\]]*\]\([^\)]*\)/', '', $text);    // images
        $text = preg_replace('/\[([^\]]+)\]\([^\)]*\)/', '$1', $text); // links
        $text = preg_replace('/\[([^\]]+)\]\[[^\]]*\]/', '$1', $text); // ref links
        $text = preg_replace('/^>\h*/m', '', $text);                   // blockquotes
        $text = preg_replace('/^[-*_]{3,}\h*$/m', '', $text);         // hr
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    // ── URL helpers ───────────────────────────────────────────────────────────

    /**
     * Returns the date + slug path segment used in public URLs and file paths.
     * e.g. "2026/03/01/my-post-slug"  (no leading or trailing slash)
     */
    public static function datePath(string $published_at, string $slug): string
    {
        $ts = strtotime($published_at);
        return date('Y/m/d', $ts) . '/' . $slug;
    }

    // ── Adjacent post navigation ──────────────────────────────────────────────

    /**
     * The nearest published post older than this one (for "← Previous" links).
     */
    public static function findPrev(Database $db, self $post): ?self
    {
        if ($post->published_at === null) {
            return null;
        }
        $row = $db->selectOne(
            "SELECT * FROM posts
              WHERE status = 'published'
                AND published_at < :pub
              ORDER BY published_at DESC
              LIMIT 1",
            ['pub' => $post->published_at]
        );
        return $row ? self::fromRow($db, $row) : null;
    }

    /**
     * The nearest published post newer than this one (for "Next →" links).
     */
    public static function findNext(Database $db, self $post): ?self
    {
        if ($post->published_at === null) {
            return null;
        }
        $row = $db->selectOne(
            "SELECT * FROM posts
              WHERE status = 'published'
                AND published_at > :pub
              ORDER BY published_at ASC
              LIMIT 1",
            ['pub' => $post->published_at]
        );
        return $row ? self::fromRow($db, $row) : null;
    }

    // ── Scheduled post promotion ──────────────────────────────────────────────

    /**
     * Flip any due scheduled posts to 'published'.
     * Returns the IDs of promoted posts (for the caller to rebuild).
     *
     * @return int[]
     */
    public static function promoteScheduled(Database $db): array
    {
        $due = $db->select(
            "SELECT id FROM posts
              WHERE status = 'scheduled'
                AND published_at <= datetime('now')"
        );

        if (empty($due)) {
            return [];
        }

        $ids = array_column($due, 'id');

        foreach ($ids as $id) {
            $db->update(
                'posts',
                ['status' => 'published', 'updated_at' => date('Y-m-d H:i:s')],
                'id = :id',
                ['id' => $id]
            );
        }

        return $ids;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private static function fromRow(Database $db, array $row): self
    {
        $post               = new self($db);
        $post->id           = (int) $row['id'];
        $post->title        = $row['title'];
        $post->slug         = $row['slug'];
        $post->content      = $row['content'];
        $post->excerpt      = $row['excerpt'] ?? null;
        $post->status       = $row['status'];
        $post->published_at = $row['published_at'] ?? null;
        $post->created_at   = $row['created_at'] ?? '';
        $post->updated_at   = $row['updated_at'] ?? '';
        $post->built_at     = $row['built_at'] ?? null;
        $post->content_hash = $row['content_hash'] ?? null;
        $post->tooted_at     = $row['tooted_at']    ?? null;
        $post->mastodon_url  = $row['mastodon_url'] ?? null;
        $post->mastodon_skip = (int) ($row['mastodon_skip'] ?? 0);
        $post->bluesky_at    = $row['bluesky_at']   ?? null;
        $post->bluesky_url   = $row['bluesky_url']  ?? null;
        $post->bluesky_skip  = (int) ($row['bluesky_skip']  ?? 0);
        $post->og_image_hash       = $row['og_image_hash']       ?? null;
        $post->webmentions_sent_at = $row['webmentions_sent_at'] ?? null;
        return $post;
    }
}
