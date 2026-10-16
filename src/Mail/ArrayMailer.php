<?php

declare(strict_types=1);

namespace App\Mail;

/** Sammelt Mails im Speicher (Tests). */
final class ArrayMailer implements Mailer
{
    /** @var list<array{to:string,subject:string,body:string}> */
    public array $sent = [];
    public bool $fail = false;

    public function send(string $toEmail, string $toName, string $subject, string $textBody): void
    {
        if ($this->fail) {
            throw new MailException('Simulierter Zustellfehler');
        }
        $this->sent[] = ['to' => $toEmail, 'subject' => $subject, 'body' => $textBody];
    }
}
