<?php
declare(strict_types=1);
defined('DIALVAULT_APP') or die('Direct access forbidden.');

class Mailer
{
    /**
     * Send an email via raw SMTP socket.
     * Uses base64 body encoding — avoids the quoted-printable '=3D' URL bug.
     * Supports: STARTTLS (port 587), SSL (port 465), plain (port 25).
     */
    public static function send(
        string $to,
        string $subject,
        string $body_text,
        string $body_html = ''
    ): bool {
        if (!defined('SMTP_ENABLED') || !SMTP_ENABLED) return false;

        try {
            return self::smtp($to, $subject, $body_text, $body_html);
        } catch (Throwable $e) {
            error_log('[Mailer] ' . $e->getMessage());
            return false;
        }
    }

    // ── Convenience senders ───────────────────────────────────────────────────

    public static function sendActivation(string $to, string $token): bool
    {
        $link = APP_URL . '/activate?token=' . rawurlencode($token);

        $text = "Hello,\n\n"
              . "Your " . APP_NAME . " installation is complete.\n\n"
              . "Activate your admin account by visiting:\n"
              . $link . "\n\n"
              . "This link expires in 24 hours.\n\n"
              . "— " . APP_NAME;

        $html = self::wrapHtml('Activate your account',
            '<p>Your <strong>' . htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8')
            . '</strong> installation is complete.</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8')
            . '" class="btn">Activate Account</a></p>'
            . '<p class="muted">This link expires in 24 hours.</p>');

