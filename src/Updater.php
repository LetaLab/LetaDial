<?php
/**
 * LetaDial — Updater (sesja 059 + sesja 065)
 *
 * Sesja 059: GitHub Releases API check (GITHUB_REPO) — baner dla admina.
 * Sesja 065: Git-based update — fetch, log, pull, fix_permissions.
 *
 * Git metody:
 *   gitCheck()   — git fetch + git log HEAD..origin/main (co nowego)
 *   gitPull()    — git pull + bash fix_permissions.sh
 *   gitStatus()  — git rev-parse HEAD i origin/main (SHA porównanie)
 *
 * Wymagania:
 *   - PHP exec() lub shell_exec() dostępne
 *   - www-data ma dostęp do remote (HTTPS z credentials w URL lub SSH)
 *   - APP_DIR = katalog główny aplikacji (dirname(__FILE__).'/..')
 *
 * Bezpieczeństwo:
 *   - Żadne dane użytkownika nie trafiają do shell_exec
 *   - Ścieżki budowane z __DIR__ (integer, nie input)
 *   - Wynik exec() jest escapowany przed zwrotem do JS
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

class Updater
{
    // ── Ścieżki ───────────────────────────────────────────────────────────────
    private static function appDir(): string
    {
        // src/Updater.php → katalog główny to ../
        return realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    }

    private static function git(string $cmd): array
    {
        $dir     = escapeshellarg(self::appDir());
        $full    = "git -C {$dir} {$cmd} 2>&1";
        $output  = [];
        $retcode = 0;
        exec($full, $output, $retcode);
        return [
            'ok'     => $retcode === 0,
            'output' => implode("\n", $output),
            'lines'  => $output,
            'code'   => $retcode,
        ];
    }

    private static function execAvailable(): bool
    {
        if (!function_exists('exec')) return false;
        $disabled = array_map('trim', explode(',', ini_get('disable_functions') ?: ''));
        return !in_array('exec', $disabled, true);
    }

    // ── Git: sprawdzenie aktualizacji ─────────────────────────────────────────

    /**
     * Sprawdza czy są dostępne aktualizacje.
     *
     * Kroki:
     *   1. git fetch origin main
     *   2. git rev-parse HEAD                → lokalny SHA
     *   3. git rev-parse origin/main         → zdalny SHA
     *   4. git log HEAD..origin/main --oneline → lista commitów
     *
     * Zwraca:
     *   ['ok' => bool, 'update_available' => bool,
     *    'local_sha' => string, 'remote_sha' => string,
     *    'commits' => [['sha'=>..,'msg'=>..]], 'error' => ?string]
     */
    public static function gitCheck(): array
    {
        if (!self::execAvailable()) {
            return ['ok' => false, 'error' => 'exec() not available on this server.'];
        }

        // 1. fetch
        $fetch = self::git('fetch origin main');
        if (!$fetch['ok']) {
            return [
                'ok'    => false,
                'error' => 'git fetch failed: ' . $fetch['output'],
            ];
        }

        // 2. lokalny SHA
        $localRes = self::git('rev-parse HEAD');
        $localSha = trim($localRes['output']);

        // 3. zdalny SHA
        $remoteRes = self::git('rev-parse origin/main');
        $remoteSha = trim($remoteRes['output']);

        $updateAvailable = ($localSha !== $remoteSha)
            && strlen($localSha)  === 40
            && strlen($remoteSha) === 40;

        // 4. lista commitów
        $commits = [];
        if ($updateAvailable) {
            $logRes = self::git('log HEAD..origin/main --oneline --no-merges');
            foreach ($logRes['lines'] as $line) {
                $line = trim($line);
                if (!$line) continue;
                $sha = substr($line, 0, 7);
                $msg = ltrim(substr($line, 7));
                $commits[] = ['sha' => $sha, 'msg' => $msg];
            }
        }

        return [
            'ok'               => true,
            'update_available' => $updateAvailable,
            'local_sha'        => substr($localSha,  0, 7),
            'remote_sha'       => substr($remoteSha, 0, 7),
            'commit_count'     => count($commits),
            'commits'          => $commits,
            'error'            => null,
        ];
    }

    /**
     * Wykonuje git pull origin main + bash fix_permissions.sh
     *
     * Zwraca:
     *   ['ok' => bool, 'pull_output' => string,
     *    'perms_output' => string, 'error' => ?string]
     */
    public static function gitPull(): array
    {
        if (!self::execAvailable()) {
            return ['ok' => false, 'error' => 'exec() not available on this server.'];
        }

        $dir = self::appDir();

        // git pull
        $pull = self::git('pull origin main');

        $pullOut = $pull['output'];

        // fix_permissions.sh
        $script = $dir . '/fix_permissions.sh';
        $permsOut = '';

        if (file_exists($script) && is_executable($script)) {
            $scriptEsc = escapeshellarg($script);
            $permsLines = [];
            $permsCode  = 0;
            exec("bash {$scriptEsc} 2>&1", $permsLines, $permsCode);
            $permsOut = implode("\n", $permsLines);
        } else {
            $permsOut = 'fix_permissions.sh not found or not executable — skipped.';
        }

        return [
            'ok'           => $pull['ok'],
            'pull_output'  => $pullOut,
            'perms_output' => $permsOut,
            'error'        => $pull['ok'] ? null : 'git pull returned exit code ' . $pull['code'],
        ];
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Sesja 059 — GitHub Releases API (baner wersji)
    // ═════════════════════════════════════════════════════════════════════════

    private const CACHE_KEY_VERSION  = 'updater_latest_version';
    private const CACHE_KEY_URL      = 'updater_latest_url';
    private const CACHE_KEY_CHECKED  = 'updater_last_checked';
    private const CACHE_KEY_NOTES    = 'updater_release_notes';
    private const DEFAULT_TTL        = 21600;
    private const TIMEOUT            = 5;

    public static function check(bool $force = false): ?array
    {
        if (!defined('GITHUB_REPO') || GITHUB_REPO === '') return null;

        $current = defined('APP_VERSION') ? APP_VERSION : '0.0.0';
        $ttl     = defined('UPDATER_CACHE_TTL') ? (int)UPDATER_CACHE_TTL : self::DEFAULT_TTL;

        $lastChecked = DB::val("SELECT value FROM settings WHERE key_name = ?", [self::CACHE_KEY_CHECKED]);
        $cacheValid  = !$force && $lastChecked !== null
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

        $result = self::fetchLatestRelease(GITHUB_REPO);
        if ($result === null) {
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

    public static function forceCheck(): ?array { return self::check(force: true); }

    private static function fetchLatestRelease(string $repo): ?array
    {
        if (!preg_match('/^[a-zA-Z0-9_.\-]+\/[a-zA-Z0-9_.\-]+$/', $repo)) return null;

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
                'ignore_errors'   => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) return null;

        $data = json_decode($response, true);
        if (!is_array($data) || empty($data['tag_name'])) return null;

        $version = ltrim($data['tag_name'], 'vV');
        $notes   = null;
        if (!empty($data['body'])) {
            $body  = trim($data['body']);
            $lines = array_filter(explode("\n", $body));
            $first = trim(reset($lines) ?: '');
            $first = ltrim($first, '#* ');
            if ($first) $notes = mb_substr($first, 0, 500);
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
