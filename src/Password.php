<?php
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

class Password
{
    private const MIN_LENGTH = 12;
    // BUG-009: bcrypt (password_hash with PASSWORD_BCRYPT) silently ignores
    // any input beyond 72 bytes — a password longer than that would not
    // actually contribute to strength even though the user might assume it
    // does. This is a UX/input-hygiene limit, not a real DoS concern (bcrypt
    // itself is not vulnerable to long-input DoS, since it only ever reads
    // the first 72 bytes).
    private const MAX_LENGTH = 128;

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
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
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
