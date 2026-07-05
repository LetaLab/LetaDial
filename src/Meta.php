<?php
/**
 * LetaDial — Meta Fetcher (sesja 057)
 *
 * Fetches <title> and Open Graph meta tags from a URL.
 * Returns: ['title' => '...', 'description' => '...', 'og_image' => '...']
 *
 * Security:
 *   - SSRF: blocks private/loopback/reserved IPs (same as Thumbnail.php)
 *   - Redirect: max 3 hops, EACH hop re-validated via isSafeUrl() (SEC-081)
 *     — never trusts PHP's built-in follow_location, which does not
 *     re-check redirect targets against the SSRF filter
 *   - Timeout: 5s connect + read
 *   - Size limit: reads max 65536 bytes — <head> is always enough
 *   - Charset: auto-detected from Content-Type header or <meta charset>
 *   - Rate limit: enforced by the API endpoint (api/meta.php), not here
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

class Meta
{
    private const TIMEOUT    = 5;
    private const MAX_BYTES  = 65536;  // 64 KB — enough for any <head>
    private const MAX_TITLE  = 100;
    private const MAX_DESC   = 300;

    /**
     * Fetch title + OG tags from a URL.
     *
     * @return array{title:string|null, description:string|null, ok:bool, error:string|null}
     */
    public static function fetch(string $url): array
    {
        $url = trim($url);
        if (!self::isSafeUrl($url)) {
            return ['ok' => false, 'error' => 'URL not allowed.', 'title' => null, 'description' => null];
        }

        $html = self::download($url);
        if ($html === null) {
            return ['ok' => false, 'error' => 'Could not fetch URL.', 'title' => null, 'description' => null];
        }

        $html = self::toUtf8($html);

        return [
            'ok'          => true,
            'error'       => null,
            'title'       => self::extractTitle($html),
            'description' => self::extractDescription($html),
        ];
    }

    // ── Safety ────────────────────────────────────────────────────────────────

    private static function isSafeUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) return false;

        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) return false;

        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return false;

        // Resolve host → IP, then block private/reserved ranges
        $ip = @gethostbyname($host);
        if (!$ip || $ip === $host) return false;   // DNS failure

        if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;

        // Block: 10.x, 172.16-31.x, 192.168.x (FILTER_FLAG_NO_PRIV_RANGE)
        // Block: 127.x, 169.254.x, ::1        (FILTER_FLAG_NO_RES_RANGE)
        return (bool)filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    // ── Download ──────────────────────────────────────────────────────────────

    /**
     * SEC-081: fetches $url, following up to 3 redirects — but RE-VALIDATING
     * every redirect target through isSafeUrl() before following it.
     *
     * Why this was needed: follow_location=true (the old behaviour) trusts
     * PHP's stream wrapper to follow redirects internally, and PHP never
     * re-checks the redirect target against our SSRF filter. Any host that
     * legitimately passes isSafeUrl() (i.e. any public server) could send
     * back "HTTP/1.1 302 Found" + "Location: http://169.254.169.254/..."
     * (cloud metadata) or "Location: http://127.0.0.1/admin" (internal
     * service), and the old code would happily follow it — no DNS control
     * needed, just one ordinary HTTP response. This closes that gap by
     * disabling automatic redirect-following and manually validating each
     * hop the same way the initial URL is validated in isSafeUrl().
     */
    private static function download(string $url): ?string
    {
        $maxRedirects = 3;

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $ctx = stream_context_create([
                'http' => [
                    'method'          => 'GET',
                    'timeout'         => self::TIMEOUT,
                    'follow_location' => false, // SEC-081: followed manually below, with re-validation
                    'user_agent'      => 'LetaDial/2.0 MetaBot (+https://github.com)',
                    'header'          => implode("\r\n", [
                        'Accept: text/html,application/xhtml+xml',
                        'Accept-Language: en,*;q=0.5',
                        'Connection: close',
                    ]),
                    'ignore_errors'   => true, // so 3xx responses stay inspectable below
                ],
                'ssl' => [
                    'verify_peer'      => true,
                    'verify_peer_name' => true,
                ],
            ]);

            // Suppress warnings — failure is handled via return values below.
            $handle = @fopen($url, 'r', false, $ctx);
            if (!$handle) return null;

            // $http_response_header is populated by PHP in this scope
            // immediately after fopen() on an http(s):// stream.
            $status   = self::parseStatusCode($http_response_header ?? []);
            $location = self::parseHeaderValue($http_response_header ?? [], 'Location');

            if ($status >= 300 && $status < 400 && $location) {
                fclose($handle);
                $next = self::resolveRedirectUrl($url, $location);
                if (!$next || !self::isSafeUrl($next)) {
                    return null; // SEC-081: refuse to follow a redirect to an unvalidated target
                }
                $url = $next;
                continue;
            }

            $html = '';
            $read = 0;
            while (!feof($handle) && $read < self::MAX_BYTES) {
                $chunk  = fread($handle, 4096);
                if ($chunk === false) break;
                $html  .= $chunk;
                $read  += strlen($chunk);

                // Early exit once </head> is found — no need for <body>
                if (stripos($html, '</head>') !== false) break;
            }
            fclose($handle);

            return strlen($html) > 0 ? $html : null;
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

    // ── Charset ───────────────────────────────────────────────────────────────

    /**
     * Convert $html to UTF-8 if it isn't already.
     * Detection order:
     *   1. <meta charset="...">
     *   2. <meta http-equiv="Content-Type" content="...charset=...">
     *   3. mb_detect_encoding() as fallback
     */
    private static function toUtf8(string $html): string
    {
        $charset = null;

        // <meta charset="utf-8"> or <meta charset='utf-8'>
        if (preg_match('/<meta[^>]+charset\s*=\s*["\']?\s*([a-zA-Z0-9\-_]+)/i', $html, $m)) {
            $charset = strtolower(trim($m[1]));
        }

        // <meta http-equiv="Content-Type" content="text/html; charset=windows-1250">
        if (!$charset && preg_match('/charset\s*=\s*([a-zA-Z0-9\-_]+)/i', $html, $m)) {
            $charset = strtolower(trim($m[1]));
        }

        if (!$charset || $charset === 'utf-8' || $charset === 'utf8') {
            return $html; // already UTF-8, or unknown → treat as UTF-8
        }

        // Map common aliases
        $map = [
            'windows-1250' => 'Windows-1250',
            'windows-1251' => 'Windows-1251',
            'windows-1252' => 'Windows-1252',
            'iso-8859-1'   => 'ISO-8859-1',
            'iso-8859-2'   => 'ISO-8859-2',
            'latin1'       => 'ISO-8859-1',
            'latin2'       => 'ISO-8859-2',
        ];

        $enc = $map[$charset] ?? null;
        if ($enc && function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($html, 'UTF-8', $enc);
            if ($converted !== false) return $converted;
        }

        return $html; // best-effort fallback
    }

    // ── Extraction ────────────────────────────────────────────────────────────

    private static function extractTitle(string $html): ?string
    {
        // 1. og:title
        $og = self::ogMeta($html, 'og:title')
           ?? self::ogMeta($html, 'twitter:title');
        if ($og) return self::cleanText($og, self::MAX_TITLE);

        // 2. <title>
        if (preg_match('/<title[^>]*>\s*(.*?)\s*<\/title>/is', $html, $m)) {
            $t = self::cleanText($m[1], self::MAX_TITLE);
            if ($t) return $t;
        }

        return null;
    }

    private static function extractDescription(string $html): ?string
    {
        // 1. og:description
        $og = self::ogMeta($html, 'og:description')
           ?? self::ogMeta($html, 'twitter:description');
        if ($og) return self::cleanText($og, self::MAX_DESC);

        // 2. <meta name="description">
        if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)["\'][^>]*>/i', $html, $m)
         || preg_match('/<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']description["\'][^>]*>/i', $html, $m)) {
            return self::cleanText($m[1], self::MAX_DESC);
        }

        return null;
    }

    /**
     * Extract og: or twitter: meta content.
     * Handles both attribute orders:
     *   <meta property="og:title" content="...">
     *   <meta content="..." property="og:title">
     */
    private static function ogMeta(string $html, string $property): ?string
    {
        $prop = preg_quote($property, '/');
        // property/name before content
        if (preg_match(
            '/<meta[^>]+(?:property|name)=["\']' . $prop . '["\'][^>]+content=["\']([^"\']*)["\'][^>]*>/i',
            $html, $m
        )) {
            return $m[1] !== '' ? $m[1] : null;
        }
        // content before property/name
        if (preg_match(
            '/<meta[^>]+content=["\']([^"\']*)["\'][^>]+(?:property|name)=["\']' . $prop . '["\'][^>]*>/i',
            $html, $m
        )) {
            return $m[1] !== '' ? $m[1] : null;
        }
        return null;
    }

    private static function cleanText(string $s, int $max): ?string
    {
        // Decode HTML entities, strip tags, normalize whitespace
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = strip_tags($s);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        $s = trim($s);
        if ($s === '') return null;
        return mb_substr($s, 0, $max);
    }
}
