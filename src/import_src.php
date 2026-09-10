<?php
/**
 * LetaDial — Import
 * Sesja 054: notes field imported when present
 * Sesja 07.09.2026 (BUG-032): host-derived fallback title now capped via
 * cleanStr()/MAX_TITLE, matching dial_src.php; per-row execute() wrapped in
 * try/catch(PDOException) so one bad row is skipped instead of aborting
 * the whole import uncaught.
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

        foreach ($rawDials as $i => $d) {
            if ($existingCount + $created >= $limits['dials']) {
                // BUG-008: once the limit is reached it can never become
                // un-reached later in this loop ($created only grows), so
                // every entry from here on is going to be skipped anyway.
                // The old `continue` re-ran this same check for each of
                // the remaining entries in a (possibly large, up to the
                // 10MB file-size cap) import instead of stopping — same
                // final 'skipped' total either way, just reached without
                // the wasted iterations. $i is 0-based (usort() above
                // re-indexes numerically), so count($rawDials) - $i counts
                // this entry plus everything still to come.
                $skipped += count($rawDials) - $i;
                break;
            }

            // BUG-029: a hand-edited or malformed import file can contain a
            // non-array entry in the dials list (a bare string, number, or
            // null). Every ?? read below would otherwise trigger a PHP
            // "illegal offset" warning on such an entry instead of cleanly
            // skipping it - harmless (validateUrl('') already rejects it
            // either way), but noisy in the logs and inconsistent with how
            // the surrounding structure is validated at the array level.
            if (!is_array($d)) { $skipped++; continue; }

            $url   = self::validateUrl($d['url'] ?? '');
            $title = self::cleanStr($d['title'] ?? '', self::MAX_TITLE);
            $notes = self::cleanStr($d['notes'] ?? '', self::MAX_NOTES);
            $group = $d[$groupKey] ?? '';

            if (!$url) { $skipped++; continue; }

            $groupId = $groupMap[$group] ?? null;
            if (!$groupId) { $skipped++; continue; }

            if (isset($existingUrls[$groupId][$url])) { $skipped++; continue; }

            if (!$title) {
                // BUG-032 (sesja 07.09.2026): the host-derived fallback title
                // used to skip cleanStr()/MAX_TITLE entirely, unlike the
                // primary $title assignment a few lines above and unlike the
                // equivalent fallback in dial_src.php's create()/update()
                // (which always wraps _titleFromUrl() in _cleanTitle()).
                // filter_var(FILTER_VALIDATE_URL) accepts hostnames up to
                // 253 chars (the DNS limit), comfortably exceeding
                // dials.title's VARCHAR(100) - verified empirically on a
                // real MariaDB 10.11.14 instance with the project's own
                // default (STRICT_TRANS_TABLES) sql_mode: an over-length
                // title here threw an uncaught PDOException
                // (SQLSTATE[22001]: Data too long for column 'title'),
                // aborting the rest of the import mid-loop with no
                // transaction to undo the rows already committed before it.
                $host  = parse_url($url, PHP_URL_HOST) ?? $url;
                $title = self::cleanStr(preg_replace('/^www\./i', '', $host), self::MAX_TITLE);
            }

            $pos = ($maxPos[$groupId] ?? -1) + 1;

            // BUG-032: defense-in-depth. The cleanStr() fix above should
            // make an over-length title impossible going forward, but this
            // still guards against any OTHER unanticipated column
            // constraint on a single malformed row - it now costs that one
            // row (counted as skipped) instead of aborting the whole
            // import uncaught, consistent with the SEC-110 pattern already
            // used elsewhere in this project for unique-constraint races.
            // $maxPos/$existingUrls bookkeeping is only committed AFTER a
            // successful execute(), so a skipped row does not leave a
            // position gap or falsely mark its URL as already imported.
            try {
                $stmt->execute([$userId, $groupId, $title, $url, $notes ?: null, $pos]);
            } catch (\PDOException $e) {
                error_log('[Import] importDials() row skipped due to DB error: ' . $e->getMessage());
                $skipped++;
                continue;
            }

            $maxPos[$groupId] = $pos;
            $existingUrls[$groupId][$url] = true;
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
        // SEC-095: dials fallback raised 500 -> 5000. The DB value in
        // `settings` (seeded by install.php, editable directly in the DB)
        // is what actually governs existing installs. This literal is
        // only used if that row is ever missing.
        return [
            'groups' => (int)(DB::val("SELECT value FROM settings WHERE key_name='max_groups_per_user'") ?? 50),
            'dials'  => (int)(DB::val("SELECT value FROM settings WHERE key_name='max_dials_per_user'")  ?? 5000),
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
