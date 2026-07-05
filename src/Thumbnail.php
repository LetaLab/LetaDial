<?php
/**
 * LetaDial — Thumbnail Generator
 *
 * Security:
 *   - SSRF: blocks private/loopback IPs before any HTTP request
 *   - Path: built from DB integers only — never from user input
 *   - Redirect: follow_location=false on ALL outbound fetches (favicon,
 *     OG-page, OG-image). SEC-081: the OG-page and OG-image fetches used
 *     to run with follow_location=true (this comment was only accurate for
 *     the favicon fetch) — every redirect target is now re-validated via
 *     isSafeHost()/isSafeHostLax() before being followed, same as the
 *     initial URL, via the shared safeFetchBody() helper below.
 *   - Upload: magic bytes validation → Imagick strip → always WebP
 *
 * Storage: storage/thumbnails/u{userId}/{dialId}.webp
 * Served:  GET /api/thumbs/{dialId} — PHP checks auth, streams file
 * API:     api/thumbs.php (NOT api/thumbnail.php — Windows case collision)
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

class Thumbnail
{
    private const WIDTH   = 163;
    private const HEIGHT  = 100;
    private const QUALITY = 72;
    private const TIMEOUT = 5;
    private const BASE    = 'storage/thumbnails';

    // ── Public API ────────────────────────────────────────────────────────────

    public static function generate(int $dialId, int $userId, string $url): bool
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagewebp')) {
            error_log('[Thumbnail] GD with WebP support not available.');
            return false;
        }

        $dir = self::absDir($userId);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                error_log("[Thumbnail] Cannot create dir: {$dir}");
                return false;
            }
        }

        $domain = self::parseDomain($url);
        if (!$domain) return false;

        $absPath = self::absPath($dialId, $userId);
        $ok      = false;

        // Strategy 1: OG image via Imagick
        if (extension_loaded('imagick') && self::isSafeHost($domain)) {
            $ok = self::generateFromOgImage($url, $domain, $absPath);
        }

        // Strategy 2: GD gradient + favicon fallback
        if (!$ok) {
            $favicon = self::fetchFavicon($domain);
            $img     = self::buildImage($domain, $favicon);
            if (!$img) return false;
            $ok = imagewebp($img, $absPath, self::QUALITY);
            imagedestroy($img);
        }

        if ($ok) {
            DB::run(
                "UPDATE dials SET thumb_path = ?, thumb_updated_at = NOW()
                 WHERE id = ? AND user_id = ?",
                [self::relPath($dialId, $userId), $dialId, $userId]
            );
        }

        return $ok;
    }

    /**
     * Process a user-uploaded image as a dial thumbnail.
     *
     * Security model:
     *   1. Magic bytes check — rejects non-image files before Imagick sees them
     *   2. Imagick reads only first frame ([0]) — prevents animated GIF attacks
     *   3. stripImage() removes ALL metadata (EXIF, GPS, XMP, IPTC, ICC, comments)
     *   4. ALWAYS re-encodes to WebP — even if source was already WebP
     *      (guarantees a "clean" output regardless of what was uploaded)
     *   5. Output path built from integers only — never from user filename
     *
     * @param int    $dialId  Dial ID (used for output filename, must match ownership)
     * @param int    $userId  User ID (used for directory path)
     * @param string $tmpPath PHP upload temp path ($_FILES['thumb']['tmp_name'])
     */
    public static function processUpload(int $dialId, int $userId, string $tmpPath): bool
    {
        if (!extension_loaded('imagick')) {
            error_log('[Thumbnail] Imagick required for upload processing.');
            return false;
        }

        if (!is_readable($tmpPath)) return false;
        $fileSize = @filesize($tmpPath);
        if (!$fileSize || $fileSize < 12 || $fileSize > 5 * 1024 * 1024) return false;

        // 1. Magic bytes validation — read binary header, reject non-images
        $fh = @fopen($tmpPath, 'rb');
        if (!$fh) return false;
        $header = fread($fh, 12);
        fclose($fh);
        if (!self::isValidUploadHeader($header)) {
            error_log('[Thumbnail] Upload rejected: invalid image signature.');
            return false;
        }

        // 2. Ensure output directory exists
        $dir = self::absDir($userId);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            error_log("[Thumbnail] Cannot create dir: {$dir}");
            return false;
        }

        $absPath = self::absPath($dialId, $userId);

        try {
            // Read only first frame/page — "[0]" prevents loading all GIF frames,
            // TIFF pages, PDF pages, etc. Safer and more memory-efficient.
            $im = new \Imagick();
            $im->readImage($tmpPath . '[0]');

            // Strip ALL metadata (EXIF, GPS, XMP, IPTC, ICC profiles, comments)
            $im->stripImage();

            // Normalize colorspace to sRGB (handles CMYK, Lab, grayscale)
            $cs = $im->getImageColorspace();
            if ($cs !== \Imagick::COLORSPACE_SRGB && $cs !== \Imagick::COLORSPACE_UNDEFINED) {
                $im->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            }

            $w = $im->getImageWidth();
            $h = $im->getImageHeight();
            if ($w < 1 || $h < 1) { $im->destroy(); return false; }

            // Center-crop to target aspect ratio (163:100 = 1.63)
            // Slightly top-biased for tall images (faces/logos tend to be near top)
            $targetRatio = self::WIDTH / self::HEIGHT;
            $srcRatio    = $w / $h;

            if ($srcRatio > $targetRatio) {
                // Image is wider — crop left and right equally
                $cropH = $h;
                $cropW = (int)round($h * $targetRatio);
                $cropX = (int)(($w - $cropW) / 2);
                $cropY = 0;
            } else {
                // Image is taller — crop more from bottom than top
                $cropW = $w;
                $cropH = (int)round($w / $targetRatio);
                $cropX = 0;
                $cropY = (int)(($h - $cropH) / 3);
            }

            $im->cropImage($cropW, $cropH, $cropX, $cropY);
            $im->resizeImage(self::WIDTH, self::HEIGHT, \Imagick::FILTER_LANCZOS, 1);

            // ALWAYS convert to WebP — even if source was already WebP.
            // This guarantees the output is clean regardless of input format.
            $im->setImageFormat('webp');
            $im->setImageCompressionQuality(self::QUALITY);
            $im->writeImage($absPath);
            $im->destroy();

            // Update database record
            DB::run(
                "UPDATE dials SET thumb_path = ?, thumb_updated_at = NOW()
                 WHERE id = ? AND user_id = ?",
                [self::relPath($dialId, $userId), $dialId, $userId]
            );

            return true;

        } catch (\ImagickException $e) {
            error_log('[Thumbnail] processUpload ImagickException: ' . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            error_log('[Thumbnail] processUpload Exception: ' . $e->getMessage());
            return false;
        }
    }

    public static function serve(int $dialId, int $userId): void
    {
        $path = self::absPath($dialId, $userId);

        if (!is_file($path)) {
            $dial = DB::row(
                "SELECT url FROM dials WHERE id = ? AND user_id = ?",
                [$dialId, $userId]
            );
            if ($dial) {
                self::generate($dialId, $userId, $dial['url']);
            }
        }

        if (!is_file($path)) {
            $fallback = __DIR__ . '/../assets/icons/empty-dial.png';
            if (is_file($fallback)) {
                header('Content-Type: image/png');
                header('Cache-Control: public, max-age=86400');
                readfile($fallback);
                return;
            }
            http_response_code(404);
            return;
        }

        $mtime = filemtime($path);
        $etag  = '"' . dechex($dialId) . '-' . dechex($mtime) . '"';

        header('Content-Type: image/webp');
        header('Cache-Control: private, max-age=3600');
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s \G\M\T', $mtime));

        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($ifNoneMatch === $etag) {
            http_response_code(304);
            return;
        }

        header('Content-Length: ' . filesize($path));
        readfile($path);
    }

    public static function delete(int $dialId, int $userId): void
    {
        $path = self::absPath($dialId, $userId);
        if (is_file($path)) @unlink($path);
    }

    public static function webUrl(int $dialId, int $userId): string
    {
        return APP_URL . '/api/thumbs/' . $dialId;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private static function relPath(int $dialId, int $userId): string
    {
        return self::BASE . '/u' . $userId . '/' . $dialId . '.webp';
    }

    private static function absPath(int $dialId, int $userId): string
    {
        return __DIR__ . '/../' . self::relPath($dialId, $userId);
    }

    private static function absDir(int $userId): string
    {
        return __DIR__ . '/../' . self::BASE . '/u' . $userId;
    }

    private static function parseDomain(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return null;
        return strtolower(preg_replace('/^www\./i', '', $host));
    }

    private static function isSafeHost(string $host): bool
    {
        $ip = @gethostbyname($host);
        if ($ip === $host) return false;
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * Validate image by checking binary file signature (magic bytes).
     * Accepts JPEG, PNG, GIF, WebP.
     * Rejects SVG, PDF, EXE, PHP, ZIP, HTML, and everything else.
     */
    private static function isValidUploadHeader(string $h): bool
    {
        return
            substr($h, 0, 2) === "\xFF\xD8"                                    ||  // JPEG
            substr($h, 0, 8) === "\x89PNG\r\n\x1A\n"                          ||  // PNG
            substr($h, 0, 6) === 'GIF87a' || substr($h, 0, 6) === 'GIF89a'   ||  // GIF
            (substr($h, 0, 4) === 'RIFF' && substr($h, 8, 4) === 'WEBP');         // WebP
    }

    // ── OG image via Imagick ──────────────────────────────────────────────────

    private static function fetchOgImageUrl(string $url): ?string
    {
        $html = self::safeFetchBody($url, "Accept: text/html\r\n", 32768, self::TIMEOUT);
        if (!$html) return null;

        $patterns = [
            '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\'][^>]*>/i',
            '/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']twitter:image["\'][^>]*>/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m)) {
                $imgUrl = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
                if (str_starts_with($imgUrl, '//')) {
                    $imgUrl = 'https:' . $imgUrl;
                } elseif (str_starts_with($imgUrl, '/')) {
                    $parsed = parse_url($url);
                    $imgUrl = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '') . $imgUrl;
                }
                if (filter_var($imgUrl, FILTER_VALIDATE_URL)) return $imgUrl;
            }
        }
        return null;
    }

    private static function generateFromOgImage(string $pageUrl, string $domain, string $absPath): bool
    {
        $ogUrl = self::fetchOgImageUrl($pageUrl);
        if (!$ogUrl) return false;

        $ogHost = parse_url($ogUrl, PHP_URL_HOST);
        if (!$ogHost || !self::isSafeHostLax($ogHost)) return false;

        $imgData = self::safeFetchBody($ogUrl, '', 5 * 1024 * 1024, self::TIMEOUT + 5);
        if (!$imgData || strlen($imgData) < 100) return false;

        try {
            $im = new \Imagick();
            $im->readImageBlob($imgData);
            $im->setFirstIterator();
            $im->stripImage();
            if ($im->getImageColorspace() !== \Imagick::COLORSPACE_SRGB) {
                $im->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            }

            $w = $im->getImageWidth();
            $h = $im->getImageHeight();
            $targetRatio = self::WIDTH / self::HEIGHT;
            $srcRatio    = $w / max($h, 1);

            if ($srcRatio > $targetRatio) {
                $cropH = $h; $cropW = (int)round($h * $targetRatio);
                $cropX = (int)(($w - $cropW) / 2); $cropY = 0;
            } else {
                $cropW = $w; $cropH = (int)round($w / $targetRatio);
                $cropX = 0;  $cropY = (int)(($h - $cropH) / 3);
            }

            $im->cropImage($cropW, $cropH, $cropX, $cropY);
            $im->resizeImage(self::WIDTH, self::HEIGHT, \Imagick::FILTER_LANCZOS, 1);
            $im->setImageFormat('webp');
            $im->setImageCompressionQuality(self::QUALITY);
            $im->writeImage($absPath);
            $im->destroy();
            return true;

        } catch (\Exception $e) {
            error_log('[Thumbnail] OG image Imagick error: ' . $e->getMessage());
            return false;
        }
    }

    private static function isSafeHostLax(string $host): bool
    {
        // SEC-055: Block loopback, link-local AND private ranges (RFC 1918).
        // Previous version only blocked 127.x and 169.254.x — allowed 10.x,
        // 192.168.x, 172.16-31.x → SSRF to internal network (router, NAS, etc).
        $ip = @gethostbyname($host);
        if (!$ip || $ip === $host) return false;

        if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;

        // FILTER_FLAG_NO_PRIV_RANGE blocks: 10.x, 172.16-31.x, 192.168.x
        // FILTER_FLAG_NO_RES_RANGE blocks: 127.x, 169.254.x, ::1, etc.
        if (!filter_var($ip, FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        // NOTE: DNS rebinding (TOCTOU) — gethostbyname() is called once here,
        // but file_get_contents() resolves DNS again. A controlled DNS server
        // could return public IP on check, internal IP on connect.
        // Full mitigation requires resolving once and connecting by IP with Host header.
        // Deferred — attacker needs control over a DNS zone, risk acceptable for self-hosted.
        return true;
    }

    // ── SEC-081: redirect-safe fetch ──────────────────────────────────────────
    //
    // fetchOgImageUrl() and generateFromOgImage() used to fetch with
    // follow_location=true, which trusts PHP to follow redirects internally
    // WITHOUT re-checking the target against isSafeHostLax(). Any public
    // server that legitimately passes the initial check could respond with
    // "Location: http://169.254.169.254/..." or "Location: http://127.0.0.1/..."
    // and PHP would follow it — no DNS control needed, just one 3xx response.
    // safeFetchBody() disables automatic redirect-following and re-validates
    // every hop the same way the initial URL is validated. (Does NOT close
    // the separate DNS-rebinding TOCTOU noted above — that remains deferred.)

    private static function safeFetchBody(string $url, string $extraHeaders, int $maxBytes, int $timeout): ?string
    {
        $maxRedirects = 3;

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $ctx = stream_context_create([
                'http' => [
                    'timeout'         => $timeout,
                    'follow_location' => false, // SEC-081: followed manually below, with re-validation
                    'ignore_errors'   => true,  // so 3xx responses stay inspectable, not treated as failure
                    'user_agent'      => 'LetaDial/1.0 ThumbnailBot',
                    'header'          => $extraHeaders,
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);

            $body     = @file_get_contents($url, false, $ctx, 0, $maxBytes);
            $status   = self::parseStatusCode($http_response_header ?? []);
            $location = self::parseHeaderValue($http_response_header ?? [], 'Location');

            if ($status >= 300 && $status < 400 && $location) {
                $next     = self::resolveRedirectUrl($url, $location);
                $nextHost = $next ? parse_url($next, PHP_URL_HOST) : null;
                if (!$nextHost || !self::isSafeHostLax($nextHost)) {
                    return null; // SEC-081: refuse to follow a redirect to an unvalidated/unsafe host
                }
                $url = $next;
                continue;
            }

            return ($body !== false && $body !== '') ? $body : null;
        }

        return null; // too many redirects
    }

    /** SEC-081: extract the numeric HTTP status code from a stream's response header array. */
    private static function parseStatusCode(array $headers): int
    {
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) return (int)$m[1];
        }
        return 0;
    }

    /** SEC-081: case-insensitive header lookup within a stream's response header array. */
    private static function parseHeaderValue(array $headers, string $name): ?string
    {
        $prefix = preg_quote($name, '#');
        foreach ($headers as $h) {
            if (preg_match('#^' . $prefix . ':\s*(.+)$#i', $h, $m)) {
                return trim($m[1]);
            }
        }
        return null;
    }

    /**
     * SEC-081: resolve a Location header (absolute, protocol-relative,
     * root-relative, or path-relative) against the URL it was received
     * from. Returns null if the base URL can't be parsed.
     */
    private static function resolveRedirectUrl(string $baseUrl, string $location): ?string
    {
        if (preg_match('#^https?://#i', $location)) return $location;

        $parts = parse_url($baseUrl);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return null;

        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (str_starts_with($location, '//')) return $parts['scheme'] . ':' . $location;
        if (str_starts_with($location, '/'))  return $origin . $location;

        $basePath = $parts['path'] ?? '/';
        $dir      = substr($basePath, 0, (strrpos($basePath, '/') ?: 0) + 1) ?: '/';
        return $origin . $dir . $location;
    }

    // ── GD fallback ───────────────────────────────────────────────────────────

    private static function fetchFavicon(string $domain): ?string
    {
        if (!self::isSafeHost($domain)) return null;
        $ctx = stream_context_create([
            'http' => ['timeout' => self::TIMEOUT, 'follow_location' => false,
                       'max_redirects' => 0, 'user_agent' => 'LetaDial/1.0 ThumbnailBot'],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        foreach (['https', 'http'] as $scheme) {
            $data = @file_get_contents("{$scheme}://{$domain}/favicon.ico", false, $ctx);
            if ($data && strlen($data) >= 8 && self::isImageData($data)) return $data;
        }
        return null;
    }

    private static function isImageData(string $data): bool
    {
        $s = substr($data, 0, 8);
        return
            substr($s, 0, 4) === "\x89PNG"                                   ||
            substr($s, 0, 2) === "\xFF\xD8"                                  ||
            substr($s, 0, 6) === 'GIF87a' || substr($s, 0, 6) === 'GIF89a'  ||
            (substr($s, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WEBP') ||
            substr($s, 0, 4) === "\x00\x00\x01\x00";
    }

    private static function buildImage(string $domain, ?string $faviconData): \GdImage|false
    {
        $img = @imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if (!$img) return false;
        imagesavealpha($img, true);

        $hue = abs(crc32($domain)) % 360;
        [$r1,$g1,$b1] = self::hsl($hue, 0.50, 0.38);
        [$r2,$g2,$b2] = self::hsl($hue, 0.45, 0.55);

        for ($y = 0; $y < self::HEIGHT; $y++) {
            $t = $y / self::HEIGHT;
            $c = imagecolorallocate($img,
                (int)($r2 + ($r1 - $r2) * $t),
                (int)($g2 + ($g1 - $g2) * $t),
                (int)($b2 + ($b1 - $b2) * $t)
            );
            imageline($img, 0, $y, self::WIDTH, $y, $c);
        }

        $white    = imagecolorallocate($img, 255, 255, 255);
        $favDrawn = false;

        if ($faviconData) {
            $fav = @imagecreatefromstring($faviconData);
            if ($fav) {
                $size = 40;
                $dx = (int)((self::WIDTH  - $size) / 2);
                $dy = (int)((self::HEIGHT - $size) / 2) - 8;
                imagecopyresampled($img, $fav, $dx, $dy, 0, 0, $size, $size,
                                   imagesx($fav), imagesy($fav));
                imagedestroy($fav);
                $favDrawn = true;
            }
        }

        if (!$favDrawn) {
            $letter = strtoupper(substr($domain, 0, 1));
            $fs = 5;
            imagestring($img, $fs,
                (int)((self::WIDTH  - imagefontwidth($fs))  / 2),
                (int)((self::HEIGHT - imagefontheight($fs)) / 2) - 8,
                $letter, $white
            );
        }

        $label = mb_strlen($domain) > 22 ? mb_substr($domain, 0, 20) . '..' : $domain;
        $tw = strlen($label) * imagefontwidth(1);
        imagefilledrectangle($img, 0, self::HEIGHT - 16, self::WIDTH, self::HEIGHT,
                             imagecolorallocatealpha($img, 0, 0, 0, 80));
        imagestring($img, 1,
            (int)max(2, (self::WIDTH - $tw) / 2),
            self::HEIGHT - 13,
            $label, $white
        );

        return $img;
    }

    private static function hsl(int $h, float $s, float $l): array
    {
        $h /= 360;
        if ($s == 0) { $v = (int)($l * 255); return [$v, $v, $v]; }
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        return [
            (int)(self::h2r($p, $q, $h + 1/3) * 255),
            (int)(self::h2r($p, $q, $h)       * 255),
            (int)(self::h2r($p, $q, $h - 1/3) * 255),
        ];
    }

    private static function h2r(float $p, float $q, float $t): float
    {
        if ($t < 0) $t += 1; if ($t > 1) $t -= 1;
        if ($t < 1/6) return $p + ($q - $p) * 6 * $t;
        if ($t < 1/2) return $q;
        if ($t < 2/3) return $p + ($q - $p) * (2/3 - $t) * 6;
        return $p;
    }
}
