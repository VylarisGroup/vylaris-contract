<?php

declare(strict_types=1);

namespace App\Mail;

/** Platzhalter, solange kein SMTP konfiguriert ist: E-Mails bleiben in der Warteschlange und werden nie "als gesendet" markiert. */
final class UnconfiguredMailer implements Mailer
{
    public function send(string $toEmail, string $toName, string $subject, string $textBody): void
    {
        throw new MailException('SMTP ist nicht konfiguriert.');
    }
}
