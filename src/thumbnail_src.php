<?php
/**
 * LetaDial — Thumbnail Generator
 *
 * Security:
 *   - SSRF: blocks private/loopback IPs before any HTTP request
 *   - SEC-086/SEC-087: resolvePinned() resolves a host ONCE, validates
 *     every A record AND every AAAA record, and every outbound fetch then
 *     connects directly to that one validated IPv4 address — never by
 *     handing a hostname to file_get_contents()/fopen() and letting PHP
 *     resolve it again independently. Closes DNS-rebinding TOCTOU
 *     (SEC-086, previously an accepted/deferred risk noted in
 *     isSafeHostLax()) and unvalidated-AAAA bypass (SEC-087) in one place.
 *     isSafeHost()/isSafeHostLax() are now thin boolean wrappers around it.
 *   - Path: built from DB integers only — never from user input
 *   - Redirect: follow_location=false on ALL outbound fetches (favicon,
 *     OG-page, OG-image), every hop re-resolved + re-validated via the
 *     shared safeFetchBody() helper below (SEC-081, extended by SEC-086/087)
 *   - Upload: magic bytes validation → Imagick strip → always WebP
 *   - SEC-090: pingImage()/pingImageBlob() checks declared dimensions
 *     BEFORE any full decode (processUpload(), generateFromOgImage()), and
 *     applyImagickSafetyLimits() caps Imagick's memory/map resource usage
 *     — guards against decompression-bomb images (a small file whose
 *     header declares huge pixel dimensions)
 *   - SEC-103: fetchFavicon() now caps its response size (MAX_FAVICON_BYTES)
 *     like every other fetch in this class already does via safeFetchBody()
 *   - BUG-018: processUpload() now unlinks its upload temp file on every
 *     exit path via a finally block (cleanupTmp()), instead of relying
 *     solely on PHP's post-request purge
 *
 * Storage: storage/thumbnails/u{userId}/{dialId}.webp
 * Served:  GET /api/thumbs/{dialId} — PHP checks auth, streams file
 * API:     api/thumbnail_api.php
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
    // SEC-103: fetchFavicon() had no response-size limit, unlike every other
    // fetch in this class (all routed through safeFetchBody(), which always
    // takes a $maxBytes). A favicon.ico response is typically well under
    // 100KB — 1 MB is a generous ceiling that still bounds a single PHP-FPM
    // worker's memory spike from a malicious/compromised favicon.ico host.
    private const MAX_FAVICON_BYTES = 1024 * 1024;
    // SEC-090: reject images with a declared width/height above this BEFORE
    // Imagick fully decodes them (see applyImagickSafetyLimits() and the
    // pingImage()/pingImageBlob() calls in processUpload() /
    // generateFromOgImage() below).
    private const MAX_DIMENSION = 8000;

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
    /**
     * SEC-090: process-wide Imagick resource ceiling, applied right before
     * any decode. ImageMagick's resource limits (the underlying C library's
     * MagickSetResourceLimit) are process-global rather than scoped to one
     * Imagick object, even though the PHP method is called on an instance
     * — confirmed against the Imagick/imagick extension's own test suite
     * and multiple independently-reported PHP manual notes, since the
     * manual's own one-line summary ("...in megabytes") is misleading: the
     * actual values are bytes. This constrains every Imagick operation for
     * the rest of this PHP-FPM worker's lifetime, which is what we want,
     * since every Imagick call site in this app wants the same
     * conservative ceiling.
     *
     * Deliberately does NOT set RESOURCETYPE_TIME: ImageMagick handles a
     * breached time limit by calling the C library's exit() directly
     * (confirmed via Imagick/imagick issue #333 — "the process to exit
     * without returning control to the php code"), which kills the whole
     * PHP-FPM worker mid-request instead of raising a catchable
     * ImagickException the way MEMORY/MAP do. WordPress core shipped this
     * exact pattern and later deprecated it for the same reason. The
     * MAX_DIMENSION ping-check below is the primary defense against a slow
     * decode; MEMORY/MAP are the graceful backstop.
     */
    private static function applyImagickSafetyLimits(\Imagick $im): void
    {
        // 512 MB pixel-cache memory, 512 MB disk-backed map overflow —
        // comfortably above what this app's own images need (a validated
        // MAX_DIMENSION=8000 image fully decoded to RGBA is ~244 MiB, plus
        // working overhead for crop/resize), but well short of what an
        // actual decompression bomb would try to allocate.
        @$im->setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 512 * 1024 * 1024);
        @$im->setResourceLimit(\Imagick::RESOURCETYPE_MAP,    512 * 1024 * 1024);
    }

    public static function processUpload(int $dialId, int $userId, string $tmpPath): bool
    {
        // BUG-018: entire body wrapped in try/finally so cleanupTmp() below
        // always runs on the way out — success, an early validation `return
        // false`, or an Imagick exception — without needing to repeat the
        // cleanup call before every individual early return. Moving the
        // pre-existing early checks inside this try does not change their
        // behaviour: none of them throw, they still `return false` exactly
        // as before, the surrounding try/catch(ImagickException|Exception)
        // only ever catches what Imagick itself can throw further down.
        try {
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

            $im = new \Imagick();

            // SEC-090: process-wide Imagick resource ceiling, applied before
            // any decode — see applyImagickSafetyLimits() docblock.
            self::applyImagickSafetyLimits($im);

            // SEC-090: pingImage() reads only the image header (format,
            // width, height) — it does NOT decode pixel data, so it stays
            // cheap even for a "decompression bomb" (a tiny compressed file
            // whose header declares huge dimensions, e.g. a few KB of PNG
            // that would decode to hundreds of MB of RGBA pixels). Reject
            // oversized images here, BEFORE the real readImage() below
            // performs the actual full decode.
            $im->pingImage($tmpPath . '[0]');
            if ($im->getImageWidth()  < 1 || $im->getImageWidth()  > self::MAX_DIMENSION
             || $im->getImageHeight() < 1 || $im->getImageHeight() > self::MAX_DIMENSION) {
                $im->clear();
                error_log('[Thumbnail] processUpload rejected: image dimensions missing or too large.');
                return false;
            }

            // Read only first frame/page — "[0]" prevents loading all GIF frames,
            // TIFF pages, PDF pages, etc. Safer and more memory-efficient.
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
        } finally {
            // BUG-018: the user's original upload never persists past this
            // function returning, on ANY exit path — mirrors avatar_src.php's
            // existing "belt-and-suspenders" cleanupTmp() pattern.
            self::cleanupTmp($tmpPath);
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

    /**
     * SEC-086/SEC-087: resolve $host to ONE validated public IPv4 address,
     * suitable for a pinned connection (see safeFetchBody()/fetchFavicon()
     * below).
     *
     * Uses gethostbynamel() (plural — returns every A record) rather than
     * gethostbyname() (singular — returns only the first), so a
     * round-robin/multi-A host cannot hide a private address behind a
     * public one that happens to be returned first: ANY private/reserved A
     * record rejects the whole host.
     *
     * Also checks every AAAA record the same way (SEC-087), even though
     * this method only ever returns an IPv4 address for the caller to
     * connect over: a host that publishes a private/loopback AAAA (e.g.
     * ::1) alongside a clean public A is treated as unsafe outright, rather
     * than assuming the IPv4 pin alone makes that irrelevant.
     *
     * This is now the ONE authoritative resolve+validate function for this
     * class — isSafeHost() and isSafeHostLax() below are thin boolean
     * wrappers kept only so existing call sites stay self-documenting
     * ("is this domain OK to touch at all" vs. "is this specific
     * redirect/og:image host OK"). The actual outbound connection is always
     * made to the IP this method returns, never by re-resolving the
     * hostname later — that is what closes SEC-086 (DNS-rebinding TOCTOU:
     * a DNS zone the attacker controls could previously answer a public IP
     * for the check and a private IP moments later for the real connect,
     * since those used to be two separate, independently-timed lookups).
     *
     * @return string|null a single validated public IPv4 address, or null
     *                      if the host has no usable A record, or if any
     *                      A/AAAA record it publishes is private/reserved.
     */
    private static function resolvePinned(string $host): ?string
    {
        $ipv4s = @gethostbynamel($host);
        if (!$ipv4s) return null; // DNS failure / no A record at all

        foreach ($ipv4s as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return null;
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null; // any private/reserved A record → reject the whole host
            }
        }

        // dns_get_record() can return false (or emit a warning) on resolver
        // failure — treated the same as "no AAAA records", which is the
        // common, legitimate case, not an error.
        $aaaaRecords = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($aaaaRecords as $rec) {
            $ip6 = $rec['ipv6'] ?? null;
            if ($ip6 !== null && !filter_var($ip6, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null;
            }
        }

        return $ipv4s[0];
    }

    private static function isSafeHost(string $host): bool
    {
        return self::resolvePinned($host) !== null;
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

    /**
     * BUG-018: explicitly unlink the upload temp file. PHP already deletes
     * $_FILES tmp files once the request ends — this just closes the window
     * sooner, the same "belt-and-suspenders" pattern already used by
     * Avatar::cleanupTmp() / GroupIcon::cleanupTmp(). Called from
     * processUpload()'s finally block, so it runs on every exit path.
     */
    private static function cleanupTmp(string $tmpPath): void
    {
        if (is_file($tmpPath) && str_starts_with($tmpPath, sys_get_temp_dir())) {
            @unlink($tmpPath);
        }
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

            // SEC-090: same guard as processUpload() — see
            // applyImagickSafetyLimits() docblock. This path is arguably
            // HIGHER risk: the bytes come from an arbitrary external URL's
            // og:image tag, fetched automatically on every dial add, with
            // no user review of the image before it's decoded.
            self::applyImagickSafetyLimits($im);

            $im->pingImageBlob($imgData);
            if ($im->getImageWidth()  < 1 || $im->getImageWidth()  > self::MAX_DIMENSION
             || $im->getImageHeight() < 1 || $im->getImageHeight() > self::MAX_DIMENSION) {
                $im->clear();
                return false;
            }

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
        // SEC-086/SEC-087: now identical to isSafeHost() — both delegate to
        // resolvePinned(), which performs the full A+AAAA check (see its
        // docblock above). Kept as a separately-named call site purely for
        // readability at each call site: isSafeHost() gates the primary
        // page URL, isSafeHostLax() gates a redirect target or an
        // extracted og:image URL. The DNS-rebinding TOCTOU risk this
        // function's comment used to note as "deferred" is closed now that
        // every fetch connects to resolvePinned()'s literal IP instead of
        // re-resolving the hostname (see safeFetchBody()/fetchFavicon()).
        return self::resolvePinned($host) !== null;
    }

    // ── SEC-081/SEC-086/SEC-087: redirect-safe, pin-connected fetch ──────────
    //
    // fetchOgImageUrl() and generateFromOgImage() used to fetch with
    // follow_location=true, which trusts PHP to follow redirects internally
    // WITHOUT re-checking the target against isSafeHostLax(). Any public
    // server that legitimately passes the initial check could respond with
    // "Location: http://169.254.169.254/..." or "Location: http://127.0.0.1/..."
    // and PHP would follow it — no DNS control needed, just one 3xx response.
    // safeFetchBody() disables automatic redirect-following and re-resolves
    // + re-validates every hop the same way the initial URL is validated.
    //
    // SEC-086/SEC-087: on top of that, every hop now connects DIRECTLY to
    // the IP resolvePinned() returned for that hop's host, instead of
    // handing the hostname to file_get_contents() and letting PHP resolve
    // it again independently. That closes the DNS-rebinding TOCTOU this
    // comment used to flag as deferred (a DNS zone the attacker controls
    // could answer differently between the validation lookup and the real
    // connect) and the unvalidated-AAAA bypass (SEC-087) — see
    // resolvePinned()'s docblock for the full rationale.

    private static function safeFetchBody(string $url, string $extraHeaders, int $maxBytes, int $timeout): ?string
    {
        $maxRedirects = 3;

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $parts = parse_url($url);
            if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return null;

            $scheme = strtolower($parts['scheme']);
            if (!in_array($scheme, ['http', 'https'], true)) return null;

            $host = $parts['host'];
            $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

            $ip = self::resolvePinned($host);
            if (!$ip) return null;

            $path      = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
            $pinnedUrl = $scheme . '://' . $ip . ':' . $port . $path;

            $hostHeader = $host;
            if (($scheme === 'http' && $port !== 80) || ($scheme === 'https' && $port !== 443)) {
                $hostHeader .= ':' . $port;
            }

            $ctxOpts = [
                'http' => [
                    'timeout'         => $timeout,
                    'follow_location' => false, // SEC-081: followed manually below, with re-validation
                    'ignore_errors'   => true,  // so 3xx responses stay inspectable, not treated as failure
                    'user_agent'      => 'LetaDial/1.0 ThumbnailBot',
                    'header'          => 'Host: ' . $hostHeader . "\r\n" . $extraHeaders,
                ],
            ];
            if ($scheme === 'https') {
                $ctxOpts['ssl'] = [
                    'verify_peer'      => true,
                    'verify_peer_name' => true,
                    // Pin SNI + certificate hostname verification to the
                    // ORIGINAL domain, not the IP we're literally
                    // connecting to.
                    'peer_name'        => $host,
                ];
            }
            $ctx = stream_context_create($ctxOpts);

            $body     = @file_get_contents($pinnedUrl, false, $ctx, 0, $maxBytes);
            $status   = self::parseStatusCode($http_response_header ?? []);
            $location = self::parseHeaderValue($http_response_header ?? [], 'Location');

            if ($status >= 300 && $status < 400 && $location) {
                $next = self::resolveRedirectUrl($url, $location);
                if (!$next) return null;
                // Re-resolved + re-pinned (resolvePinned()) at the top of
                // the next loop iteration — never trusted from this hop.
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
        // SEC-086/SEC-087: resolve+validate ONCE and reuse the same pinned
        // IP for both the https and http attempts below, instead of letting
        // file_get_contents() resolve $domain independently each time.
        $ip = self::resolvePinned($domain);
        if (!$ip) return null;

        foreach (['https', 'http'] as $scheme) {
            $ctxOpts = [
                'http' => [
                    'timeout' => self::TIMEOUT, 'follow_location' => false,
                    'max_redirects' => 0, 'user_agent' => 'LetaDial/1.0 ThumbnailBot',
                    'header' => 'Host: ' . $domain . "\r\n",
                ],
            ];
            if ($scheme === 'https') {
                $ctxOpts['ssl'] = [
                    'verify_peer' => true, 'verify_peer_name' => true,
                    'peer_name'   => $domain,
                ];
            }
            $ctx  = stream_context_create($ctxOpts);
            $data = @file_get_contents("{$scheme}://{$ip}/favicon.ico", false, $ctx, 0, self::MAX_FAVICON_BYTES);
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
