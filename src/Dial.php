<?php
/**
 * LetaDial — Dial model
 * Sesja 054: notes field added (TEXT, nullable, max 500 chars enforced in PHP)
 * Sesja 061: pinned field added (TINYINT 0/1) — pinned dials always appear first
 * Sesja 062: getRecent() — virtual "Recently used" group based on last_click
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

class Dial
{
    private const MAX_NOTES  = 500;
    private const RECENT_MAX = 20;

    // ── Read ──────────────────────────────────────────────────────────────────

    public static function getAll(int $userId, ?int $groupId = null): array
    {
        if ($groupId !== null) {
            $rows = DB::rows(
                'SELECT d.*, g.name AS group_name
                 FROM dials d
                 LEFT JOIN groups_list g ON g.id = d.group_id
                 WHERE d.user_id = ? AND d.group_id = ?
                 ORDER BY d.pinned DESC, d.position ASC, d.id ASC',
                [$userId, $groupId]
            );
        } else {
            $rows = DB::rows(
                'SELECT d.*, g.name AS group_name
                 FROM dials d
                 LEFT JOIN groups_list g ON g.id = d.group_id
                 WHERE d.user_id = ?
                 ORDER BY g.position ASC, d.pinned DESC, d.position ASC, d.id ASC',
                [$userId]
            );
        }
        return array_map([self::class, '_hydrate'], $rows ?: []);
    }

    /**
     * Get recently clicked dials — virtual group for sesja 062.
     * Returns up to RECENT_MAX dials ordered by last_click DESC.
     * Only includes dials that have been clicked at least once.
     */
    public static function getRecent(int $userId): array
    {
        $limit = self::RECENT_MAX;
        $rows  = DB::rows(
            'SELECT d.*, g.name AS group_name
             FROM dials d
             LEFT JOIN groups_list g ON g.id = d.group_id
             WHERE d.user_id = ? AND d.last_click IS NOT NULL
             ORDER BY d.last_click DESC
             LIMIT ' . $limit,
            [$userId]
        );
        return array_map([self::class, '_hydrate'], $rows ?: []);
    }

    public static function getOne(int $dialId, int $userId): ?array
    {
        $row = DB::row(
            'SELECT * FROM dials WHERE id = ? AND user_id = ?',
            [$dialId, $userId]
        );
        return $row ? self::_hydrate($row) : null;
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public static function create(int $userId, int $groupId, string $title, string $url, string $notes = ''): array
    {
        $url = self::_normalizeUrl($url);
        if (!self::_validUrl($url)) {
            return ['ok' => false, 'error' => 'Invalid URL.'];
        }

        $group = DB::row(
            'SELECT id FROM groups_list WHERE id = ? AND user_id = ?',
            [$groupId, $userId]
        );
        if (!$group) return ['ok' => false, 'error' => 'Group not found.'];

        $pos = (int)(DB::val(
            'SELECT COALESCE(MAX(position), -1) FROM dials WHERE user_id = ? AND group_id = ?',
            [$userId, $groupId]
        ) ?? -1) + 1;

        $title = $title !== '' ? $title : self::_titleFromUrl($url);
        $notes = self::_cleanNotes($notes);

        DB::run(
            'INSERT INTO dials (user_id, group_id, title, url, notes, position, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$userId, $groupId, $title, $url, $notes ?: null, $pos, date('Y-m-d H:i:s')]
        );

        return ['ok' => true, 'id' => (int)DB::lastId()];
    }

    // ── Pin / Unpin ───────────────────────────────────────────────────────────

    /**
     * Toggle pin state for a dial.
     * Returns ['ok' => true, 'pinned' => bool]
     */
    public static function togglePin(int $dialId, int $userId): array
    {
        $dial = self::getOne($dialId, $userId);
        if (!$dial) return ['ok' => false, 'error' => 'Dial not found.'];

        $newPinned = $dial['pinned'] ? 0 : 1;
        DB::run(
            'UPDATE dials SET pinned = ? WHERE id = ? AND user_id = ?',
            [$newPinned, $dialId, $userId]
        );

        return ['ok' => true, 'pinned' => (bool)$newPinned];
    }

    // ── Duplicate (single) ────────────────────────────────────────────────────

    public static function duplicate(int $dialId, int $userId, int $targetGroupId): array
    {
        $dial = self::getOne($dialId, $userId);
        if (!$dial) {
            return ['ok' => false, 'error' => 'Dial not found.'];
        }

        $group = DB::row(
            'SELECT id FROM groups_list WHERE id = ? AND user_id = ?',
            [$targetGroupId, $userId]
        );
        if (!$group) {
            return ['ok' => false, 'error' => 'Target group not found.'];
        }

        $maxDials = (int)(DB::val(
            "SELECT value FROM settings WHERE key_name = 'max_dials_per_user'"
        ) ?? 500);
        $currentCount = (int)(DB::val(
            'SELECT COUNT(*) FROM dials WHERE user_id = ?',
            [$userId]
        ) ?? 0);
        if ($currentCount >= $maxDials) {
            return ['ok' => false, 'error' => "Dial limit reached (max {$maxDials})."];
        }

        $pos = (int)(DB::val(
            'SELECT COALESCE(MAX(position), -1) FROM dials WHERE user_id = ? AND group_id = ?',
            [$userId, $targetGroupId]
        ) ?? -1) + 1;

        DB::run(
            'INSERT INTO dials (user_id, group_id, title, url, notes, position, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$userId, $targetGroupId, $dial['title'], $dial['url'], $dial['notes'] ?: null, $pos, date('Y-m-d H:i:s')]
        );

        return ['ok' => true, 'id' => (int)DB::lastId()];
    }

    // ── Bulk Delete ───────────────────────────────────────────────────────────

    public static function bulkDelete(array $ids, int $userId): array
    {
        if (empty($ids)) return ['ok' => false, 'error' => 'No IDs provided.'];

        $ids = array_unique(array_map('intval', $ids));
        $ph  = implode(',', array_fill(0, count($ids), '?'));

        $dials = DB::rows(
            "SELECT id, group_id FROM dials WHERE id IN ({$ph}) AND user_id = ?",
            [...$ids, $userId]
        );

        if (empty($dials)) return ['ok' => false, 'error' => 'No valid dials found.'];

        $validIds = array_column($dials, 'id');
        $groupIds = array_unique(array_column($dials, 'group_id'));

        $ph2 = implode(',', array_fill(0, count($validIds), '?'));
        DB::run(
            "DELETE FROM dials WHERE id IN ({$ph2}) AND user_id = ?",
            [...$validIds, $userId]
        );

        foreach ($validIds as $id) {
            try { Thumbnail::delete((int)$id, $userId); } catch (Throwable) {}
        }

        foreach ($groupIds as $gid) {
            self::normalizePositions((int)$gid, $userId);
        }

        return ['ok' => true, 'deleted' => count($validIds)];
    }

    // ── Bulk Move ─────────────────────────────────────────────────────────────

    public static function bulkMove(array $ids, int $userId, int $targetGroupId): array
    {
        if (empty($ids)) return ['ok' => false, 'error' => 'No IDs provided.'];

        $group = DB::row(
            'SELECT id FROM groups_list WHERE id = ? AND user_id = ?',
            [$targetGroupId, $userId]
        );
        if (!$group) return ['ok' => false, 'error' => 'Target group not found.'];

        $ids = array_unique(array_map('intval', $ids));
        $ph  = implode(',', array_fill(0, count($ids), '?'));

        $dials = DB::rows(
            "SELECT id, group_id FROM dials WHERE id IN ({$ph}) AND user_id = ?
             ORDER BY position ASC, id ASC",
            [...$ids, $userId]
        );

        if (empty($dials)) return ['ok' => false, 'error' => 'No valid dials found.'];

        $validIds  = array_column($dials, 'id');
        $oldGroups = array_unique(array_column($dials, 'group_id'));

        $maxPos = (int)(DB::val(
            'SELECT COALESCE(MAX(position), -1) FROM dials WHERE user_id = ? AND group_id = ?',
            [$userId, $targetGroupId]
        ) ?? -1);

        $stmt = DB::get()->prepare(
            'UPDATE dials SET group_id = ?, position = ? WHERE id = ? AND user_id = ?'
        );
        foreach ($validIds as $i => $id) {
            $stmt->execute([$targetGroupId, $maxPos + 1 + $i, $id, $userId]);
        }

        foreach ($oldGroups as $gid) {
            if ((int)$gid !== $targetGroupId) {
                self::normalizePositions((int)$gid, $userId);
            }
        }
        self::normalizePositions($targetGroupId, $userId);

        return ['ok' => true, 'moved' => count($validIds)];
    }

    // ── Bulk Duplicate ────────────────────────────────────────────────────────

    public static function bulkDuplicate(array $ids, int $userId, int $targetGroupId): array
    {
        if (empty($ids)) return ['ok' => false, 'error' => 'No IDs provided.'];

        $group = DB::row(
            'SELECT id FROM groups_list WHERE id = ? AND user_id = ?',
            [$targetGroupId, $userId]
        );
        if (!$group) return ['ok' => false, 'error' => 'Target group not found.'];

        $maxDials = (int)(DB::val(
            "SELECT value FROM settings WHERE key_name = 'max_dials_per_user'"
        ) ?? 500);
        $currentCount = (int)(DB::val(
            'SELECT COUNT(*) FROM dials WHERE user_id = ?',
            [$userId]
        ) ?? 0);

        if ($currentCount >= $maxDials) {
            return ['ok' => false, 'error' => "Dial limit reached (max {$maxDials})."];
        }

        $ids = array_unique(array_map('intval', $ids));
        $ph  = implode(',', array_fill(0, count($ids), '?'));

        $dials = DB::rows(
            "SELECT id, title, url, notes FROM dials WHERE id IN ({$ph}) AND user_id = ?
             ORDER BY position ASC, id ASC",
            [...$ids, $userId]
        );

        if (empty($dials)) return ['ok' => false, 'error' => 'No valid dials found.'];

        $maxPos = (int)(DB::val(
            'SELECT COALESCE(MAX(position), -1) FROM dials WHERE user_id = ? AND group_id = ?',
            [$userId, $targetGroupId]
        ) ?? -1);

        $created = 0;
        $newIds  = [];
        $pdo     = DB::get();
        $stmt    = $pdo->prepare(
            'INSERT INTO dials (user_id, group_id, title, url, notes, position, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($dials as $d) {
            if ($currentCount + $created >= $maxDials) break;
            $maxPos++;
            $stmt->execute([$userId, $targetGroupId, $d['title'], $d['url'], $d['notes'] ?: null, $maxPos, date('Y-m-d H:i:s')]);
            $newIds[] = (int)$pdo->lastInsertId();
            $created++;
        }

        return ['ok' => true, 'duplicated' => $created, 'ids' => $newIds];
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public static function update(int $dialId, int $userId, string $title, string $url, string $notes = ''): array
    {
        $url = self::_normalizeUrl($url);
        if (!self::_validUrl($url)) {
            return ['ok' => false, 'error' => 'Invalid URL.'];
        }
        if (!self::getOne($dialId, $userId)) {
            return ['ok' => false, 'error' => 'Dial not found.'];
        }

        $title = $title !== '' ? $title : self::_titleFromUrl($url);
        $notes = self::_cleanNotes($notes);

        DB::run(
            'UPDATE dials SET title = ?, url = ?, notes = ? WHERE id = ? AND user_id = ?',
            [$title, $url, $notes ?: null, $dialId, $userId]
        );

        return ['ok' => true];
    }

    // ── Move to group (single) ────────────────────────────────────────────────

    public static function moveToGroup(int $dialId, int $userId, int $newGroupId): array
    {
        $dial = self::getOne($dialId, $userId);
        if (!$dial) {
            return ['ok' => false, 'error' => 'Dial not found.'];
        }

        $group = DB::row(
            'SELECT id FROM groups_list WHERE id = ? AND user_id = ?',
            [$newGroupId, $userId]
        );
        if (!$group) {
            return ['ok' => false, 'error' => 'Group not found.'];
        }

        $oldGroupId = (int)$dial['group_id'];

        if ($oldGroupId === $newGroupId) {
            return ['ok' => true];
        }

        $maxPos = (int)(DB::val(
            'SELECT COALESCE(MAX(position), -1) FROM dials WHERE user_id = ? AND group_id = ?',
            [$userId, $newGroupId]
        ) ?? -1);

        DB::run(
            'UPDATE dials SET group_id = ?, position = ? WHERE id = ? AND user_id = ?',
            [$newGroupId, $maxPos + 1, $dialId, $userId]
        );

        self::normalizePositions($oldGroupId, $userId);

        return ['ok' => true];
    }

    // ── Delete (single) ───────────────────────────────────────────────────────

    public static function delete(int $dialId, int $userId): array
    {
        $dial = self::getOne($dialId, $userId);
        if (!$dial) return ['ok' => false, 'error' => 'Dial not found.'];

        DB::run('DELETE FROM dials WHERE id = ? AND user_id = ?', [$dialId, $userId]);
        self::normalizePositions((int)$dial['group_id'], $userId);

        try {
            Thumbnail::delete($dialId, $userId);
        } catch (Throwable $e) {
            error_log('[Dial::delete] Thumbnail::delete(' . $dialId . ') failed: ' . $e->getMessage());
        }

        return ['ok' => true];
    }

    // ── Reorder ───────────────────────────────────────────────────────────────

    public static function reorder(int $userId, int $groupId, array $ids): array
    {
        $existing = DB::rows(
            'SELECT id FROM dials WHERE user_id = ? AND group_id = ?',
            [$userId, $groupId]
        );
        $existingIds = array_column($existing ?: [], 'id');
        $ids = array_values(array_filter($ids, fn($id) => in_array($id, $existingIds, true)));

        foreach ($ids as $pos => $id) {
            DB::run(
                'UPDATE dials SET position = ? WHERE id = ? AND user_id = ?',
                [$pos, $id, $userId]
            );
        }
        return ['ok' => true];
    }

    // ── Click tracking ────────────────────────────────────────────────────────

    public static function recordClick(int $dialId, int $userId): void
    {
        DB::run(
            'UPDATE dials SET click_count = click_count + 1, last_click = ?
             WHERE id = ? AND user_id = ?',
            [date('Y-m-d H:i:s'), $dialId, $userId]
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function normalizePositions(int $groupId, int $userId): void
    {
        $rows = DB::rows(
            'SELECT id FROM dials WHERE user_id = ? AND group_id = ? ORDER BY position ASC, id ASC',
            [$userId, $groupId]
        );
        foreach (($rows ?: []) as $i => $row) {
            DB::run('UPDATE dials SET position = ? WHERE id = ?', [$i, $row['id']]);
        }
    }

    private static function _hydrate(array $row): array
    {
        $row['id']          = (int)$row['id'];
        $row['user_id']     = (int)$row['user_id'];
        $row['group_id']    = (int)$row['group_id'];
        $row['position']    = (int)$row['position'];
        $row['click_count'] = (int)($row['click_count'] ?? 0);
        $row['pinned']      = (bool)($row['pinned'] ?? false);
        $row['thumb_path']  = $row['thumb_path'] ?? null;
        $row['has_thumb']   = !empty($row['thumb_path']);
        $row['notes']       = $row['notes'] ?? null;
        return $row;
    }

    private static function _normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        return $url;
    }

    private static function _validUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
        return in_array($scheme, ['http', 'https'], true);
    }

    private static function _titleFromUrl(string $url): string
    {
        $parts = parse_url($url);
        $host  = $parts['host'] ?? $url;
        return preg_replace('/^www\./i', '', $host);
    }

    private static function _cleanNotes(string $notes): string
    {
        return mb_substr(trim(strip_tags($notes)), 0, self::MAX_NOTES);
    }
}
