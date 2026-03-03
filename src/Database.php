<?php

declare(strict_types=1);

namespace CMS;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

class Database
{
    private PDO $pdo;

    // Increment this whenever the schema changes.
    private const SCHEMA_VERSION = 6;

    public function __construct(string $dbPath)
    {
        try {
            $this->pdo = new PDO(
                'sqlite:' . $dbPath,
                null,
                null,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
            $this->pdo->exec('PRAGMA journal_mode=WAL');
            $this->pdo->exec('PRAGMA foreign_keys=ON');
        } catch (PDOException $e) {
            throw new RuntimeException('Cannot open database: ' . $e->getMessage());
        }

        $this->migrate();
    }

    // ── Query helpers ─────────────────────────────────────────────────────────

    /**
     * Run a SELECT and return all rows.
     *
     * @param array<mixed> $params
     * @return array<array<string,mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        $stmt = $this->run($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * Run a SELECT and return a single row (or null).
     *
     * @param array<mixed> $params
     * @return array<string,mixed>|null
     */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->run($sql, $params);
        $row  = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Execute an INSERT and return the last insert ID.
     *
     * @param array<string,mixed> $data  column => value
     */
    public function insert(string $table, array $data): int
    {
        $cols        = array_keys($data);
        $placeholders = implode(', ', array_map(fn($c) => ':' . $c, $cols));
        $colList     = implode(', ', $cols);

        $this->run(
            "INSERT INTO {$table} ({$colList}) VALUES ({$placeholders})",
            $data
        );

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Execute an UPDATE. $where is a raw SQL snippet (e.g. "id = :id").
     * Merge $data and $whereParams so all bindings go into one execute().
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $whereParams
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = implode(', ', array_map(fn($c) => "{$c} = :{$c}", array_keys($data)));

        $stmt = $this->run(
            "UPDATE {$table} SET {$sets} WHERE {$where}",
            array_merge($data, $whereParams)
        );

        return $stmt->rowCount();
    }

    /**
     * Execute a DELETE. $where is a raw SQL snippet.
     *
     * @param array<string,mixed> $params
     */
    public function delete(string $table, string $where, array $params = []): int
    {
        $stmt = $this->run("DELETE FROM {$table} WHERE {$where}", $params);
        return $stmt->rowCount();
    }

    /**
     * Execute arbitrary SQL (useful for CREATE TABLE, PRAGMA, etc.).
     *
     * @param array<mixed> $params
     */
    public function exec(string $sql, array $params = []): PDOStatement
    {
        return $this->run($sql, $params);
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /** @param array<mixed> $params */
    private function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    // ── Schema / migrations ───────────────────────────────────────────────────

    private function migrate(): void
    {
        // Ensure settings table exists first (stores schema_version).
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS settings (
                key        TEXT PRIMARY KEY,
                value      TEXT,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        SQL);

        $row     = $this->selectOne("SELECT value FROM settings WHERE key = 'schema_version'");
        $current = $row ? (int) $row['value'] : 0;

        if ($current < 1) {
            $this->applySchemaV1();
        }

        if ($current < 2) {
            $this->applySchemaV2();
        }

        if ($current < 3) {
            $this->applySchemaV3();
        }

        if ($current < 4) {
            $this->applySchemaV4();
        }

        if ($current < 5) {
            $this->applySchemaV5();
        }

        if ($current < 6) {
            $this->applySchemaV6();
        }

        if ($current < self::SCHEMA_VERSION) {
            $this->upsertSetting('schema_version', (string) self::SCHEMA_VERSION);
        }
    }

    private function applySchemaV1(): void
    {
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS posts (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                title        TEXT    NOT NULL,
                slug         TEXT    UNIQUE NOT NULL,
                content      TEXT    NOT NULL,
                excerpt      TEXT,
                status       TEXT    NOT NULL DEFAULT 'draft',
                published_at DATETIME,
                created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
                built_at     DATETIME,
                content_hash TEXT
            )
        SQL);

        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS pages (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                title        TEXT    NOT NULL,
                slug         TEXT    UNIQUE NOT NULL,
                content      TEXT    NOT NULL,
                nav_order    INTEGER DEFAULT 0,
                status       TEXT    NOT NULL DEFAULT 'draft',
                created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
                built_at     DATETIME,
                content_hash TEXT
            )
        SQL);

        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS media (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                filename      TEXT    NOT NULL,
                original_name TEXT    NOT NULL,
                mime_type     TEXT    NOT NULL,
                size          INTEGER NOT NULL,
                uploaded_at   DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        SQL);

        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS login_attempts (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                ip           TEXT    NOT NULL,
                attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                success      INTEGER DEFAULT 0
            )
        SQL);

        // Seed default settings if they don't exist yet.
        $defaults = [
            'site_title'       => 'My CMS',
            'site_description' => '',
            'site_url'         => '',
            'posts_per_page'   => '10',
            'feed_post_count'  => '20',
            'footer_text'      => '',
        ];
        foreach ($defaults as $key => $value) {
            $this->pdo->exec(
                "INSERT OR IGNORE INTO settings (key, value) VALUES ('{$key}', " .
                $this->pdo->quote($value) . ")"
            );
        }
    }

    private function applySchemaV2(): void
    {
        // Add tooted_at column to track Mastodon syndication (NULL = not yet tooted).
        $this->pdo->exec("ALTER TABLE posts ADD COLUMN tooted_at DATETIME");
    }

    private function applySchemaV3(): void
    {
        // 0 = send to Mastodon (default), 1 = user opted out for this post.
        $this->pdo->exec("ALTER TABLE posts ADD COLUMN mastodon_skip INTEGER NOT NULL DEFAULT 0");
    }

    private function applySchemaV4(): void
    {
        // Index makes the rate-limiting COUNT(*) query efficient as the table grows.
        $this->pdo->exec(
            "CREATE INDEX IF NOT EXISTS idx_login_attempts_ip_time
             ON login_attempts(ip, attempted_at)"
        );
    }

    private function applySchemaV5(): void
    {
        // Hash of (site_title | post_title) used when the OG image was last generated.
        // NULL means no OG image has been generated yet.
        $this->pdo->exec("ALTER TABLE posts ADD COLUMN og_image_hash TEXT");
    }

    private function applySchemaV6(): void
    {
        // Track Bluesky crossposting — mirrors tooted_at / mastodon_skip.
        $this->pdo->exec("ALTER TABLE posts ADD COLUMN bluesky_at   DATETIME");
        $this->pdo->exec("ALTER TABLE posts ADD COLUMN bluesky_skip INTEGER NOT NULL DEFAULT 0");
    }

    /** Insert or update a single settings row. */
    public function upsertSetting(string $key, string $value): void
    {
        $this->run(
            "INSERT INTO settings (key, value, updated_at)
             VALUES (:key, :value, CURRENT_TIMESTAMP)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at",
            ['key' => $key, 'value' => $value]
        );
    }

    /** Retrieve a single setting value (or $default if not found). */
    public function getSetting(string $key, string $default = ''): string
    {
        $row = $this->selectOne("SELECT value FROM settings WHERE key = :key", ['key' => $key]);
        return $row ? $row['value'] : $default;
    }

    /** Retrieve all settings as key => value array. */
    public function getAllSettings(): array
    {
        $rows = $this->select("SELECT key, value FROM settings");
        $out  = [];
        foreach ($rows as $row) {
            $out[$row['key']] = $row['value'];
        }
        return $out;
    }
}
