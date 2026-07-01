<?php
/**
 * LetaDial — Avatar (sesja 078)
 *
 * Handles user avatar upload, processing, serving and deletion.
 * Storage: storage/avatars/u{userId}.webp
 * Served:  GET /api/avatars/{userId} — PHP checks auth, streams file
 * API:     api/avatars.php
 *
 * Security model (same trust boundary as GroupIcon.php / Thumbnail.php):
 *   1. Magic-bytes pre-check — rejects non-image files before GD ever sees them
 *      (cheap fast-fail; not the real gate, just saves a wasted decode attempt).
 *   2. imagecreatefromstring() — the PRIMARY security gate. It actually decodes
 *      pixel data: type-safe, not MIME/extension based. Fails immediately on
 *      SVG, PHP, HTML, JS, EXE, ZIP, PDF, or any corrupt/non-image bytes —
 *      regardless of what the filename or claimed Content-Type says.
 *   3. GD re-encodes PIXEL DATA ONLY → the WebP written to disk carries ZERO of
 *      the original file's metadata (EXIF, GPS, XMP, IPTC, ICC profiles,
 *      comments, or any payload hidden in those segments).
 *   4. ALWAYS re-encoded to WebP — even if the source was already WebP. No
 *      passthrough, ever. Bytes on disk are 100% GD's own fresh output.
 *   5. EXIF orientation is read and applied to the PIXELS before that metadata
 *      is discarded (best-effort, only if ext-exif is loaded — purely cosmetic,
 *      never required; without it phone photos could appear sideways once
 *      their orientation tag is stripped).
 *   6. Center-crop to 1:1 square (top-biased — faces sit above center) before
 *      resize, so portrait/landscape sources are not squished.
 *   7. The user's original upload temp file is explicitly unlinked the moment
 *      processing finishes — PHP already purges $_FILES tmp files post-request,
 *      this closes the window sooner: the untrusted original never outlives
 *      the single step that converts it.
 *   8. Output path is built from the AUTHENTICATED user's own integer ID only —
 *      never from user-supplied filename or path data.
 *
 * Why GD (not Imagick) here:
 *   imagecreatefromstring() is the strongest validation gate available — it
 *   actually decodes every pixel; any non-image byte stream returns false
 *   immediately. GD + WebP is also a REQUIRED extension on every LetaDial
 *   install (checked in install.php), so avatar upload works everywhere with
 *   zero optional dependencies — unlike Thumbnail::processUpload(), which
 *   needs the optional Imagick extension. At 128×128 output, GD quality is
 *   indistinguishable from Imagick anyway.
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

class Avatar
{
    private const ICON_W    = 128;
    private const ICON_H    = 128;
    private const QUALITY   = 85;
    private const MAX_BYTES = 5 * 1024 * 1024; // 5 MB — matches Thumbnail's upload limit

    // ── Paths ─────────────────────────────────────────────────────────────────

    private static function dir(): string
    {
        return __DIR__ . '/../storage/avatars';
    }

    public static function filePath(int $userId): string
    {
        return self::dir() . '/u' . $userId . '.webp';
    }

    private static function relPath(int $userId): string
    {
        return 'storage/avatars/u' . $userId . '.webp';
    }

    public static function webUrl(int $userId): string
    {
        return '/api/avatars/' . $userId;
    }

    // ── Upload & Process ──────────────────────────────────────────────────────

    /**
     * Process an uploaded image and save as 128×128 WebP.
     *
     * @param int    $userId  Authenticated user's ID — output filename only,
     *                        never trusted from request data.
     * @param string $tmpPath PHP upload temp path ($_FILES['avatar']['tmp_name'])
     */
    public static function processUpload(int $userId, string $tmpPath): bool
    {
        if (!file_exists($tmpPath) || !is_readable($tmpPath)) return false;

        if (!function_exists('imagecreatetruecolor') || !function_exists('imagewebp')) {
            error_log('[Avatar] GD with WebP support required.');
            return false;
        }

        $fileSize = @filesize($tmpPath);
        if (!$fileSize || $fileSize < 12 || $fileSize > self::MAX_BYTES) return false;

        // 1. Magic-bytes pre-check — fast-fail on obviously non-image files
        //    before wasting a GD decode attempt on them.
        $fh = @fopen($tmpPath, 'rb');
        if (!$fh) return false;
        $header = fread($fh, 12);
        fclose($fh);
        if (!self::isValidUploadHeader($header)) {
            error_log('[Avatar] Upload rejected: invalid image signature.');
            self::cleanupTmp($tmpPath);
            return false;
        }

        // Read raw bytes — GD will decode and validate them for real
        $raw = @file_get_contents($tmpPath);
        if ($raw === false || strlen($raw) === 0) {
            self::cleanupTmp($tmpPath);
            return false;
        }

        // 2. PRIMARY SECURITY GATE: imagecreatefromstring() actually decodes
        //    pixels. Returns false for anything that is not a valid raster
        //    image. This blocks: SVG, PHP, HTML, JS, EXE, ZIP, PDF, text files.
        $src = @imagecreatefromstring($raw);
        if ($src === false) {
            error_log('[Avatar] Upload rejected: imagecreatefromstring() failed.');
            self::cleanupTmp($tmpPath);
            return false;
        }

        // 3. Best-effort EXIF orientation correction, applied to the decoded
        //    pixels BEFORE re-encoding discards all metadata below.
        $src = self::applyExifOrientation($src, $tmpPath);

        $sw = imagesx($src);
        $sh = imagesy($src);

        if ($sw < 1 || $sh < 1) {
            imagedestroy($src);
            self::cleanupTmp($tmpPath);
            return false;
        }

        // 4. Center-crop to square — top-biased (faces/logos tend to sit
        //    in the upper portion of a photo, same heuristic as Thumbnail.php)
        if ($sw !== $sh) {
            $cropSize = min($sw, $sh);
            $cropX    = (int)(($sw - $cropSize) / 2);
            $cropY    = (int)(($sh - $cropSize) / 3); // top-bias

            $cropped = @imagecreatetruecolor($cropSize, $cropSize);
            if (!$cropped) {
                imagedestroy($src);
                self::cleanupTmp($tmpPath);
                return false;
            }

            // White background — transparent PNGs/GIFs/WebPs become white, not black
            $white = imagecolorallocate($cropped, 255, 255, 255);
            imagefilledrectangle($cropped, 0, 0, $cropSize - 1, $cropSize - 1, $white);

            imagecopyresampled(
                $cropped, $src,
                0, 0,
                $cropX, $cropY,
                $cropSize, $cropSize,
                $cropSize, $cropSize
            );
            imagedestroy($src);
            $src = $cropped;
            $sw  = $cropSize;
            $sh  = $cropSize;
        }

        // 5. Fresh 128×128 output canvas — never a passthrough of source bytes
        $dst = @imagecreatetruecolor(self::ICON_W, self::ICON_H);
        if (!$dst) {
            imagedestroy($src);
            self::cleanupTmp($tmpPath);
            return false;
        }

        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, self::ICON_W - 1, self::ICON_H - 1, $white);

        imagecopyresampled($dst, $src, 0, 0, 0, 0, self::ICON_W, self::ICON_H, $sw, $sh);
        imagedestroy($src);

        // 6. Ensure storage directory exists and is protected on first use
        //    (fix_permissions.sh already does this ahead of time on a normal
        //    deploy — this is defense in depth for a fresh install that
        //    hasn't run it yet).
        $dir = self::dir();
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                imagedestroy($dst);
                error_log('[Avatar] Cannot create dir: ' . $dir);
                self::cleanupTmp($tmpPath);
                return false;
            }
        }
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Options -Indexes\nOrder deny,allow\nDeny from all\n");
        }

        // 7. Write — output path built from integer userId only, never from
        //    the user's original filename.
        $out = self::filePath($userId);
        $ok  = imagewebp($dst, $out, self::QUALITY);
        imagedestroy($dst);

        if (!$ok) {
            error_log('[Avatar] imagewebp() failed for user ' . $userId);
            self::cleanupTmp($tmpPath);
            return false;
        }

        @chmod($out, 0644);

        DB::run(
            "UPDATE users SET avatar_path = ? WHERE id = ?",
            [self::relPath($userId), $userId]
        );

        // 8. The user's original upload never persists past this point —
        //    explicit cleanup rather than waiting on PHP's post-request purge.
        self::cleanupTmp($tmpPath);

        return true;
    }

    // ── Serve ─────────────────────────────────────────────────────────────────

    /**
     * Stream the avatar to the browser with ETag/304 cache headers.
     * Any authenticated user may view any user's avatar — a profile picture
     * is low-sensitivity by nature and is needed in the topbar, Settings, and
     * the admin Users panel. Caller MUST already have verified Auth::getUser()
     * is non-null before calling this.
     */
    public static function serve(int $userId): void
    {
        $path = self::filePath($userId);

        if (!is_file($path)) {
            http_response_code(404);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => 'No avatar set.']);
            return;
        }

        $mtime = (int)filemtime($path);
        $etag  = '"av-' . $userId . '-' . $mtime . '"';

        header('Content-Type: image/webp');
        header('Cache-Control: private, max-age=3600');
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s \G\M\T', $mtime));

        if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            return;
        }

        header('Content-Length: ' . filesize($path));
        readfile($path);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    /**
     * Delete the avatar file and clear avatar_path in DB.
     * Best-effort — does not throw on missing file.
     */
    public static function delete(int $userId): void
    {
        $path = self::filePath($userId);
        if (is_file($path)) {
            @unlink($path);
        }

        DB::run(
            "UPDATE users SET avatar_path = NULL WHERE id = ?",
            [$userId]
        );
    }

    // ── Existence check ───────────────────────────────────────────────────────

    /**
     * Check if user has an avatar file on disk (faster than a DB query).
     */
    public static function exists(int $userId): bool
    {
        return is_file(self::filePath($userId));
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private static function cleanupTmp(string $tmpPath): void
    {
        // Belt-and-suspenders: PHP already deletes $_FILES tmp files once the
        // request ends, but the untrusted original is removed explicitly here
        // the moment we're done with it, rather than waiting on request teardown.
        if (is_file($tmpPath) && str_starts_with($tmpPath, sys_get_temp_dir())) {
            @unlink($tmpPath);
        }
    }

    /**
     * Validate image by checking binary file signature (magic bytes).
     * Accepts JPEG, PNG, GIF, WebP. Rejects SVG, PDF, EXE, PHP, ZIP, HTML, everything else.
     * Mirrors Thumbnail::isValidUploadHeader exactly — same trust boundary.
     */
    private static function isValidUploadHeader(string $h): bool
    {
        return
            substr($h, 0, 2) === "\xFF\xD8"                                    ||  // JPEG
            substr($h, 0, 8) === "\x89PNG\r\n\x1A\n"                          ||  // PNG
            substr($h, 0, 6) === 'GIF87a' || substr($h, 0, 6) === 'GIF89a'   ||  // GIF
            (substr($h, 0, 4) === 'RIFF' && substr($h, 8, 4) === 'WEBP');         // WebP
    }

    /**
     * Best-effort EXIF orientation correction. Only meaningful for JPEG, only
     * runs if ext-exif is loaded (optional — never required for LetaDial to
     * function). Handles the three pure-rotation cases (3/6/8) that cover the
     * overwhelming majority of real phone photos; the rare mirrored variants
     * (2/4/5/7, mostly from flatbed scanners) are left as-is rather than risk
     * a faulty transform — worst case is a cosmetic non-issue, never a
     * security concern.
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
