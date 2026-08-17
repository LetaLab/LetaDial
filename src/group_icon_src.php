<?php
/**
 * LetaDial — Group Icon (sesja 052 + BUG-018 + BUG-020)
 *
 * Handles custom image icons for groups.
 * Stored as 32×32 WebP in storage/group_icons/u{userId}/{groupId}.webp
 * ALL access via PHP — directory is deny-all in .htaccess.
 *
 * Security model (same as thumbnail_src.php):
 *   - imagecreatefromstring() validates pixel data (type-safe, not MIME-based)
 *   - GD re-encodes only pixel data → strips ALL metadata/EXIF/embedded payloads
 *   - Always re-encoded to WebP regardless of input format
 *   - Even a WebP upload is decoded then re-encoded (no passthrough)
 *   - Accepts: JPEG, PNG, GIF, WebP — max 2 MB
 *
 * BUG-018: processUpload() now explicitly unlinks the upload temp file on
 * every exit path (cleanupTmp()), instead of relying solely on PHP's
 * post-request purge — mirrors avatar_src.php's existing pattern.
 * BUG-020: processUpload() now applies best-effort EXIF orientation
 * correction (applyExifOrientation()) before re-encoding, so a phone photo
 * used as a group icon does not come out sideways — mirrors avatar_src.php.
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

class GroupIcon
{
    private const ICON_W    = 32;    // output pixels
    private const ICON_H    = 32;
    private const QUALITY   = 90;   // WebP quality
    private const MAX_BYTES = 2 * 1024 * 1024; // 2 MB
    // SEC-090: reject images with a declared width/height above this BEFORE
    // imagecreatefromstring() fully decodes them — same rationale as
    // avatar_src.php's MAX_DIMENSION (GD has no configurable internal resource
    // limit the way Imagick does, so the dimension pre-check IS the guard).
    private const MAX_DIMENSION = 8000;

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
     * BUG-018: the upload temp file is now explicitly unlinked on every exit
     * path via cleanupTmp() below, mirroring Avatar::processUpload()'s
     * "belt-and-suspenders" pattern — PHP already purges $_FILES tmp files
     * once the request ends, this just closes that window sooner.
     *
     * BUG-020: best-effort EXIF orientation correction (applyExifOrientation()
     * below) is now applied to the decoded pixels before re-encoding, same as
     * avatar_src.php — a phone photo used as a group icon no longer comes out
     * sideways after its orientation tag is stripped by the WebP re-encode.
     *
     * @param string $tmpPath PHP temp file path ($_FILES['icon']['tmp_name'])
     */
    public static function processUpload(int $groupId, int $userId, string $tmpPath): bool
    {
        if (!file_exists($tmpPath)) return false;
        if (filesize($tmpPath) > self::MAX_BYTES) { self::cleanupTmp($tmpPath); return false; }
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagewebp')) {
            self::cleanupTmp($tmpPath);
            return false;
        }

        // Read raw bytes
        $raw = file_get_contents($tmpPath);
        if ($raw === false || strlen($raw) === 0) { self::cleanupTmp($tmpPath); return false; }

        // SEC-090: getimagesizefromstring() only parses the image header —
        // it does NOT allocate a full decoded pixel buffer, so it stays
        // cheap even for a "decompression bomb" (a tiny compressed file
        // whose header declares huge dimensions). Reject oversized images
        // here, BEFORE imagecreatefromstring() below performs the actual
        // full decode. See avatar_src.php for the identical, more thoroughly
        // commented version of this check.
        $dims = @getimagesizefromstring($raw);
        if (!$dims || $dims[0] < 1 || $dims[1] < 1
            || $dims[0] > self::MAX_DIMENSION || $dims[1] > self::MAX_DIMENSION) {
            self::cleanupTmp($tmpPath);
            return false;
        }

        // imagecreatefromstring decodes pixel data — fails on non-image bytes
        $src = @imagecreatefromstring($raw);
        if ($src === false) { self::cleanupTmp($tmpPath); return false; }

        // BUG-020: best-effort EXIF orientation correction, applied to the
        // decoded pixels BEFORE the resize below discards the source aspect
        // ratio. Only meaningful for JPEG, only runs if ext-exif is loaded —
        // never required for upload to work, purely cosmetic.
        $src = self::applyExifOrientation($src, $tmpPath);

        $sw = imagesx($src);
        $sh = imagesy($src);

        if ($sw === 0 || $sh === 0) { imagedestroy($src); self::cleanupTmp($tmpPath); return false; }

        // Create output canvas
        $dst = @imagecreatetruecolor(self::ICON_W, self::ICON_H);
        if (!$dst) { imagedestroy($src); self::cleanupTmp($tmpPath); return false; }

        // White background — transparent PNGs/GIFs become white, not black
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, self::ICON_W - 1, self::ICON_H - 1, $white);

        // High-quality resample
        imagecopyresampled($dst, $src, 0, 0, 0, 0, self::ICON_W, self::ICON_H, $sw, $sh);
        imagedestroy($src);

        // Ensure storage directory exists
        $dir = self::dir($userId);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) { imagedestroy($dst); self::cleanupTmp($tmpPath); return false; }
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

        if (!$ok) { self::cleanupTmp($tmpPath); return false; }

        @chmod($out, 0644);

        // Store path in DB
        DB::run(
            "UPDATE groups_list SET icon_path = ? WHERE id = ? AND user_id = ?",
            [$out, $groupId, $userId]
        );

        // BUG-018: the user's original upload never persists past this point.
        self::cleanupTmp($tmpPath);

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

    // ── Private helpers (BUG-018 / BUG-020) ──────────────────────────────────

    /**
     * BUG-018: explicitly unlink the upload temp file. PHP already deletes
     * $_FILES tmp files once the request ends — this just closes the window
     * sooner, the same "belt-and-suspenders" pattern already used by
     * Avatar::cleanupTmp().
     */
    private static function cleanupTmp(string $tmpPath): void
    {
        if (is_file($tmpPath) && str_starts_with($tmpPath, sys_get_temp_dir())) {
            @unlink($tmpPath);
        }
    }

    /**
     * BUG-020: best-effort EXIF orientation correction, copied from
     * Avatar::applyExifOrientation(). Only meaningful for JPEG, only runs if
     * ext-exif is loaded (optional — never required for group icon upload to
     * work). Handles the three pure-rotation cases (3/6/8) that cover the
     * overwhelming majority of real phone photos; the rare mirrored variants
     * (2/4/5/7, mostly from flatbed scanners) are left as-is rather than risk
     * a faulty transform — worst case is a cosmetic non-issue.
     */
    private static function applyExifOrientation(\GdImage $img, string $tmpPath): \GdImage
    {
        if (!function_exists('exif_read_data')) return $img;

        $exif = @exif_read_data($tmpPath);
        if (!$exif || empty($exif['Orientation'])) return $img;

        $degrees = match ((int)$exif['Orientation']) {
            3       => 180,
            6       => -90,
            8       => 90,
            default => 0,   // 1 = normal; 2/4/5/7 = mirrored (rare) — left untouched
        };

        if ($degrees === 0) return $img;

        $rotated = @imagerotate($img, $degrees, 0);
        if (!$rotated instanceof \GdImage) return $img;

        imagedestroy($img);
        return $rotated;
    }
}
