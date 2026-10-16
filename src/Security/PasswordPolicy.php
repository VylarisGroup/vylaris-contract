<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\ValidationException;

final class PasswordPolicy
{
    public const MIN_LENGTH = 12;
    private const COMMON = ['passwort1234', 'password1234', '123456789012', 'qwertzuiopasdf', 'vertragsplattform', 'willkommen123'];

    /** @throws ValidationException */
    public static function assertAcceptable(string $password, string $email = '', string $name = ''): void
    {
        $errors = [];
        if (mb_strlen($password) < self::MIN_LENGTH) {
            $errors[] = 'mindestens ' . self::MIN_LENGTH . ' Zeichen';
        }
        if (mb_strlen($password) > 200) {
            $errors[] = 'höchstens 200 Zeichen';
        }
        if (count(array_unique(mb_str_split($password))) < 6) {
            $errors[] = 'mindestens 6 verschiedene Zeichen';
        }
        $lower = mb_strtolower($password);
        if (in_array($lower, self::COMMON, true)) {
            $errors[] = 'kein gängiges Passwort';
        }
        $local = mb_strtolower(explode('@', $email)[0] ?? '');
        if ($local !== '' && mb_strlen($local) >= 4 && str_contains($lower, $local)) {
            $errors[] = 'darf nicht Ihre E-Mail-Adresse enthalten';
        }
        if ($errors !== []) {
            throw new ValidationException(['password' => 'Passwort unzureichend: ' . implode(', ', $errors) . '.']);
        }
    }
}
