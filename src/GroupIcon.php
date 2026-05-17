<?php
/**
 * LetaDial — Group Icon (sesja 052)
 *
 * Handles custom image icons for groups.
 * Stored as 32×32 WebP in storage/group_icons/u{userId}/{groupId}.webp
 * ALL access via PHP — directory is deny-all in .htaccess.
 *
 * Security model (same as Thumbnail.php):
 *   - imagecreatefromstring() validates pixel data (type-safe, not MIME-based)
 *   - GD re-encodes only pixel data → strips ALL metadata/EXIF/embedded payloads
 *   - Always re-encoded to WebP regardless of input format
 *   - Even a WebP upload is decoded then re-encoded (no passthrough)
 *   - Accepts: JPEG, PNG, GIF, WebP — max 2 MB
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

class GroupIcon
{
    private const ICON_W    = 32;    // output pixels
    private const ICON_H    = 32;
    private const QUALITY   = 90;   // WebP quality
    private const MAX_BYTES = 2 * 1024 * 1024; // 2 MB

    // ── Paths ─────────────────────────────────────────────────────────────────

    private static function dir(int $userId): string
    {
        return __DIR__ . '/../storage/group_icons/u' . $userId;
    }

    public static function filePath(int $groupId, int $userId): string
    {
        return self::dir($userId) . '/' . $groupId . '.webp';
    }

    public static function webUrl(int $groupId): string
    {
        return '/api/group_icons/' . $groupId;
    }

    // ── Upload & Process ──────────────────────────────────────────────────────

    /**
     * Process an uploaded image and save as 32×32 WebP.
     * Uses GD imagecreatefromstring — validates and decodes pixel data.
     * This approach is type-safe: PHP actually decodes the image;
     * if any byte is malformed or not a real image it fails here.
     *
     * @param string $tmpPath PHP temp file path ($_FILES['icon']['tmp_name'])
     */
    public static function processUpload(int $groupId, int $userId, string $tmpPath): bool
    {
        if (!file_exists($tmpPath)) return false;
        if (filesize($tmpPath) > self::MAX_BYTES) return false;
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagewebp')) return false;

        // Read raw bytes
        $raw = file_get_contents($tmpPath);
        if ($raw === false || strlen($raw) === 0) return false;

        // imagecreatefromstring decodes pixel data — fails on non-image bytes
        $src = @imagecreatefromstring($raw);
        if ($src === false) return false;

        $sw = imagesx($src);
        $sh = imagesy($src);

        if ($sw === 0 || $sh === 0) { imagedestroy($src); return false; }

        // Create output canvas
        $dst = @imagecreatetruecolor(self::ICON_W, self::ICON_H);
        if (!$dst) { imagedestroy($src); return false; }

        // White background — transparent PNGs/GIFs become white, not black
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, self::ICON_W - 1, self::ICON_H - 1, $white);

        // High-quality resample
        imagecopyresampled($dst, $src, 0, 0, 0, 0, self::ICON_W, self::ICON_H, $sw, $sh);
        imagedestroy($src);

        // Ensure storage directory exists
        $dir = self::dir($userId);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) { imagedestroy($dst); return false; }
        }

        // Protect directory on first use
        $htaccess = $dir . '/../.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Options -Indexes\nOrder deny,allow\nDeny from all\n");
        }

        // Save as WebP
        $out = self::filePath($groupId, $userId);
        $ok  = imagewebp($dst, $out, self::QUALITY);
        imagedestroy($dst);

        if (!$ok) return false;

        @chmod($out, 0644);

        // Store path in DB
        DB::run(
            "UPDATE groups_list SET icon_path = ? WHERE id = ? AND user_id = ?",
            [$out, $groupId, $userId]
        );

        return true;
    }

    // ── Serve ─────────────────────────────────────────────────────────────────

    /**
     * Stream the icon to the browser with cache headers.
     * Call before any HTML output.
     */
    public static function serve(int $groupId, int $userId): void
    {
        $path = self::filePath($groupId, $userId);

        if (!file_exists($path)) {
            http_response_code(404);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => 'Icon not found.']);
            return;
        }

        $mtime = (int)filemtime($path);
        $etag  = '"gi-' . $groupId . '-' . $mtime . '"';

        header('Content-Type: image/webp');
        header('Cache-Control: private, max-age=3600');
        header('ETag: ' . $etag);
        header('Content-Length: ' . filesize($path));

        if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            return;
        }

        readfile($path);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    /**
     * Delete the icon file and clear icon_path in DB.
     * Best-effort — does not throw on missing file.
     */
    public static function delete(int $groupId, int $userId): void
    {
        $path = self::filePath($groupId, $userId);
        if (file_exists($path)) {
            @unlink($path);
        }

        DB::run(
            "UPDATE groups_list SET icon_path = NULL WHERE id = ? AND user_id = ?",
            [$groupId, $userId]
        );
    }
}
