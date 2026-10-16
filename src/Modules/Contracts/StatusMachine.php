<?php

declare(strict_types=1);

namespace App\Modules\Contracts;

/**
 * Reine, zustandslose Definition der erlaubten Vertragsstatuswechsel.
 *
 * Hauptpfad:  draft -> review -> approved -> signature -> active -> archived
 * Nebenpfade: rejected, withdrawn, expired, cancelled
 * "archived" ist final: Änderungen erfolgen über neue Entwürfe, Versionen oder Nachträge.
 */
final class StatusMachine
{
    public const DRAFT = 'draft';
    public const REVIEW = 'review';
    public const APPROVED = 'approved';
    public const SIGNATURE = 'signature';
    public const ACTIVE = 'active';
    public const ARCHIVED = 'archived';
    public const REJECTED = 'rejected';
    public const WITHDRAWN = 'withdrawn';
    public const EXPIRED = 'expired';
    public const CANCELLED = 'cancelled';

    /** @var array<string,list<string>> */
    private const TRANSITIONS = [
        self::DRAFT => [self::REVIEW, self::ARCHIVED],
        self::REVIEW => [self::APPROVED, self::REJECTED, self::WITHDRAWN],
        self::APPROVED => [self::SIGNATURE, self::DRAFT, self::CANCELLED],
        self::SIGNATURE => [self::ACTIVE, self::APPROVED, self::CANCELLED],
        self::ACTIVE => [self::ARCHIVED, self::EXPIRED, self::CANCELLED],
        self::REJECTED => [self::DRAFT, self::ARCHIVED],
        self::WITHDRAWN => [self::DRAFT, self::ARCHIVED],
        self::EXPIRED => [self::ARCHIVED],
        self::CANCELLED => [self::ARCHIVED],
        self::ARCHIVED => [],
    ];

    /** Status, in denen der Vertragsinhalt (Entwurfsversion) bearbeitet werden darf. */
    public const EDITABLE = [self::DRAFT];

    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** @return list<string> */
    public static function allowedFrom(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::TRANSITIONS);
    }
}
