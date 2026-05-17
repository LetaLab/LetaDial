<?php
/**
 * LetaDial — Updater (sesja 059)
 *
 * Checks GitHub Releases API for a newer version of LetaDial.
 * Results are cached in the settings table to avoid hitting GitHub rate limits
 * (60 anonymous requests/hour — one pageload per admin could exhaust this fast).
 *
 * Cache TTL: 6 hours (configurable via UPDATER_CACHE_TTL constant).
 * Only called for admin users, never for regular users.
 *
 * Configuration (in config.php):
 *   define('GITHUB_REPO', 'andrzejl/letadial');  // owner/repo
 *   define('UPDATER_CACHE_TTL', 21600);            // 6 hours in seconds
 *
 * If GITHUB_REPO is not defined, Updater::check() returns null silently.
 *
 * GitHub API endpoint used:
 *   GET https://api.github.com/repos/{owner}/{repo}/releases/latest
 *   Returns: { tag_name: "v2.1.0", html_url: "...", body: "..." }
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

class Updater
{
    private const CACHE_KEY_VERSION  = 'updater_latest_version';
    private const CACHE_KEY_URL      = 'updater_latest_url';
    private const CACHE_KEY_CHECKED  = 'updater_last_checked';
    private const CACHE_KEY_NOTES    = 'updater_release_notes';
    private const DEFAULT_TTL        = 21600; // 6 hours
    private const TIMEOUT            = 5;     // seconds

    /**
     * Check for updates. Returns update info array or null.
     *
     * @return array{
     *   current: string,
     *   latest: string,
     *   update_available: bool,
     *   url: string,
     *   notes: string|null,
     *   cached: bool,
     *   checked_at: string|null
     * }|null  null if GITHUB_REPO not configured or on fetch error with no cache
     */
    public static function check(bool $force = false): ?array
    {
        if (!defined('GITHUB_REPO') || GITHUB_REPO === '') {
            return null;
        }

        $current = defined('APP_VERSION') ? APP_VERSION : '0.0.0';
        $ttl     = defined('UPDATER_CACHE_TTL') ? (int)UPDATER_CACHE_TTL : self::DEFAULT_TTL;

        // ── Try cache first ───────────────────────────────────────────────────
        $lastChecked = DB::val(
            "SELECT value FROM settings WHERE key_name = ?",
            [self::CACHE_KEY_CHECKED]
        );

        $cacheValid = !$force
            && $lastChecked !== null
            && (time() - strtotime($lastChecked)) < $ttl;

        if ($cacheValid) {
            $latest = DB::val("SELECT value FROM settings WHERE key_name = ?", [self::CACHE_KEY_VERSION]);
            $url    = DB::val("SELECT value FROM settings WHERE key_name = ?", [self::CACHE_KEY_URL]);
            $notes  = DB::val("SELECT value FROM settings WHERE key_name = ?", [self::CACHE_KEY_NOTES]);

            if ($latest) {
                return [
                    'current'          => $current,
                    'latest'           => $latest,
                    'update_available' => version_compare($latest, $current, '>'),
                    'url'              => $url ?? '',
                    'notes'            => $notes ?: null,
                    'cached'           => true,
                    'checked_at'       => $lastChecked,
                ];
            }
        }

        // ── Fetch from GitHub ─────────────────────────────────────────────────
        $result = self::fetchLatestRelease(GITHUB_REPO);

        if ($result === null) {
            // Fetch failed — return stale cache if available, else null
            $latest = DB::val("SELECT value FROM settings WHERE key_name = ?", [self::CACHE_KEY_VERSION]);
            if ($latest) {
                $url   = DB::val("SELECT value FROM settings WHERE key_name = ?", [self::CACHE_KEY_URL]);
                $notes = DB::val("SELECT value FROM settings WHERE key_name = ?", [self::CACHE_KEY_NOTES]);
                return [
                    'current'          => $current,
                    'latest'           => $latest,
                    'update_available' => version_compare($latest, $current, '>'),
                    'url'              => $url ?? '',
                    'notes'            => $notes ?: null,
                    'cached'           => true,
                    'checked_at'       => $lastChecked,
                ];
            }
            return null;
        }

        // ── Store in cache ────────────────────────────────────────────────────
        $now = date('Y-m-d H:i:s');
        self::saveSetting(self::CACHE_KEY_VERSION, $result['version']);
        self::saveSetting(self::CACHE_KEY_URL,     $result['url']);
        self::saveSetting(self::CACHE_KEY_NOTES,   $result['notes'] ?? '');
        self::saveSetting(self::CACHE_KEY_CHECKED,  $now);

        return [
            'current'          => $current,
            'latest'           => $result['version'],
            'update_available' => version_compare($result['version'], $current, '>'),
            'url'              => $result['url'],
            'notes'            => $result['notes'] ?: null,
            'cached'           => false,
            'checked_at'       => $now,
        ];
    }

    /**
     * Force-refresh the cache and return fresh result.
     * Called from the "Check now" button in the admin/settings UI.
     */
    public static function forceCheck(): ?array
    {
        return self::check(force: true);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private static function fetchLatestRelease(string $repo): ?array
    {
        // Sanitise repo string — only allow owner/repo format
        if (!preg_match('/^[a-zA-Z0-9_.\-]+\/[a-zA-Z0-9_.\-]+$/', $repo)) {
            error_log('[Updater] Invalid GITHUB_REPO format: ' . $repo);
            return null;
        }

        $url = 'https://api.github.com/repos/' . $repo . '/releases/latest';

        $ctx = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => self::TIMEOUT,
                'follow_location' => true,
                'max_redirects'   => 2,
                'user_agent'      => 'LetaDial/' . (defined('APP_VERSION') ? APP_VERSION : '0') . ' update-checker',
                'header'          => implode("\r\n", [
                    'Accept: application/vnd.github+json',
                    'X-GitHub-Api-Version: 2022-11-28',
                ]),
                'ignore_errors'   => true, // so we get 404 body too
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) {
            error_log('[Updater] GitHub API fetch failed for repo: ' . $repo);
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || empty($data['tag_name'])) {
            // Could be 404 (no releases yet) or rate limited
            if (isset($data['message'])) {
                error_log('[Updater] GitHub API error: ' . $data['message']);
            }
            return null;
        }

        // Normalise tag: strip leading 'v' → "v2.1.0" → "2.1.0"
        $version = ltrim($data['tag_name'], 'vV');

        // Extract first paragraph of release notes (max 500 chars)
        $notes = null;
        if (!empty($data['body'])) {
            $body = trim($data['body']);
            // Take first non-empty line up to 500 chars
            $lines = array_filter(explode("\n", $body));
            $first = trim(reset($lines) ?: '');
            // Strip markdown heading markers
            $first = ltrim($first, '#* ');
            if ($first) {
                $notes = mb_substr($first, 0, 500);
            }
        }

        return [
            'version' => $version,
            'url'     => $data['html_url'] ?? ('https://github.com/' . $repo . '/releases'),
            'notes'   => $notes,
        ];
    }

    private static function saveSetting(string $key, string $value): void
    {
        DB::run(
            "INSERT INTO settings (key_name, value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)",
            [$key, $value]
        );
    }
}
