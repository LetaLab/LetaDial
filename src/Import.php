<?php
/**
 * LetaDial — Import
 * Sesja 054: notes field imported when present
 *
 * Supports two formats:
 *   A) LetaDial JSON  {"version":"1.x","app":"LetaDial","groups":[...],"dials":[...]}
 *   B) Legacy db format {"db":{"groups":[...],"dials":[...]}}
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

class Import
{
    private const MAX_FILE_SIZE = 10 * 1024 * 1024;
    private const MAX_TITLE     = 100;
    private const MAX_GROUP     = 100;
    private const MAX_NOTES     = 500;

    public static function fromJson(string $json, int $userId): array
    {
        if (strlen($json) > self::MAX_FILE_SIZE) {
            return ['ok' => false, 'error' => 'File too large (max 10MB).'];
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'Invalid JSON file.'];
        }

        if (isset($data['db']['groups'], $data['db']['dials'])) {
            return self::importLegacyDb($data['db'], $userId);
        }
        if (isset($data['groups'], $data['dials']) && ($data['app'] ?? '') === 'LetaDial') {
            return self::importLetaDial($data, $userId);
        }

        return ['ok' => false, 'error' => 'Unknown format. Expected LetaDial JSON.'];
    }

    // ── LetaDial format ───────────────────────────────────────────────────────

    private static function importLetaDial(array $data, int $userId): array
    {
        $rawGroups = $data['groups'] ?? [];
        $rawDials  = $data['dials']  ?? [];

        if (!is_array($rawGroups) || !is_array($rawDials)) {
            return ['ok' => false, 'error' => 'Malformed LetaDial JSON.'];
        }

        $groupMap = self::buildGroupMap($userId);
        $maxPos   = self::maxGroupPos($userId);
        $limits   = self::getLimits();

        $groupsCreated = 0;
        foreach ($rawGroups as $g) {
            $name = self::cleanStr($g['name'] ?? '', self::MAX_GROUP);
            if (!$name) continue;
            if (isset($groupMap[$name])) continue;
            if (count($groupMap) >= $limits['groups']) break;

            $maxPos++;
            DB::run("INSERT INTO groups_list (user_id, name, position) VALUES (?, ?, ?)",
                [$userId, $name, $maxPos]);
            $groupMap[$name] = (int)DB::lastId();
            $groupsCreated++;
        }

        [$dialsCreated, $skipped] = self::importDials($rawDials, $userId, $groupMap, $limits, 'group');

        return [
            'ok'      => true,
            'groups'  => $groupsCreated,
            'dials'   => $dialsCreated,
            'skipped' => $skipped,
            'format'  => 'LetaDial',
        ];
    }

    // ── Legacy db format ──────────────────────────────────────────────────────

    private static function importLegacyDb(array $db, int $userId): array
    {
        $rawGroups = $db['groups'] ?? [];
        $rawDials  = $db['dials']  ?? [];

        if (!is_array($rawGroups) || !is_array($rawDials)) {
            return ['ok' => false, 'error' => 'Malformed JSON: expected groups and dials arrays.'];
        }

        $groupIdToName = [];
        foreach ($rawGroups as $g) {
            $id   = (int)($g['id'] ?? 0);
            $name = self::cleanStr($g['name'] ?? '', self::MAX_GROUP);
            if ($id && $name) $groupIdToName[$id] = $name;
        }

        $groupMap = self::buildGroupMap($userId);
        $maxPos   = self::maxGroupPos($userId);
        $limits   = self::getLimits();

        $groupsCreated = 0;
        foreach ($groupIdToName as $name) {
            if (isset($groupMap[$name])) continue;
            if (count($groupMap) >= $limits['groups']) break;

            $maxPos++;
            DB::run("INSERT INTO groups_list (user_id, name, position) VALUES (?, ?, ?)",
                [$userId, $name, $maxPos]);
            $groupMap[$name] = (int)DB::lastId();
            $groupsCreated++;
        }

        $remapped = [];
        foreach ($rawDials as $d) {
            $gid = (int)($d['group_id'] ?? 0);
            if (!isset($groupIdToName[$gid])) continue;
            $remapped[] = [
                'url'      => $d['url']      ?? '',
                'title'    => $d['title']    ?? '',
                'notes'    => $d['notes']    ?? '',
                'group'    => $groupIdToName[$gid],
                'position' => (int)($d['position'] ?? 0),
            ];
        }

        [$dialsCreated, $skipped] = self::importDials($remapped, $userId, $groupMap, $limits, 'group');

        return [
            'ok'      => true,
            'groups'  => $groupsCreated,
            'dials'   => $dialsCreated,
            'skipped' => $skipped,
            'format'  => 'Speed dial import',
        ];
    }

    // ── Shared dial import ────────────────────────────────────────────────────

    private static function importDials(
        array $rawDials,
        int   $userId,
        array $groupMap,
        array $limits,
        string $groupKey
    ): array {
        $created = 0;
        $skipped = 0;

        $existingCount = (int)(DB::val(
            "SELECT COUNT(*) FROM dials WHERE user_id = ?", [$userId]
        ) ?? 0);

        $existingUrls = [];
        $rows = DB::rows("SELECT group_id, url FROM dials WHERE user_id = ?", [$userId]);
        foreach ($rows as $r) {
            $existingUrls[$r['group_id']][$r['url']] = true;
        }

        usort($rawDials, fn($a, $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));

        $maxPos = [];
        $posRows = DB::rows(
            "SELECT group_id, COALESCE(MAX(position),-1) AS mp FROM dials WHERE user_id = ? GROUP BY group_id",
            [$userId]
        );
        foreach ($posRows as $r) {
            $maxPos[$r['group_id']] = (int)$r['mp'];
        }

        $stmt = DB::get()->prepare(
            "INSERT INTO dials (user_id, group_id, title, url, notes, position) VALUES (?, ?, ?, ?, ?, ?)"
        );

        foreach ($rawDials as $d) {
            if ($existingCount + $created >= $limits['dials']) {
                $skipped++;
                continue;
            }

            $url   = self::validateUrl($d['url'] ?? '');
            $title = self::cleanStr($d['title'] ?? '', self::MAX_TITLE);
            $notes = self::cleanStr($d['notes'] ?? '', self::MAX_NOTES);
            $group = $d[$groupKey] ?? '';

            if (!$url) { $skipped++; continue; }

            $groupId = $groupMap[$group] ?? null;
            if (!$groupId) { $skipped++; continue; }

            if (isset($existingUrls[$groupId][$url])) { $skipped++; continue; }

            if (!$title) {
                $host  = parse_url($url, PHP_URL_HOST) ?? $url;
                $title = preg_replace('/^www\./i', '', $host);
            }

            $pos = ($maxPos[$groupId] ?? -1) + 1;
            $maxPos[$groupId] = $pos;
            $existingUrls[$groupId][$url] = true;

            $stmt->execute([$userId, $groupId, $title, $url, $notes ?: null, $pos]);
            $created++;
        }

        return [$created, $skipped];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function buildGroupMap(int $userId): array
    {
        $rows = DB::rows("SELECT id, name FROM groups_list WHERE user_id = ?", [$userId]);
        $map = [];
        foreach ($rows as $r) $map[$r['name']] = (int)$r['id'];
        return $map;
    }

    private static function maxGroupPos(int $userId): int
    {
        return (int)(DB::val(
            "SELECT COALESCE(MAX(position),-1) FROM groups_list WHERE user_id = ?", [$userId]
        ) ?? -1);
    }

    private static function getLimits(): array
    {
        return [
            'groups' => (int)(DB::val("SELECT value FROM settings WHERE key_name='max_groups_per_user'") ?? 50),
            'dials'  => (int)(DB::val("SELECT value FROM settings WHERE key_name='max_dials_per_user'")  ?? 500),
        ];
    }

    private static function cleanStr(string $s, int $max): string
    {
        return mb_substr(trim(strip_tags($s)), 0, $max);
    }

    private static function validateUrl(string $url): string|false
    {
        $url = trim($url);
        if (!$url || strlen($url) > 2048) return false;
        if (!preg_match('/^https?:\/\//i', $url)) $url = 'https://' . $url;
        if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) return false;
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host || strlen($host) < 2) return false;
        return $url;
    }
}
