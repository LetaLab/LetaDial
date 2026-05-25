<?php
/**
 * LetaDial — Updater (sesja 059 + sesja 065)
 *
 * Sesja 059: GitHub Releases API check (GITHUB_REPO) — baner dla admina.
 * Sesja 065: Git-based update check vs PUBLIC GitHub repo (LetaLab/LetaDial).
 *
 * gitCheck():
 *   Używa GitHub Compare API żeby porównać lokalny HEAD SHA z main na GitHub.
 *   NIE robi git fetch — nie potrzebuje pisać do lokalnego repo.
 *   Zwraca listę commitów "co nowego" z GitHub.
 *
 * gitPull():
 *   Wykonuje git pull origin main (z coderepo.andrzejl.eu — tu są credentials)
 *   + bash fix_permissions.sh
 *
 * Dlaczego dwa różne remotes?
 *   Check = porównanie z publicznym GitHub (LetaLab/LetaDial) — "co jest dostępne"
 *   Pull  = pobieranie z prywatnego repo (coderepo.andrzejl.eu) — "gdzie są credentials"
 *   Założenie: oba repo są zsynchronizowane (push do obu).
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

class Updater
{
    // GitHub public repo — zawsze tu sprawdzamy dostępność aktualizacji
    private const GITHUB_UPDATE_REPO = 'LetaLab/LetaDial';
    private const TIMEOUT            = 8;

    // ── Ścieżki ───────────────────────────────────────────────────────────────
    private static function appDir(): string
    {
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

    // ── Git: sprawdzenie aktualizacji vs GitHub ───────────────────────────────

    /**
     * Porównuje lokalny HEAD z main na github.com/LetaLab/LetaDial
     * używając GitHub Compare API — nie wymaga git fetch ani credentials.
     *
     * Kroki:
     *   1. git rev-parse HEAD         → lokalny SHA (pełny)
     *   2. GitHub API /compare/{sha}...main → ahead_by, behind_by, commits
     *
     * Zwraca:
     *   ['ok' => bool, 'update_available' => bool,
     *    'local_sha' => string, 'remote_sha' => string,
     *    'commits' => [['sha'=>..,'msg'=>..]], 'commit_count' => int,
     *    'error' => ?string]
     */
    public static function gitCheck(): array
    {
        if (!self::execAvailable()) {
            return ['ok' => false, 'error' => 'exec() not available on this server.'];
        }

        // 1. Lokalny SHA
        $localRes = self::git('rev-parse HEAD');
        if (!$localRes['ok']) {
            return ['ok' => false, 'error' => 'git rev-parse failed: ' . $localRes['output']];
        }
        $localSha = trim($localRes['output']);
        if (strlen($localSha) !== 40) {
            return ['ok' => false, 'error' => 'Could not read local commit SHA.'];
        }

        // 2. GitHub Compare API — {base}...{head}
        // base = lokalny SHA, head = main na GitHub
        // Jeśli behind_by > 0 → mamy zaległe commity do pobrania
        $repo     = self::GITHUB_UPDATE_REPO;
        $shortSha = substr($localSha, 0, 7);
        $apiUrl   = "https://api.github.com/repos/{$repo}/compare/{$localSha}...main";

        $ctx = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => self::TIMEOUT,
                'follow_location' => true,
                'max_redirects'   => 2,
                'user_agent'      => 'LetaDial/' . (defined('APP_VERSION') ? APP_VERSION : '0') . ' updater',
                'header'          => implode("\r\n", [
                    'Accept: application/vnd.github+json',
                    'X-GitHub-Api-Version: 2022-11-28',
                ]),
                'ignore_errors'   => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $response = @file_get_contents($apiUrl, false, $ctx);
        if ($response === false || $response === '') {
            return ['ok' => false, 'error' => 'Could not reach GitHub. Check network connectivity.'];
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'Invalid response from GitHub API.'];
        }

        // GitHub zwraca 404 jeśli SHA nie istnieje w repo
        if (isset($data['message'])) {
            // Lokalny SHA nie istnieje w GitHub repo — możliwe przy pierwszym deployu z innego repo
            // Fallback: sprawdź najnowszy SHA na main i zwróć "update available"
            $mainUrl  = "https://api.github.com/repos/{$repo}/commits/main";
            $mainResp = @file_get_contents($mainUrl, false, $ctx);
            if ($mainResp) {
                $mainData = json_decode($mainResp, true);
                $remoteSha = $mainData['sha'] ?? '';
                if ($remoteSha && $remoteSha !== $localSha) {
                    return [
                        'ok'               => true,
                        'update_available' => true,
                        'local_sha'        => $shortSha,
                        'remote_sha'       => substr($remoteSha, 0, 7),
                        'commit_count'     => 0,
                        'commits'          => [],
                        'note'             => 'Local SHA not found in GitHub repo. Manual update recommended.',
                        'error'            => null,
                    ];
                }
            }
            return ['ok' => false, 'error' => 'GitHub API: ' . $data['message']];
        }

        $behindBy   = (int)($data['behind_by']   ?? 0);
        $remoteSha  = $data['merge_base_commit']['sha'] ?? '';
        // Dla "co nowego" — bierzemy commity z ahead (GitHub main vs nasze HEAD)
        // W porównaniu {local}...main: "commits" to commity które MA main a nie ma local
        $rawCommits = $data['commits'] ?? [];
        $commits    = [];
        foreach ($rawCommits as $c) {
            $msg = trim($c['commit']['message'] ?? '');
            // Pierwsza linia commita
            $msg = explode("\n", $msg)[0];
            $commits[] = [
                'sha' => substr($c['sha'] ?? '', 0, 7),
                'msg' => $msg,
            ];
        }

        // remote_sha = HEAD commita main na GitHub
        $remoteHeadSha = '';
        if (!empty($rawCommits)) {
            $last = end($rawCommits);
            $remoteHeadSha = substr($last['sha'] ?? '', 0, 7);
        }

        $updateAvailable = $behindBy > 0 || count($commits) > 0;

        return [
            'ok'               => true,
            'update_available' => $updateAvailable,
            'local_sha'        => $shortSha,
            'remote_sha'       => $remoteHeadSha ?: 'main',
            'commit_count'     => count($commits),
            'commits'          => array_reverse($commits), // newest last = chronological
            'behind_by'        => $behindBy,
            'error'            => null,
        ];
    }

    /**
     * git pull origin main + bash fix_permissions.sh
     * Pull jest z origin (coderepo.andrzejl.eu) gdzie są credentials www-data.
     */
    public static function gitPull(): array
    {
        if (!self::execAvailable()) {
            return ['ok' => false, 'error' => 'exec() not available on this server.'];
        }

        $dir = self::appDir();

        $pull    = self::git('pull origin main');
        $pullOut = $pull['output'];

        // Immediately remove install.php if git pull restored it from repo.
        // There is a brief window between git pull and fix_permissions.sh where
        // install.php would be accessible via HTTP — close it explicitly here.
        $installPhp = $dir . '/install.php';
        if (file_exists($installPhp)) {
            @unlink($installPhp);
            $pullOut .= "\n[LetaDial] install.php removed after git pull.";
        }

        $script   = $dir . '/fix_permissions.sh';
        $permsOut = '';

        if (file_exists($script) && is_executable($script)) {
            $scriptEsc  = escapeshellarg($script);
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
    // Sesja 059 — GitHub Releases API (baner wersji w dashboard)
    // ═════════════════════════════════════════════════════════════════════════

    private const CACHE_KEY_VERSION  = 'updater_latest_version';
    private const CACHE_KEY_URL      = 'updater_latest_url';
    private const CACHE_KEY_CHECKED  = 'updater_last_checked';
    private const CACHE_KEY_NOTES    = 'updater_release_notes';
    private const DEFAULT_TTL        = 21600;

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
