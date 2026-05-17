<?php
/**
 * LetaDial — Group Model
 * Handles all CRUD operations for dial groups (speed dial categories/tabs).
 * Note: table is named 'groups_list' because 'groups' is a reserved word in SQL.
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

class Group
{
    // Maximum groups allowed per user (enforced here AND in settings table)
    private const DEFAULT_MAX = 50;

    // Allowed hex color pattern
    private const COLOR_RE = '/^#[0-9A-Fa-f]{6}$/';

    // ── Read ──────────────────────────────────────────────────────────────────

    /**
     * Get all groups for a user, ordered by position then name.
     * Returns array of rows: [id, user_id, name, icon, color, position, dial_count, created_at]
     */
    public static function getAll(int $userId): array
    {
        return DB::rows(
            "SELECT g.*,
                    (SELECT COUNT(*) FROM dials d WHERE d.group_id = g.id) AS dial_count
             FROM groups_list g
             WHERE g.user_id = ?
             ORDER BY g.position ASC, g.name ASC",
            [$userId]
        );
    }

    /**
     * Get a single group by ID, verifying it belongs to the given user.
     */
    public static function getOne(int $groupId, int $userId): ?array
    {
        return DB::row(
            "SELECT * FROM groups_list WHERE id = ? AND user_id = ?",
            [$groupId, $userId]
        );
    }

    /**
     * Count groups for a user.
     */
    public static function count(int $userId): int
    {
        return (int)(DB::val("SELECT COUNT(*) FROM groups_list WHERE user_id = ?", [$userId]) ?? 0);
    }

    // ── Write ─────────────────────────────────────────────────────────────────

    /**
     * Create a new group.
     * Returns ['ok' => true, 'id' => int] or ['ok' => false, 'error' => string]
     */
    public static function create(int $userId, string $name): array
    {
        $name = self::sanitizeName($name);
        if (!$name) return ['ok' => false, 'error' => 'Group name cannot be empty.'];
        if (mb_strlen($name) > 100) return ['ok' => false, 'error' => 'Name too long (max 100 chars).'];

        // Enforce per-user limit
        $max = (int)(DB::val("SELECT value FROM settings WHERE key_name = 'max_groups_per_user'") ?? self::DEFAULT_MAX);
        if (self::count($userId) >= $max) {
            return ['ok' => false, 'error' => "Maximum {$max} groups allowed."];
        }

        // Prevent duplicate names for this user
        $exists = DB::val(
            "SELECT id FROM groups_list WHERE user_id = ? AND name = ?",
            [$userId, $name]
        );
        if ($exists) return ['ok' => false, 'error' => 'A group with this name already exists.'];

        // Place at end of current list
        $maxPos = (int)(DB::val(
            "SELECT COALESCE(MAX(position), -1) FROM groups_list WHERE user_id = ?",
            [$userId]
        ) ?? -1);

        DB::run(
            "INSERT INTO groups_list (user_id, name, position) VALUES (?, ?, ?)",
            [$userId, $name, $maxPos + 1]
        );

        return ['ok' => true, 'id' => (int)DB::lastId()];
    }

    /**
     * Rename a group. Validates ownership.
     */
    public static function rename(int $groupId, int $userId, string $newName): array
    {
        $newName = self::sanitizeName($newName);
        if (!$newName) return ['ok' => false, 'error' => 'Name cannot be empty.'];
        if (mb_strlen($newName) > 100) return ['ok' => false, 'error' => 'Name too long (max 100 chars).'];

        if (!self::getOne($groupId, $userId)) {
            return ['ok' => false, 'error' => 'Group not found.'];
        }

        // Check duplicate name (excluding this group)
        $exists = DB::val(
            "SELECT id FROM groups_list WHERE user_id = ? AND name = ? AND id != ?",
            [$userId, $newName, $groupId]
        );
        if ($exists) return ['ok' => false, 'error' => 'Another group already has this name.'];

        DB::run(
            "UPDATE groups_list SET name = ? WHERE id = ? AND user_id = ?",
            [$newName, $groupId, $userId]
        );
        return ['ok' => true];
    }

    /**
     * Set emoji icon and/or hex color for a group.
     *
     * @param string|null $icon  Single emoji character, or null to clear.
     * @param string|null $color Hex color e.g. "#3182CE", or null to clear.
     */
    public static function setStyle(int $groupId, int $userId, ?string $icon, ?string $color): array
    {
        if (!self::getOne($groupId, $userId)) {
            return ['ok' => false, 'error' => 'Group not found.'];
        }

        // Sanitise icon — allow null (clear) or a short string (emoji, 1-4 chars grapheme)
        if ($icon !== null) {
            $icon = mb_substr(strip_tags(trim($icon)), 0, 4);
            if ($icon === '') $icon = null;
        }

        // Sanitise color — allow null (clear) or exactly #RRGGBB
        if ($color !== null) {
            $color = trim($color);
            if (!preg_match(self::COLOR_RE, $color)) {
                return ['ok' => false, 'error' => 'Invalid color format. Use #RRGGBB.'];
            }
            // Normalise to lowercase
            $color = strtolower($color);
        }

        DB::run(
            "UPDATE groups_list SET icon = ?, color = ? WHERE id = ? AND user_id = ?",
            [$icon, $color, $groupId, $userId]
        );

        return ['ok' => true, 'icon' => $icon, 'color' => $color];
    }

    /**
     * Delete a group and all its dials (cascade in DB).
     * Users cannot delete their last group.
     */
    public static function delete(int $groupId, int $userId): array
    {
        if (!self::getOne($groupId, $userId)) {
            return ['ok' => false, 'error' => 'Group not found.'];
        }

        if (self::count($userId) <= 1) {
            return ['ok' => false, 'error' => 'You must have at least one group.'];
        }

        DB::run("DELETE FROM groups_list WHERE id = ? AND user_id = ?", [$groupId, $userId]);

        // Re-normalize positions after deletion
        self::normalizePositions($userId);

        return ['ok' => true];
    }

    /**
     * Reorder groups. Receives an ordered array of group IDs.
     * Validates all IDs belong to the user before applying.
     */
    public static function reorder(int $userId, array $orderedIds): array
    {
        if (empty($orderedIds)) return ['ok' => false, 'error' => 'No IDs provided.'];

        // Validate all IDs belong to user
        $userGroupIds = array_column(DB::rows(
            "SELECT id FROM groups_list WHERE user_id = ?", [$userId]
        ), 'id');

        foreach ($orderedIds as $id) {
            if (!in_array((int)$id, array_map('intval', $userGroupIds))) {
                return ['ok' => false, 'error' => 'Invalid group ID.'];
            }
        }

        // Apply positions
        $stmt = DB::get()->prepare(
            "UPDATE groups_list SET position = ? WHERE id = ? AND user_id = ?"
        );
        foreach ($orderedIds as $pos => $id) {
            $stmt->execute([$pos, (int)$id, $userId]);
        }
        return ['ok' => true];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function sanitizeName(string $name): string
    {
        return trim(strip_tags($name));
    }

    private static function normalizePositions(int $userId): void
    {
        $groups = DB::rows(
            "SELECT id FROM groups_list WHERE user_id = ? ORDER BY position ASC, id ASC",
            [$userId]
        );
        $stmt = DB::get()->prepare(
            "UPDATE groups_list SET position = ? WHERE id = ?"
        );
        foreach ($groups as $i => $g) {
            $stmt->execute([$i, $g['id']]);
        }
    }
}
