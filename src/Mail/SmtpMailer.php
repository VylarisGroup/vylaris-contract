<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Schlanker SMTP-Client (RFC 5321/5322): Implicit TLS (smtps/465) oder STARTTLS (587),
 * AUTH LOGIN/PLAIN, UTF-8 (Betreff RFC 2047, Text base64). TLS-Zertifikate werden geprüft.
 * Header-Injection wird durch Entfernen von CR/LF in allen Header-Werten verhindert.
 *
 * Nicht gegen einen echten Server getestet, sondern gegen einen lokalen Mock-Server (siehe Tests).
 */
final class SmtpMailer implements Mailer
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption,   // 'tls' (implicit), 'starttls', 'none' (nur Tests)
        private readonly string $username,
        private readonly string $password,
        private readonly string $fromEmail,
        private readonly string $fromName,
        private readonly int $timeout = 15,
        private readonly bool $verifyPeer = true,
    ) {
        if (!in_array($encryption, ['tls', 'starttls', 'none'], true)) {
            throw new \InvalidArgumentException('Ungültige SMTP-Verschlüsselung.');
        }
    }

    public function send(string $toEmail, string $toName, string $subject, string $textBody): void
    {
        $toEmail = self::clean($toEmail);
        if (filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailException('Ungültige Empfängeradresse.');
        }
        $ctx = stream_context_create(['ssl' => ['verify_peer' => $this->verifyPeer, 'verify_peer_name' => $this->verifyPeer, 'allow_self_signed' => !$this->verifyPeer]]);
        $scheme = $this->encryption === 'tls' ? 'ssl' : 'tcp';
        $fp = @stream_socket_client("{$scheme}://{$this->host}:{$this->port}", $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($fp === false) {
            throw new MailException('SMTP-Verbindung fehlgeschlagen.');
        }
        stream_set_timeout($fp, $this->timeout);
        try {
            $this->expect($fp, [220]);
            $ehlo = $this->cmd($fp, 'EHLO ' . self::clean(gethostname() ?: 'localhost'), [250]);
            if ($this->encryption === 'starttls') {
                $this->cmd($fp, 'STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new MailException('STARTTLS fehlgeschlagen.');
                }
                $ehlo = $this->cmd($fp, 'EHLO ' . self::clean(gethostname() ?: 'localhost'), [250]);
            }
            if ($this->username !== '') {
                if ($this->encryption === 'none') {
                    throw new MailException('Authentifizierung ohne Verschlüsselung ist nicht erlaubt.');
                }
                $this->cmd($fp, 'AUTH LOGIN', [334]);
                $this->cmd($fp, base64_encode($this->username), [334]);
                $this->cmd($fp, base64_encode($this->password), [235]);
            }
            $this->cmd($fp, 'MAIL FROM:<' . self::clean($this->fromEmail) . '>', [250]);
            $this->cmd($fp, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
            $this->cmd($fp, 'DATA', [354]);
            $msg = $this->build($toEmail, $toName, $subject, $textBody);
            fwrite($fp, $msg . "\r\n.\r\n");
            $this->expect($fp, [250]);
            @$this->cmd($fp, 'QUIT', [221]);
        } finally {
            fclose($fp);
        }
        unset($ehlo);
    }

    private function build(string $toEmail, string $toName, string $subject, string $body): string
    {
        $host = preg_replace('/[^A-Za-z0-9.-]/', '', (string) (parse_url('//' . $this->host, PHP_URL_HOST) ?: 'localhost')) ?: 'localhost';
        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'From: ' . self::encodeName($this->fromName) . ' <' . self::clean($this->fromEmail) . '>',
            'To: ' . ($toName !== '' ? self::encodeName($toName) . ' ' : '') . '<' . $toEmail . '>',
            'Subject: =?UTF-8?B?' . base64_encode(self::clean($subject)) . '?=',
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $host . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Auto-Submitted: auto-generated',
        ];
        $b64 = chunk_split(base64_encode(str_replace(["\r\n", "\r"], "\n", $body)), 76, "\r\n");
        return implode("\r\n", $headers) . "\r\n\r\n" . rtrim($b64);
    }

    private static function clean(string $v): string
    {
        return trim(str_replace(["\r", "\n", "\0", '<', '>'], '', $v));
    }

    private static function encodeName(string $name): string
    {
        return '=?UTF-8?B?' . base64_encode(self::clean($name)) . '?=';
    }

    /** @param resource $fp @param list<int> $ok */
    private function cmd($fp, string $line, array $ok): string
    {
        fwrite($fp, $line . "\r\n");
        return $this->expect($fp, $ok);
    }

    /** @param resource $fp @param list<int> $ok */
    private function expect($fp, array $ok): string
    {
        $all = '';
        while (($l = fgets($fp, 1024)) !== false) {
            $all .= $l;
            if (strlen($l) < 4 || $l[3] !== '-') {
                break;
            }
        }
        $code = (int) substr($all, 0, 3);
        if (!in_array($code, $ok, true)) {
            throw new MailException('SMTP-Fehler (Code ' . $code . ').');
        }
        return $all;
    }
}
