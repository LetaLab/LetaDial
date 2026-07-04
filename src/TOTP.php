<?php
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

class TOTP
{
    private const DIGITS  = 6;
    private const STEP    = 30;  // seconds per time step
    private const WINDOW  = 2;   // ±2 step tolerance (covers up to 60s clock skew between server and phone)
    private const SECRET_BYTES = 20;

    // ── Public API ─────────────────────────────────────────────────────────────

    /** Generate a new random Base32 secret */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(self::SECRET_BYTES));
    }

    /**
     * Verify a 6-digit code against a secret, within the time window.
     *
     * SEC-080: this checks cryptographic correctness ONLY — it does NOT
     * protect against replay (the same valid code accepted twice within
     * its ~150s validity window). Any call site that gates authentication
     * or a sensitive action MUST use verifyAndConsume() instead. This
     * method stays public/stateless for any future non-authenticating use
     * (e.g. a "does this code look right" preview with no user context).
     */
    public static function verify(string $secret, string $code): bool
    {
        return self::matchStep($secret, $code) !== null;
    }

    /**
     * Verify a code AND atomically mark its time-step as consumed for this
     * user, so the exact same code can never be accepted a second time.
     * (SEC-080 — closes the replay gap flagged in the 2026-07 audit.)
     *
     * Threat this closes: a code is shoulder-surfed / MITM'd / read off a
     * clipboard or screenshot. Without this, the captured code stays valid
     * for its whole window and can authenticate a second, attacker session
     * before or after the legitimate one. RFC 6238 §5.2 recommends
     * tracking the last successfully used step per credential — that's
     * exactly what this does, via `users.totp_last_step`.
     *
     * Why this can't break normal logins: server time only moves forward,
     * so every later, legitimate authentication computes a step strictly
     * greater than anything recorded in the past. The ONLY thing this can
     * ever reject is re-submitting the SAME (or an earlier) step — which
     * is either an actual replay or a harmless duplicate form submit, and
     * failing closed on those is correct behaviour, not a regression.
     *
     * Why it's atomic: the UPDATE's own WHERE clause IS the check — there
     * is no separate SELECT-then-decide step, so there's no race window
     * (unlike RateLimit::check()'s purge, tracked separately). Two
     * concurrent requests racing the same step: exactly one UPDATE affects
     * a row; the other affects zero and returns false.
     *
     * Shared across every call site on purpose (login 2FA, initial 2FA
     * setup, backup-code-regeneration re-auth) — a consumed step is
     * consumed everywhere, matching RFC 6238's own recommendation, which
     * does not distinguish by "purpose" of the check.
     */
    public static function verifyAndConsume(string $secret, string $code, int $userId): bool
    {
        $matchedStep = self::matchStep($secret, $code);
        if ($matchedStep === null) return false;

        $affected = DB::run(
            "UPDATE users SET totp_last_step = ?
             WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)",
            [$matchedStep, $userId, $matchedStep]
        );

        return $affected > 0;
    }

    /**
     * Shared matching primitive — identical cryptographic check verify()
     * always did, just returns WHICH step matched (or null) instead of a
     * plain bool, so callers can compare it against the replay guard.
     */
    private static function matchStep(string $secret, string $code): ?int
    {
        $code = preg_replace('/\s/', '', $code);
        if (!preg_match('/^\d{6}$/', $code)) return null;

        $key  = self::base32Decode($secret);
        $step = (int)floor(time() / self::STEP);

        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            $candidate = $step + $offset;
            if (hash_equals(self::compute($key, $candidate), $code)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Build the otpauth:// URI for QR codes and "Open in Authenticator" links.
     * Format: otpauth://totp/{issuer}:{account}?secret={secret}&issuer={issuer}&digits=6&period=30
     */
    public static function uri(string $secret, string $account, string $issuer = APP_NAME): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        $params = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'digits' => self::DIGITS,
            'period' => self::STEP,
        ]);
        return 'otpauth://totp/' . $label . '?' . $params;
    }

    /**
     * Format secret in groups of 4 for human readability.
     * e.g. "JBSW Y3DP EHPK 3PXP ..."
     */
    public static function formatSecret(string $secret): string
    {
        return implode(' ', str_split(strtoupper($secret), 4));
    }

    // ── Encryption (AES-256-GCM) for storing secrets in DB ────────────────────

    /** Encrypt a TOTP secret before storing in DB */
    public static function encrypt(string $plaintext): string
    {
        $key   = hex2bin(ENCRYPTION_KEY);
        $iv    = random_bytes(12); // 96-bit IV for GCM
        $tag   = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key,
                                       OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ciphertext === false) throw new RuntimeException('TOTP encryption failed');
        // Store as: base64(iv + tag + ciphertext)
        return base64_encode($iv . $tag . $ciphertext);
    }

    /** Decrypt a TOTP secret from DB */
    public static function decrypt(string $stored): string
    {
        $raw        = base64_decode($stored, true);
        if ($raw === false || strlen($raw) < 29) throw new RuntimeException('Invalid TOTP ciphertext');
        $iv         = substr($raw, 0, 12);
        $tag        = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $key        = hex2bin(ENCRYPTION_KEY);
        $plain      = openssl_decrypt($ciphertext, 'aes-256-gcm', $key,
                                       OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) throw new RuntimeException('TOTP decryption failed');
        return $plain;
    }

    // ── Backup Codes ───────────────────────────────────────────────────────────

    /** Generate 10 backup codes, return [plaintext[], hashed[]] */
    public static function generateBackupCodes(): array
    {
        $plain  = [];
        $hashed = [];
        for ($i = 0; $i < 10; $i++) {
            // Format: XXXX-XXXX (8 hex chars with dash = easy to type)
            $raw     = strtoupper(bin2hex(random_bytes(4)));
            $code    = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
            $plain[] = $code;
            $hashed[] = password_hash($code, PASSWORD_BCRYPT, ['cost' => 10]);
        }
        return [$plain, $hashed];
    }

    /** Verify and consume a backup code for a user */
    public static function useBackupCode(int $userId, string $code): bool
    {
        $code = strtoupper(preg_replace('/\s/', '', $code));
        $rows = DB::rows(
            "SELECT id, code_hash FROM totp_backup_codes
             WHERE user_id = ? AND used = 0",
            [$userId]
        );
        foreach ($rows as $row) {
            if (password_verify($code, $row['code_hash'])) {
                DB::run(
                    "UPDATE totp_backup_codes SET used = 1, used_at = NOW() WHERE id = ?",
                    [$row['id']]
                );
                return true;
            }
        }
        return false;
    }

    // ── Private ────────────────────────────────────────────────────────────────

    private static function compute(string $key, int $step): string
    {
        $msg  = pack('J', $step);               // 8-byte big-endian
        $hash = hash_hmac('sha1', $msg, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $code = (
            ((ord($hash[$offset])   & 0x7F) << 24) |
            ((ord($hash[$offset+1]) & 0xFF) << 16) |
            ((ord($hash[$offset+2]) & 0xFF) <<  8) |
             (ord($hash[$offset+3]) & 0xFF)
        ) % (10 ** self::DIGITS);
        return str_pad((string)$code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $output   = '';
        $buffer   = 0;
        $bitsLeft = 0;
        foreach (str_split($data) as $char) {
            $buffer  = ($buffer << 8) | ord($char);
            $bitsLeft += 8;
            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $output .= $alphabet[($buffer >> $bitsLeft) & 31];
            }
        }
        if ($bitsLeft > 0) {
            $output .= $alphabet[($buffer << (5 - $bitsLeft)) & 31];
        }
        return $output;
    }

    private static function base32Decode(string $data): string
    {
        $data     = strtoupper(preg_replace('/[^A-Z2-7]/', '', $data));
        $map      = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
        $output   = '';
        $buffer   = 0;
        $bitsLeft = 0;
        foreach (str_split($data) as $char) {
            $buffer   = ($buffer << 5) | ($map[$char] ?? 0);
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }
        return $output;
    }
}
