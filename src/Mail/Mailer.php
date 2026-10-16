<?php

declare(strict_types=1);

namespace App\Mail;

interface Mailer
{
    /** @throws MailException bei Zustellfehlern (Aufrufer entscheidet über Wiederholung). */
    public function send(string $toEmail, string $toName, string $subject, string $textBody): void;
}
