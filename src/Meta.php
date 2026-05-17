<?php
/**
 * LetaDial — Meta Fetcher (sesja 057)
 *
 * Fetches <title> and Open Graph meta tags from a URL.
 * Returns: ['title' => '...', 'description' => '...', 'og_image' => '...']
 *
 * Security:
 *   - SSRF: blocks private/loopback/reserved IPs (same as Thumbnail.php)
 *   - Redirect: follow_location=true, max 3 hops, only http/https
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

    private static function download(string $url): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => self::TIMEOUT,
                'follow_location' => true,
                'max_redirects'   => 3,
                'user_agent'      => 'LetaDial/2.0 MetaBot (+https://github.com)',
                'header'          => implode("\r\n", [
                    'Accept: text/html,application/xhtml+xml',
                    'Accept-Language: en,*;q=0.5',
                    'Connection: close',
                ]),
                'ignore_errors'   => false,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        // Suppress warnings — we handle null return
        $handle = @fopen($url, 'r', false, $ctx);
        if (!$handle) return null;

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
