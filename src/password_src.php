<?php
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

class Password
{
    private const MIN_LENGTH = 12;
    // BUG-009 / BUG-015: bcrypt (password_hash with PASSWORD_BCRYPT) silently
    // ignores any input beyond 72 bytes — a password longer than that would
    // not actually contribute to strength even though the user might assume
    // it does. This is a UX/input-hygiene limit, not a real DoS concern
    // (bcrypt itself is not vulnerable to long-input DoS, since it only ever
    // reads the first 72 bytes).
    //
    // BUG-015: MAX_LENGTH was first set to 128 when this limit was added
    // (BUG-009) — still above bcrypt's own 72-byte window, so two passwords
    // that agree on the first 72 bytes and differ only after that would
    // hash identically and both "work". Lowered to 72 so everything a user
    // actually types is guaranteed to reach the hash, with no silent
    // truncation left for bcrypt to perform on our behalf.
    private const MAX_LENGTH = 72;

    // BUG-010 (03.08.2026): bcrypt work factor. Raised 12 -> 15 (Andrzej's
    // decision — deliberately higher than the 13/14 suggested, prioritizing
    // security margin over shaving off roughly one extra second per login
    // on this low-traffic personal install). Each +1 doubles hashing time;
    // cost 15 costs roughly 2s per hash on typical 2026 server hardware —
    // paid once per login/password-change, not on every request. See
    // verifyAndRehash() below for how an existing lower-cost hash silently
    // upgrades to this value the next time its owner logs in, with no
    // forced password reset.
    private const BCRYPT_COST = 15;

    /**
     * Validate password strength.
     * Returns array of error strings (empty = OK).
     */
    public static function validate(string $password): array
    {
        $errors = [];

        if (strlen($password) < self::MIN_LENGTH) {
            $errors[] = 'Password must be at least ' . self::MIN_LENGTH . ' characters.';
        }
        if (strlen($password) > self::MAX_LENGTH) {
            $errors[] = 'Password must be at most ' . self::MAX_LENGTH . ' characters.';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter.';
        }
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter.';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number.';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'Password must contain at least one special character.';
        }

        return $errors;
    }

    public static function isValid(string $password): bool
    {
        return empty(self::validate($password));
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Verify a password and, if it matches but the stored hash was computed
     * with a different bcrypt cost than today's BCRYPT_COST, transparently
     * re-hash it and persist the new hash.
     *
     * BUG-010: without this, raising BCRYPT_COST in the future only
     * affects brand-new hashes — every hash already stored in the DB
     * would stay on its old, weaker cost forever, since nothing else
     * would ever recompute it. The only moment the server legitimately
     * holds the plaintext password at all is right here, during a
     * successful password check, so this is the only place a silent
     * upgrade can ever happen.
     *
     * Login itself is never put at risk by this: the boolean returned
     * below only ever reflects whether $password matched $hash, exactly
     * like verify(). If the opportunistic rehash write fails (e.g. a
     * transient DB hiccup), that failure is logged and swallowed — it
     * never turns a correct password into a rejected login. The hash
     * simply stays on its old cost and gets another chance to upgrade
     * on this user's next login.
     *
     * Deliberately NOT used by the password-change flow
     * (api/settings_api.php) — there, the freshly-typed new password gets
     * hashed and stored immediately afterwards regardless, so rehashing
     * the OLD hash first would just be a wasted write.
     */
    public static function verifyAndRehash(string $password, string $hash, int $userId): bool
    {
        if (!self::verify($password, $hash)) {
            return false;
        }

        if (password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST])) {
            try {
                DB::run(
                    "UPDATE users SET password_hash = ? WHERE id = ?",
                    [self::hash($password), $userId]
                );
            } catch (Throwable $e) {
                error_log('[Password] opportunistic rehash failed for user ' . $userId . ': ' . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * Returns a JS-friendly strength level string for frontend meter.
     * Used in login/settings pages.
     */
    public static function jsRules(): string
    {
        return json_encode([
            'minLength'   => self::MIN_LENGTH,
            'maxLength'   => self::MAX_LENGTH,
            'uppercase'   => true,
            'lowercase'   => true,
            'number'      => true,
            'special'     => true,
        ]);
    }
}
