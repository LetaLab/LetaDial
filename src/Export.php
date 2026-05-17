<?php
/**
 * LetaDial — Export
 * Sesja 054: notes field included in export
 *
 * Format:
 * {
 *   "version": "1.1",
 *   "app": "LetaDial",
 *   "exported_at": "...",
 *   "groups": [{"name": "...", "position": 0}],
 *   "dials": [{"title":"...","url":"...","notes":"...","group":"...","position":0}]
 * }
 */
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

class Export
{
    public static function build(int $userId): array
    {
        $groups = DB::rows(
            "SELECT name, position FROM groups_list
             WHERE user_id = ?
             ORDER BY position ASC, name ASC",
            [$userId]
        );

        $dials = DB::rows(
            "SELECT d.title, d.url, d.notes, g.name AS group_name, d.position
             FROM dials d
             JOIN groups_list g ON g.id = d.group_id
             WHERE d.user_id = ?
             ORDER BY g.position ASC, d.position ASC",
            [$userId]
        );

        $exportGroups = array_map(fn($g) => [
            'name'     => $g['name'],
            'position' => (int)$g['position'],
        ], $groups);

        $exportDials = array_map(fn($d) => [
            'title'    => $d['title'],
            'url'      => $d['url'],
            'notes'    => $d['notes'] ?? null,
            'group'    => $d['group_name'],
            'position' => (int)$d['position'],
        ], $dials);

        return [
            'version'     => '1.1',
            'app'         => 'LetaDial',
            'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'groups'      => $exportGroups,
            'dials'       => $exportDials,
        ];
    }

    public static function download(int $userId): void
    {
        $data     = self::build($userId);
        $json     = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $filename = 'letadial_export_' . date('Y-m-d') . '.json';

        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        echo $json;
    }
}
