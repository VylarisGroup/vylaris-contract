<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Contracts\StatusMachine as S;
use Tests\TestCase;

final class StatusMachineTest extends TestCase
{
    public function testMainPathIsAllowed(): void
    {
        $path = [S::DRAFT, S::REVIEW, S::APPROVED, S::SIGNATURE, S::ACTIVE, S::ARCHIVED];
        for ($i = 0; $i < count($path) - 1; $i++) {
            $this->assertTrue(S::canMove($path[$i], $path[$i + 1]), "{$path[$i]} -> {$path[$i + 1]}");
        }
    }

    public function testShortcutsAreForbidden(): void
    {
        $this->assertFalse(S::canMove(S::DRAFT, S::APPROVED), 'Entwurf darf nicht direkt freigegeben werden');
        $this->assertFalse(S::canMove(S::DRAFT, S::ACTIVE));
        $this->assertFalse(S::canMove(S::DRAFT, S::SIGNATURE));
        $this->assertFalse(S::canMove(S::REVIEW, S::SIGNATURE), 'Ohne Freigabe keine Signatur');
        $this->assertFalse(S::canMove(S::REVIEW, S::ACTIVE));
        $this->assertFalse(S::canMove(S::APPROVED, S::ACTIVE), 'Ohne Signatur nicht aktiv');
        $this->assertFalse(S::canMove(S::REJECTED, S::APPROVED));
    }

    public function testArchivedIsFinal(): void
    {
        foreach (S::all() as $to) {
            $this->assertFalse(S::canMove(S::ARCHIVED, $to), "archived -> {$to}");
        }
    }

    public function testNoSelfTransitions(): void
    {
        foreach (S::all() as $s) {
            $this->assertFalse(S::canMove($s, $s), "{$s} -> {$s}");
        }
    }

    public function testUnknownStatusHasNoTransitions(): void
    {
        $this->assertFalse(S::canMove('bogus', S::DRAFT));
        $this->assertFalse(S::canMove(S::DRAFT, 'bogus'));
    }

    public function testSideStatesAreReachable(): void
    {
        $this->assertTrue(S::canMove(S::REVIEW, S::REJECTED));
        $this->assertTrue(S::canMove(S::REVIEW, S::WITHDRAWN));
        $this->assertTrue(S::canMove(S::ACTIVE, S::EXPIRED));
        $this->assertTrue(S::canMove(S::ACTIVE, S::CANCELLED));
        $this->assertTrue(S::canMove(S::SIGNATURE, S::APPROVED), 'Signatur abgelehnt -> zurück zu freigegeben');
        $this->assertTrue(S::canMove(S::REJECTED, S::DRAFT));
    }
}
