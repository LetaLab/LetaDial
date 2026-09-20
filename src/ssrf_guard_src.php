<?php
/**
 * LetaDial — SsrfGuard (SEC-153, SEC_AND_BUG_ANIH_PLAN.md, Czesc XVII)
 *
 * Single, shared home for the "resolve a hostname to one validated,
 * connect-safe public IPv4 address" logic that Thumbnail (fetching a
 * dial's OG image / favicon) and Meta (fetching a dial's <title>/<meta
 * description>) both need before making any outbound HTTP request to a
 * user-supplied dial URL.
 *
 * Before this file existed, thumbnail_src.php::resolvePinned() and
 * meta_src.php::resolvePinned() were two byte-identical, independently
 * maintained copies of the exact same function. That duplication was not
 * itself a live bug — both copies were correct and equivalent — but it was
 * exactly the kind of structural risk that had already bitten this
 * project once before: SEC-086 (DNS-rebinding TOCTOU) and SEC-087
 * (unvalidated AAAA bypass) each had to be fixed SEPARATELY in both files,
 * since no single shared definition existed whose fix would have covered
 * both automatically. A future improvement applied to only one copy —
 * easy to miss, since nothing in the code enforces the two staying in
 * sync — would have quietly left the other file on a weaker SSRF guard.
 * This class is that single shared definition.
 *
 * SEC-086/SEC-087 (original rationale, preserved here since this is now
 * the one place it applies):
 *   Uses gethostbynamel() (plural — every A record) rather than
 *   gethostbyname() (singular — only the first), so a round-robin/multi-A
 *   host cannot hide a private address behind a public one that happens
 *   to be returned first: ANY private/reserved A record rejects the whole
 *   host. Also checks every AAAA record the same way, even though this
 *   method only ever returns an IPv4 address for the caller to connect
 *   over — a host that publishes a private/loopback AAAA (e.g. ::1)
 *   alongside a clean public A is treated as unsafe outright, rather than
 *   assuming the IPv4 pin alone makes that irrelevant.
 *
 *   The caller MUST connect directly to the literal IP this method
 *   returns (never by handing the hostname to fopen()/file_get_contents()
 *   and letting PHP's stream wrapper resolve it again independently) —
 *   that is what closes the DNS-rebinding TOCTOU: a DNS zone the attacker
 *   controls could otherwise answer a public IP for this validation
 *   lookup and a private/internal IP for the real connection moments
 *   later, since those would be two separate, independently-timed
 *   resolutions of the same hostname. There is only ever one resolution
 *   per hop when callers follow this contract — see Thumbnail's
 *   safeFetchBody()/fetchFavicon() and Meta::download() for the reference
 *   implementations of that pinned-connect pattern.
 *
 * TLS certificate validation in the caller should still check the
 * ORIGINAL hostname (via the ssl context's peer_name option), not the IP
 * literally being connected to, so a certificate mismatch is still caught
 * exactly as if no pinning were happening at all.
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

final class SsrfGuard
{
    /**
     * Resolve $host to ONE validated public IPv4 address suitable for a
     * pinned connection.
     *
     * @return string|null a single validated public IPv4 address, or null
     *                      if the host has no usable A record, or if any
     *                      A/AAAA record it publishes is private/reserved.
     */
    public static function resolvePinned(string $host): ?string
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

    /**
     * Convenience boolean wrapper for call sites that only need a yes/no
     * answer (e.g. a pre-check before doing further work), not the IP
     * itself. Callers that go on to make a connection should call
     * resolvePinned() directly and connect to the IP it returns, rather
     * than calling isSafe() and then separately re-resolving the host —
     * doing so would reopen exactly the DNS-rebinding TOCTOU this class
     * exists to close.
     */
    public static function isSafe(string $host): bool
    {
        return self::resolvePinned($host) !== null;
    }
}
