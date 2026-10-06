<?php
/**
 * LetaDial — Export
 * Sesja 054: notes field included in export
 * Sesja 080: personal data export for GDPR (buildPersonalData() / downloadPersonalData() below)
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

    // ── Personal data export (sesja 080 - GDPR Art. 15 and Art. 20) ───────────

    /**
     * Layout version of the personal data export file. Independent of the
     * "version" of the dials export produced by build() above.
     */
    public const PERSONAL_DATA_FORMAT_VERSION = '1.0';

    /**
     * Upper bound for the two lists that nothing in the application ever
     * purges and that can therefore grow without limit: sessions and
     * login_history. Keeps a single export request bounded in memory. A list
     * that reaches the bound is flagged with "truncated": true and carries
     * the real "total_records", so the file never silently pretends to be
     * complete.
     */
    private const MAX_LIST_ROWS = 10000;

    /** Defensive ceiling for one image embedded as a data URI (real files are a few KB). */
    private const MAX_EMBED_BYTES = 524288;

    /**
     * Build a copy of the personal data LetaDial stores for one user:
     * profile, preferences, groups, dials, avatar, custom group icons, 2FA
     * status, sessions, trusted devices, remember-me token dates and login
     * history.
     *
     * Deliberately NEVER included: password hash, TOTP secret, backup code
     * hashes, and every token or token hash (session id, remember-me and
     * trusted-device selector/verifier, password reset, activation and e-mail
     * change tokens). Every query below names its columns explicitly and
     * never uses SELECT *, so a credential column added to a table later
     * cannot leak into the export by accident.
     *
     * The top level also carries "app", "groups" and "dials" in the shape
     * Import::fromJson() already understands, so groups and dials from this
     * file can be brought back with the normal Import.
     *
     * @param int         $userId           Authenticated user's own ID, never request data.
     * @param string|null $currentSessionId Session ID of the calling session. Only used to set
     *                                      "is_current_session"; it is never exported.
     * @throws RuntimeException if the user does not exist
     */
    public static function buildPersonalData(int $userId, ?string $currentSessionId = null): array
    {
        $u = DB::row(
            "SELECT id, login, email, email_pending, role, email_verified,
                    totp_enabled, totp_required, recent_disabled, theme, dial_width,
                    theme_light_primary, theme_dark_primary, theme_midnight_primary,
                    theme_light_extra, theme_dark_extra, theme_midnight_extra,
                    created_at, last_login
             FROM users
             WHERE id = ?",
            [$userId]
        );
        if (!$u) {
            throw new RuntimeException('User not found.');
        }

        // Groups, with the custom icon image embedded when one exists.
        $groups = array_map(fn($g) => [
            'id'          => (int)$g['id'],
            'name'        => $g['name'],
            'position'    => (int)$g['position'],
            'icon'        => $g['icon'],
            'color'       => $g['color'],
            'custom_icon' => self::embedImage(GroupIcon::filePath((int)$g['id'], $userId)),
            'created_at'  => $g['created_at'],
        ], DB::rows(
            "SELECT id, name, position, icon, color, created_at
             FROM groups_list
             WHERE user_id = ?
             ORDER BY position ASC, name ASC, id ASC",
            [$userId]
        ));

        // Dials. "group" (the group name) is what Import::fromJson() reads.
        $dials = array_map(fn($d) => [
            'id'                   => (int)$d['id'],
            'group_id'             => (int)$d['group_id'],
            'group'                => $d['group_name'],
            'title'                => $d['title'],
            'url'                  => $d['url'],
            'notes'                => $d['notes'],
            'position'             => (int)$d['position'],
            'pinned'               => (bool)$d['pinned'],
            'click_count'          => (int)$d['click_count'],
            'last_click'           => $d['last_click'],
            'created_at'           => $d['created_at'],
            'thumbnail_updated_at' => $d['thumb_updated_at'],
        ], DB::rows(
            "SELECT d.id, d.group_id, g.name AS group_name, d.title, d.url, d.notes,
                    d.position, d.pinned, d.click_count, d.last_click, d.created_at,
                    d.thumb_updated_at
             FROM dials d
             JOIN groups_list g ON g.id = d.group_id
             WHERE d.user_id = ?
             ORDER BY g.position ASC, g.id ASC, d.position ASC, d.id ASC",
            [$userId]
        ));

        // Sessions. sessions.id is selected ONLY to compute is_current_session
        // and pending_totp (an encrypted 2FA setup secret) is never selected.
        $sessionTotal = (int)(DB::val("SELECT COUNT(*) FROM sessions WHERE user_id = ?", [$userId]) ?? 0);
        $sessions = array_map(fn($s) => [
            'ip'                  => $s['ip'],
            'user_agent'          => $s['user_agent'],
            'created_at'          => $s['created_at'],
            'last_activity'       => $s['last_activity'],
            'expires_at'          => $s['expires_at'],
            'two_factor_verified' => (bool)$s['totp_verified'],
            'is_current_session'  => $currentSessionId !== null
                                     && hash_equals($currentSessionId, (string)$s['id']),
        ], DB::rows(
            "SELECT id, ip, user_agent, created_at, last_activity, expires_at, totp_verified
             FROM sessions
             WHERE user_id = ?
             ORDER BY last_activity DESC, created_at DESC
             LIMIT " . self::MAX_LIST_ROWS,
            [$userId]
        ));

        // Trusted devices and remember-me tokens: dates and labels only, never selector/verifier.
        $trustedDevices = DB::rows(
            "SELECT label, ip, created_at, last_used_at, expires_at
             FROM trusted_devices
             WHERE user_id = ?
             ORDER BY last_used_at DESC, id DESC",
            [$userId]
        );
        $rememberTokens = DB::rows(
            "SELECT created_at, expires_at
             FROM remember_tokens
             WHERE user_id = ?
             ORDER BY created_at DESC, id DESC",
            [$userId]
        );

        // Backup codes: counts only, never the code hashes.
        $bc = DB::row(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN used = 0 THEN 1 ELSE 0 END), 0) AS unused
             FROM totp_backup_codes
             WHERE user_id = ?",
            [$userId]
        );
        $bcTotal  = (int)($bc['total']  ?? 0);
        $bcUnused = (int)($bc['unused'] ?? 0);

        // Login history of this account (failed attempts included).
        $loginTotal   = (int)(DB::val("SELECT COUNT(*) FROM login_history WHERE user_id = ?", [$userId]) ?? 0);
        $loginHistory = DB::rows(
            "SELECT created_at, status, ip, user_agent, login_attempt
             FROM login_history
             WHERE user_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT " . self::MAX_LIST_ROWS,
            [$userId]
        );

        return [
            'format'          => 'letadial-personal-data',
            'format_version'  => self::PERSONAL_DATA_FORMAT_VERSION,
            'app'             => 'LetaDial',
            'exported_at'     => gmdate('Y-m-d\TH:i:s\Z'),
            'about'           => 'Copy of the personal data this LetaDial instance stores about your account, '
                               . 'exported at your request (GDPR Art. 15 and Art. 20). '
                               . 'Passwords and secrets are never included.',
            'timestamps_note' => 'Timestamps are exported exactly as stored by the server, which normally records UTC.',
            'summary'         => [
                'groups'          => count($groups),
                'dials'           => count($dials),
                'sessions'        => $sessionTotal,
                'trusted_devices' => count($trustedDevices),
                'login_history'   => $loginTotal,
            ],
            'account'         => [
                'user_id'        => (int)$u['id'],
                'login'          => $u['login'],
                'email'          => $u['email'],
                'pending_email'  => $u['email_pending'],
                'role'           => $u['role'],
                'email_verified' => (bool)$u['email_verified'],
                'created_at'     => $u['created_at'],
                'last_login'     => $u['last_login'],
                'avatar'         => self::embedImage(Avatar::filePath($userId)),
            ],
            'preferences'     => [
                'theme'              => $u['theme'],
                'recent_tab_hidden'  => (bool)$u['recent_disabled'],
                'dial_card_width_px' => (int)$u['dial_width'],
                'custom_colors'      => [
                    'light'    => self::themeColors($u['theme_light_primary'],    $u['theme_light_extra']),
                    'dark'     => self::themeColors($u['theme_dark_primary'],     $u['theme_dark_extra']),
                    'midnight' => self::themeColors($u['theme_midnight_primary'], $u['theme_midnight_extra']),
                ],
            ],
            'groups'          => $groups,
            'dials'           => $dials,
            'security'        => [
                'two_factor'         => [
                    'enabled'      => (bool)$u['totp_enabled'],
                    'required'     => (bool)$u['totp_required'],
                    'backup_codes' => [
                        'total'  => $bcTotal,
                        'unused' => $bcUnused,
                        'used'   => $bcTotal - $bcUnused,
                    ],
                ],
                'sessions'           => self::listBlock($sessions, $sessionTotal),
                'trusted_devices'    => $trustedDevices,
                'remember_me_tokens' => $rememberTokens,
                'login_history'      => self::listBlock($loginHistory, $loginTotal),
            ],
            'not_included'    => [
                'Password hash, two-factor secret and backup code hashes: credentials are never exported.',
                'Session, remember-me and trusted-device tokens, and password reset, activation and e-mail change tokens.',
                'Dial thumbnail images: generated previews, not embedded in this file.',
                'Browser-only settings (sort order per group, last opened group): stored in your browser and never sent to the server.',
                'Temporary rate-limit counters (short-lived security bookkeeping).',
                'Web server access logs and CSP violation reports: not linked to an account.',
            ],
        ];
    }

    /**
     * Send the personal data export as a JSON file download. Everything that
     * can fail (queries, file reads, JSON encoding) happens BEFORE the first
     * header() call, so a failure leaves the response untouched and the
     * caller can still answer with a clean JSON error.
     *
     * Content-Length is left out on purpose: output compression or a proxy
     * could make a hand-set value disagree with the bytes actually sent.
     */
    public static function downloadPersonalData(int $userId, ?string $currentSessionId = null): void
    {
        $json = json_encode(
            self::buildPersonalData($userId, $currentSessionId),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
        $filename = 'letadial_personal_data_' . gmdate('Y-m-d') . '.json';

        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
        echo $json;
    }

    // ── Personal data export helpers ──────────────────────────────────────────

    /** Wrap a (possibly capped) list so a truncated list is always visible as such. */
    private static function listBlock(array $entries, int $total): array
    {
        return [
            'total_records'    => $total,
            'included_records' => count($entries),
            'truncated'        => $total > count($entries),
            'entries'          => $entries,
        ];
    }

    /** Accent color from users.theme_*_primary plus background/text from the users.theme_*_extra JSON. */
    private static function themeColors(?string $accent, ?string $extraJson): array
    {
        $extra = ($extraJson !== null && $extraJson !== '') ? json_decode($extraJson, true) : null;
        if (!is_array($extra)) {
            $extra = [];
        }
        return [
            'accent'     => self::hexOrNull($accent),
            'background' => self::hexOrNull($extra['bg']   ?? null),
            'text'       => self::hexOrNull($extra['text'] ?? null),
        ];
    }

    private static function hexOrNull(mixed $value): ?string
    {
        return (is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value)) ? strtolower($value) : null;
    }

    /**
     * Read an image the application itself wrote under storage/ and return it
     * as a data URI, or null if it is missing or implausibly large. Callers
     * build $path from integer IDs only, never from request data.
     */
    private static function embedImage(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $size = @filesize($path);
        if ($size === false || $size < 1 || $size > self::MAX_EMBED_BYTES) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        return 'data:image/webp;base64,' . base64_encode($raw);
    }
}
