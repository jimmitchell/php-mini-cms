<?php

declare(strict_types=1);

namespace CMS;

use RuntimeException;

class Media
{
    /** MIME types the CMS will accept. */
    private const ALLOWED_MIME = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/gif'       => 'gif',
        'image/webp'      => 'webp',
        'image/svg+xml'   => 'svg',
        'video/mp4'       => 'mp4',
        'video/webm'      => 'webm',
        'audio/mpeg'      => 'mp3',
        'audio/ogg'       => 'ogg',
    ];

    /** Default max upload size in bytes (50 MB). */
    private const DEFAULT_MAX_BYTES = 52_428_800;

    private Database $db;
    private string   $storageDir;
    private int      $maxBytes;

    public function __construct(Database $db, string $storageDir, int $maxBytes = self::DEFAULT_MAX_BYTES)
    {
        $this->db         = $db;
        $this->storageDir = rtrim($storageDir, '/\\');
        $this->maxBytes   = $maxBytes;

        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0775, true);
        }
    }

    // ── Upload ────────────────────────────────────────────────────────────────

    /**
     * Process a single file from $_FILES.
     *
     * @param  array{name:string,tmp_name:string,size:int,error:int} $file
     * @return array{id:int,filename:string,url:string}
     * @throws RuntimeException on validation failure or I/O error
     */
    public function upload(array $file): array
    {
        // 1. PHP upload error codes.
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->phpUploadError($file['error']));
        }

        // 2. Size check (server-side — php.ini limits may catch it earlier).
        if ($file['size'] > $this->maxBytes) {
            $mb = round($this->maxBytes / 1_048_576);
            throw new RuntimeException("File exceeds the {$mb} MB size limit.");
        }

        // 3. MIME type via fileinfo (not the browser-supplied type).
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!array_key_exists($mimeType, self::ALLOWED_MIME)) {
            throw new RuntimeException("File type '{$mimeType}' is not allowed.");
        }

        // 4. Generate a safe, unique filename.
        $originalName = $file['name'];
        $safeName     = $this->safeFilename($originalName, self::ALLOWED_MIME[$mimeType]);
        $destPath     = $this->storageDir . '/' . $safeName;

        // 5. Move the uploaded file.
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new RuntimeException('Could not save the uploaded file.');
        }

        // 5a. Generate a WebP companion for JPEG/PNG uploads (non-fatal).
        $ext = self::ALLOWED_MIME[$mimeType];
        if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            $this->generateWebp($destPath);
        }

        // 6. Insert DB record.
        $id = $this->db->insert('media', [
            'filename'      => $safeName,
            'original_name' => $originalName,
            'mime_type'     => $mimeType,
            'size'          => $file['size'],
        ]);

        return [
            'id'       => $id,
            'filename' => $safeName,
            'url'      => '/media/' . rawurlencode($safeName),
        ];
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    /**
     * Delete a media item by DB id: removes the file and the DB row.
     * Returns true on success, false if the item wasn't found.
     */
    public function delete(int $id): bool
    {
        $row = $this->db->selectOne("SELECT filename FROM media WHERE id = :id", ['id' => $id]);
        if (!$row) {
            return false;
        }

        // Use basename() to prevent any path traversal in stored filenames.
        $path = $this->storageDir . '/' . basename($row['filename']);
        if (file_exists($path)) {
            unlink($path);
        }

        // Remove WebP companion if one was generated.
        $webpPath = (string) preg_replace('/\.[^.]+$/', '.webp', $path);
        if ($webpPath !== $path && file_exists($webpPath)) {
            unlink($webpPath);
        }

        $this->db->delete('media', 'id = :id', ['id' => $id]);
        return true;
    }

    // ── List ──────────────────────────────────────────────────────────────────

    /**
     * Return all media records, newest first.
     *
     * @return array<array<string,mixed>>
     */
    public function all(): array
    {
        return $this->db->select(
            "SELECT id, filename, original_name, mime_type, size, uploaded_at
               FROM media
              ORDER BY uploaded_at DESC"
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Accepted MIME types for use in HTML accept= attributes.
     */
    public static function acceptAttribute(): string
    {
        return implode(',', array_keys(self::ALLOWED_MIME));
    }

    public static function isImage(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/');
    }

    public static function isVideo(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'video/');
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1_048_576) {
            return round($bytes / 1_048_576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Generate a collision-safe filename while keeping the original name readable.
     * Pattern: {sanitized_stem}_{8_hex_chars}.{canonical_ext}
     */
    private function safeFilename(string $originalName, string $canonicalExt): string
    {
        // Strip path components — never trust client input.
        $base = basename($originalName);

        // Remove the extension from the base and sanitize.
        $stem = pathinfo($base, PATHINFO_FILENAME);
        $stem = strtolower($stem);
        $stem = preg_replace('/[^a-z0-9_-]+/', '-', $stem);
        $stem = trim($stem, '-') ?: 'file';

        // Truncate long stems.
        $stem = mb_substr($stem, 0, 60);

        $unique = bin2hex(random_bytes(4)); // 8 hex chars

        $candidate = "{$stem}_{$unique}.{$canonicalExt}";

        // In the (astronomically unlikely) event of collision, append more entropy.
        $attempts = 0;
        while (file_exists($this->storageDir . '/' . $candidate)) {
            $unique    = bin2hex(random_bytes(4));
            $candidate = "{$stem}_{$unique}.{$canonicalExt}";
            if (++$attempts > 10) {
                throw new RuntimeException('Could not generate a unique filename.');
            }
        }

        return $candidate;
    }

    /**
     * Generate a .webp companion file alongside a JPEG or PNG source.
     * Silently skips if GD is unavailable or conversion fails.
     */
    private function generateWebp(string $sourcePath): void
    {
        if (!extension_loaded('gd')) {
            return;
        }

        $ext   = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        $image = match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($sourcePath),
            'png'         => @imagecreatefrompng($sourcePath),
            default       => false,
        };

        if ($image === false) {
            return;
        }

        // Preserve PNG transparency.
        if ($ext === 'png') {
            imagepalettetotruecolor($image);
            imagealphablending($image, true);
            imagesavealpha($image, true);
        }

        $destPath = (string) preg_replace('/\.[^.]+$/', '.webp', $sourcePath);
        @imagewebp($image, $destPath, 82);
        imagedestroy($image);
    }

    private function phpUploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the allowed size.',
            UPLOAD_ERR_PARTIAL   => 'The file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE   => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary directory.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
            default              => 'Unknown upload error (code ' . $code . ').',
        };
    }
}
