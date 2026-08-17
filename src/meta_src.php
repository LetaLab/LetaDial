<?php
/**
 * LetaDial — Meta Fetcher (sesja 057)
 *
 * Fetches <title> and Open Graph meta tags from a URL.
 * Returns: ['title' => '...', 'description' => '...', 'og_image' => '...']
 *
 * Security:
 *   - SSRF: blocks private/loopback/reserved IPs (same as thumbnail_src.php)
 *   - SEC-086/SEC-087: resolves the host ONCE per hop and connects directly
 *     to that validated IP (see resolvePinned()) instead of letting PHP's
 *     stream wrapper re-resolve the hostname independently at connect time.
 *     Closes DNS-rebinding TOCTOU (SEC-086) and unvalidated-AAAA bypass
 *     (SEC-087) in one mechanism — see resolvePinned()/download() docblocks.
 *   - Redirect: max 3 hops, EACH hop re-resolved + re-validated (SEC-081,
 *     extended by SEC-086/087) — never trusts PHP's built-in follow_location
 *   - Timeout: 5s connect + read
 *   - Size limit: reads max 65536 bytes — <head> is always enough
 *   - Charset: auto-detected from Content-Type header or <meta charset>
 *   - Rate limit: enforced by the API endpoint (api/meta_api.php), not here
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

    /**
     * Fast pre-filter: format + scheme only. This intentionally no longer
     * performs its own DNS resolution / private-range check — SEC-086 and
     * SEC-087 moved the REAL, authoritative SSRF check into
     * resolvePinned(), which download() calls on every hop (including the
     * first) immediately before it connects. Keeping a second, independent
     * host-safety check here would either duplicate that logic or, worse,
     * drift out of sync with it over time; one source of truth for "is
     * this IP safe to connect to" is safer than two.
     */
    private static function isSafeUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) return false;

        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) return false;

        $host = parse_url($url, PHP_URL_HOST);
        return $host !== null && $host !== '';
    }

    // ── Download ──────────────────────────────────────────────────────────────

    /**
     * SEC-081: fetches $url, following up to 3 redirects — but RE-VALIDATING
     * every redirect target before following it.
     *
     * SEC-086/SEC-087: on EVERY hop (including the first), the host is
     * resolved and validated exactly once via resolvePinned(), and the
     * actual connection is made directly to that literal IP — never by
     * handing the hostname to fopen() and letting PHP's stream wrapper
     * resolve it again independently. This closes two related gaps that
     * isSafeUrl()'s old single gethostbyname() check did not:
     *   - DNS rebinding (TOCTOU): a DNS zone the attacker controls could
     *     previously answer a public IP for the validation lookup and a
     *     private/internal IP for the real connection moments later, since
     *     those were two separate, independently-timed resolutions of the
     *     same hostname. There is only one resolution now.
     *   - Unvalidated AAAA bypass: gethostbyname() only ever inspects A
     *     (IPv4) records. A malicious AAAA record (e.g. pointing at ::1)
     *     was never checked, and PHP's stream wrapper could still prefer
     *     it at connect time. resolvePinned() validates AAAA too and
     *     rejects the host outright if any A or AAAA record is
     *     private/reserved, then this method only ever connects to the one
     *     specific, pre-validated IPv4 address it returned — there is no
     *     address-family selection left for the OS/PHP to make on its own.
     *
     * TLS certificate validation still checks the ORIGINAL hostname (via
     * ssl.peer_name below), not the IP literally being connected to, so a
     * certificate mismatch is still caught exactly as before.
     */
    private static function download(string $url): ?string
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
                    'method'          => 'GET',
                    'timeout'         => self::TIMEOUT,
                    'follow_location' => false, // SEC-081: followed manually below, with re-validation
                    'user_agent'      => 'LetaDial/2.0 MetaBot (+https://github.com)',
                    'header'          => implode("\r\n", [
                        'Host: ' . $hostHeader,
                        'Accept: text/html,application/xhtml+xml',
                        'Accept-Language: en,*;q=0.5',
                        'Connection: close',
                    ]),
                    'ignore_errors'   => true, // so 3xx responses stay inspectable below
                ],
            ];
            if ($scheme === 'https') {
                $ctxOpts['ssl'] = [
                    'verify_peer'      => true,
                    'verify_peer_name' => true,
                    // Pin SNI + certificate hostname verification to the
                    // ORIGINAL domain, not the IP we're literally
                    // connecting to — the certificate is issued for the
                    // domain, so this keeps that check meaningful.
                    'peer_name'        => $host,
                ];
            }
            $ctx = stream_context_create($ctxOpts);

            // Suppress warnings — failure is handled via return values below.
            $handle = @fopen($pinnedUrl, 'r', false, $ctx);
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
                // Re-resolved + re-pinned (resolvePinned()) at the top of
                // the next loop iteration — never trusted from this hop.
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

    /**
     * SEC-086/SEC-087: resolve $host to ONE validated public IPv4 address
     * suitable for a pinned connection (see download() above).
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