        return self::send($to, 'Activate your ' . APP_NAME . ' account', $text, $html);
    }

    public static function sendPasswordReset(string $to, string $token): bool
    {
        $link = APP_URL . '/reset-password?token=' . rawurlencode($token);

        $text = "Hello,\n\n"
              . "A password reset was requested for your " . APP_NAME . " account.\n\n"
              . "Reset your password:\n"
              . $link . "\n\n"
              . "This link expires in 1 hour. If you did not request this, ignore this email.\n\n"
              . "— " . APP_NAME;

        $html = self::wrapHtml('Reset your password',
            '<p>A password reset was requested for your account.</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8')
            . '" class="btn">Reset Password</a></p>'
            . '<p class="muted">Expires in 1 hour. Ignore if you did not request this.</p>');

        return self::send($to, 'Password reset — ' . APP_NAME, $text, $html);
    }

    public static function sendUserInvite(string $to, string $token, string $invited_by): bool
    {
        $link = APP_URL . '/activate?token=' . rawurlencode($token);

        $text = "Hello,\n\n"
              . $invited_by . " has invited you to " . APP_NAME . ".\n\n"
              . "Create your account:\n"
              . $link . "\n\n"
              . "This link expires in 48 hours.\n\n"
              . "— " . APP_NAME;

        $html = self::wrapHtml('You\'ve been invited',
            '<p><strong>' . htmlspecialchars($invited_by, ENT_QUOTES, 'UTF-8')
            . '</strong> has invited you to join <strong>'
            . htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8')
            . '" class="btn">Create Account</a></p>'
            . '<p class="muted">This link expires in 48 hours.</p>');

        return self::send($to, 'You\'ve been invited to ' . APP_NAME, $text, $html);
    }

    /**
     * Send invite to the NEW setup-account page (sesja 067).
     */
    public static function sendInviteToSetup(string $to, string $token, string $invitedBy): bool
    {
        $link = APP_URL . '/setup-account?token=' . rawurlencode($token);
        $app  = APP_NAME;

        $text = "Hello,\n\n"
              . "{$invitedBy} has invited you to join {$app}.\n\n"
              . "Click the link below to set your password and activate your account:\n"
              . $link . "\n\n"
              . "This invitation link expires in 24 hours.\n\n"
              . "If you did not expect this invitation, you can safely ignore this email.\n\n"
              . "— {$app}";

        $html = self::wrapHtml("You've been invited to {$app}",
            '<p><strong>' . htmlspecialchars($invitedBy, ENT_QUOTES, 'UTF-8')
            . '</strong> has invited you to join <strong>'
            . htmlspecialchars($app, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
            . '<p>Click the button below to set your password and activate your account:</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8')
            . '" class="btn">Set Up My Account</a></p>'
            . '<p class="muted">This link expires in 24 hours.</p>'
            . '<p class="muted">If you did not expect this invitation, ignore this email.</p>');

        return self::send($to, "You've been invited to {$app}", $text, $html);
    }

    /**
     * Send email address change confirmation to the NEW email address.
     * sesja 066
     */
    public static function sendEmailChange(string $to, string $token): bool
    {
        $link = APP_URL . '/confirm-email?token=' . rawurlencode($token);
        $app  = APP_NAME;

        $text = "Hello,\n\n"
              . "A request was made to change the email address on your {$app} account "
              . "to this address.\n\n"
              . "Click the link below to confirm and apply the change:\n"
              . $link . "\n\n"
              . "This link expires in 1 hour.\n\n"
              . "If you did not request this change, you can safely ignore this email.\n\n"
              . "— {$app}";

        $html = self::wrapHtml('Confirm your new email address',
            '<p>A request was made to change the email address on your <strong>'
            . htmlspecialchars($app, ENT_QUOTES, 'UTF-8') . '</strong> account to this address.</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8')
            . '" class="btn">Confirm Email Change</a></p>'
            . '<p class="muted">This link expires in 1 hour.</p>'
            . '<p class="muted">If you did not request this, ignore this email.</p>');

        return self::send($to, 'Confirm your new email address — ' . $app, $text, $html);
    }

    // ── SMTP core ─────────────────────────────────────────────────────────────

    private static function smtp(
        string $to,
        string $subject,
        string $text,
        string $html
    ): bool {
        // SEC-099: buildMessage() already calls sanitizeHeader() on $to,
        // but that runs on its own local copy (PHP passes strings by
        // value) — it never touched the $to used right here to build the
        // literal "RCPT TO:<{$to}>" command below. Every current caller of
        // Mailer::send*() already validates addresses with
        // FILTER_VALIDATE_EMAIL upstream (which can't contain CR/LF), so
        // this was not exploitable today — this makes smtp() safe on its
        // own, independent of what any future caller passes in.
        $to     = self::sanitizeHeader($to);
        $port   = (int)SMTP_PORT;
        $host   = SMTP_HOST;
        $domain = explode('@', SMTP_FROM)[1] ?? 'localhost';

        // SEC-140 (11.09.2026): explicit SSL verification context, matching
        // the same defensive pattern already used everywhere else in this
        // project that opens a TLS connection (thumbnail_src.php's
        // safeFetchBody()/fetchFavicon(), meta_src.php's download(),
        // updater_src.php's GitHub API calls all set 'verify_peer' =>
        // true, 'verify_peer_name' => true, 'peer_name' => $host
        // explicitly). This was the one outbound connection in the app
        // that instead relied implicitly on PHP's global default stream
        // context — safe under PHP's own default (verify_peer=true since
        // 5.6), but silently overridable by a php.ini change on any future
        // host, with nothing in this application's own code to catch that.
        // fsockopen() has no context parameter at all, so the immediate-TLS
        // branch (port 465) is switched to stream_socket_client(), which
        // does; behaviour for the plain tcp:// case (port 25) is unchanged.
        $ctxOpts = [];
        if ($port === 465) {
            $ctxOpts['ssl'] = [
                'verify_peer'      => true,
                'verify_peer_name' => true,
                'peer_name'        => $host,
            ];
        }
        $ctx    = stream_context_create($ctxOpts);
        $scheme = ($port === 465) ? 'ssl://' : 'tcp://';
        $sock   = @stream_socket_client($scheme . $host . ':' . $port, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) throw new RuntimeException("SMTP connect failed: {$errstr} ({$errno})");

        stream_set_timeout($sock, 15);

        if (!self::expect($sock, 220)) {
            fclose($sock); throw new RuntimeException('SMTP: expected 220 greeting');
        }

        self::cmd($sock, "EHLO {$domain}");
        self::drainEhlo($sock);

        // SEC-145 (SEC_AND_BUG_ANIH_PLAN.md, Czesc XVI): STARTTLS now also
        // attempted for port 2525, not just 587. install.php::process_email()
        // explicitly allows exactly four ports - [25, 465, 587, 2525] - but
        // this method previously only ever attempted STARTTLS for 587,
        // leaving 2525 connecting as plain, unencrypted tcp:// with no
        // upgrade attempt at all. Port 2525 is a widely documented "use like
        // 587" fallback offered by major transactional-mail providers
        // (Mailgun, SendGrid, Postmark, among others) specifically for
        // STARTTLS submission when 587 is blocked by an ISP/firewall -
        // configuring it here previously meant AUTH LOGIN credentials
        // (base64-encoded, not encrypted) and the message body travelled the
        // network in plaintext. Most modern SMTP servers simply refuse
        // AUTH without STARTTLS first (so the most likely prior symptom was
        // failed delivery, not a confirmed leak) but nothing in this code
        // enforced that - it depended entirely on the remote server's own
        // policy. Port 25 intentionally remains untouched: it is commonly
        // used for a local/same-host or same-network relay where TLS is not
        // expected, and install.php does not document any assumption either
        // way for it.
        if (in_array($port, [587, 2525], true)) {
            fwrite($sock, "STARTTLS\r\n");
            if (!self::expect($sock, 220)) {
                fclose($sock); throw new RuntimeException('SMTP: STARTTLS failed');
            }
            // SEC-140: same explicit verification options as the port-465
            // branch above, set on this stream's context immediately
            // before the STARTTLS upgrade — stream_socket_enable_crypto()
            // reads SSL options from the stream's own context, which for a
            // plain tcp:// connection was never given one until now.
            stream_context_set_option($sock, 'ssl', 'verify_peer', true);
            stream_context_set_option($sock, 'ssl', 'verify_peer_name', true);
            stream_context_set_option($sock, 'ssl', 'peer_name', $host);
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($sock); throw new RuntimeException('SMTP: TLS negotiation failed');
            }
            self::cmd($sock, "EHLO {$domain}");
            self::drainEhlo($sock);
        }

        if (SMTP_USER !== '') {
            fwrite($sock, "AUTH LOGIN\r\n");
            self::read($sock);
            fwrite($sock, base64_encode(SMTP_USER) . "\r\n");
            self::read($sock);
            fwrite($sock, base64_encode(SMTP_PASS) . "\r\n");
            $auth = self::read($sock);
            if ((int)substr($auth, 0, 3) !== 235) {
                fclose($sock); throw new RuntimeException('SMTP: AUTH failed');
            }
        }

        self::cmd($sock, "MAIL FROM:<" . SMTP_FROM . ">");
        self::cmd($sock, "RCPT TO:<{$to}>");
        self::cmd($sock, "DATA");
        self::expect($sock, 354);

        $msg = self::buildMessage($to, $subject, $text, $html, $domain);
        fwrite($sock, $msg . "\r\n.\r\n");

        $response = self::read($sock);
        $code     = (int)substr($response, 0, 3);
        self::cmd($sock, 'QUIT');
        fclose($sock);

        return $code === 250;
    }

    private static function sanitizeHeader(string $value): string
    {
        return str_replace(["\r", "\n", "\0"], '', $value);
    }

    private static function buildMessage(
        string $to, string $subject, string $text, string $html, string $domain
    ): string {
        $to = self::sanitizeHeader($to);
        // SEC-125: defense in depth. Every current caller only ever builds
        // $subject from a static string literal or from APP_NAME (an
        // admin-set constant) - never from request/user-controlled data -
        // so this is not exploitable today. $to already gets this exact
        // treatment for the identical reason (SEC-099); leaving $subject
        // unsanitized was a latent SMTP header-injection trap for any
        // future caller that ever interpolates user-controlled text into
        // it without going through Mailer's own sanitization first.
        $subject = self::sanitizeHeader($subject);
        $date   = date('r');
        $msg_id = '<' . bin2hex(random_bytes(8)) . '@' . $domain . '>';

        $name    = SMTP_NAME;
        $from    = SMTP_FROM;
        $encoded_name = mb_detect_encoding($name, 'ASCII', true)
            ? $name
            : '=?UTF-8?B?' . base64_encode($name) . '?=';

        $encoded_subj = mb_detect_encoding($subject, 'ASCII', true)
            ? $subject
            : '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $boundary = 'dv_' . bin2hex(random_bytes(8));

        $msg  = "Date: {$date}\r\n";
        $msg .= "From: {$encoded_name} <{$from}>\r\n";
        $msg .= "To: <{$to}>\r\n";
        $msg .= "Subject: {$encoded_subj}\r\n";
        $msg .= "Message-ID: {$msg_id}\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= "X-Mailer: LetaDial/" . APP_VERSION . "\r\n";

        if ($html) {
            $msg .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
            $msg .= "\r\n";
            $msg .= "--{$boundary}\r\n";
            $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $msg .= "Content-Transfer-Encoding: base64\r\n";
            $msg .= "\r\n";
            $msg .= chunk_split(base64_encode($text), 76, "\r\n");
            $msg .= "--{$boundary}\r\n";
            $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
            $msg .= "Content-Transfer-Encoding: base64\r\n";
            $msg .= "\r\n";
            $msg .= chunk_split(base64_encode($html), 76, "\r\n");
            $msg .= "--{$boundary}--\r\n";
        } else {
            $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $msg .= "Content-Transfer-Encoding: base64\r\n";
            $msg .= "\r\n";
            $msg .= chunk_split(base64_encode($text), 76, "\r\n");
        }

        return $msg;
    }

    private static function cmd(mixed $sock, string $cmd): string
    {
        fwrite($sock, $cmd . "\r\n");
        return self::read($sock);
    }

    private static function read(mixed $sock): string
    {
        $out = '';
        do {
            $line = fgets($sock, 512);
            if ($line === false) break;
            $out .= $line;
        } while (strlen($line) > 3 && $line[3] === '-');
        return $out;
    }

    private static function expect(mixed $sock, int $code): bool
    {
        $response = self::read($sock);
        return (int)substr(trim($response), 0, 3) === $code;
    }

    private static function drainEhlo(mixed $sock): void {}

    private static function wrapHtml(string $title, string $body): string
    {
        $app  = htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8');
        $ttl  = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>{$ttl}</title>
<style>
body{font-family:system-ui,sans-serif;background:#f0f2f5;margin:0;padding:2rem}
.wrap{max-width:520px;margin:0 auto;background:#fff;border-radius:10px;
      box-shadow:0 2px 16px rgba(0,0,0,.08);padding:2rem}
h1{color:#690B22;font-size:1.3rem;margin:0 0 1.5rem}
p{color:#374151;line-height:1.6;margin:.75rem 0}
.btn{display:inline-block;background:#690B22;color:#fff!important;
     padding:.7rem 1.5rem;border-radius:6px;text-decoration:none;
     font-weight:600;margin:1rem 0}
.muted{color:#9ca3af!important;font-size:.85rem}
.footer{margin-top:2rem;padding-top:1rem;border-top:1px solid #e5e7eb;
        color:#9ca3af;font-size:.8rem;text-align:center}
</style></head>
<body>
<div class="wrap">
<h1>{$ttl}</h1>
{$body}
<div class="footer">Sent by {$app}</div>
</div>
</body>
</html>
HTML;
    }
}
