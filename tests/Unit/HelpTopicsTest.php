<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Web\HelpTopics;
use Tests\TestCase;

final class HelpTopicsTest extends TestCase
{
    public function testAllTopicsAreWellFormed(): void
    {
        $all = HelpTopics::all();
        $this->assertTrue(count($all) >= 10, 'Anleitungen vorhanden');
        foreach ($all as $slug => $t) {
            $this->assertTrue(preg_match('/^[a-z0-9-]+$/', $slug) === 1, "Slug $slug");
            $this->assertTrue(isset(HelpTopics::GROUPS[$t['group']]), "$slug: Gruppe");
            $this->assertTrue(isset(HelpTopics::AUDIENCES[$t['audience']]), "$slug: Zielgruppe");
            $this->assertTrue(count($t['steps']) >= 2, "$slug: mindestens zwei Schritte");
            foreach ($t['steps'] as $s) {
                $this->assertTrue($s[0] !== '' && $s[1] !== '', "$slug: Schritt vollständig");
            }
            foreach ($t['related'] as $rel) {
                $this->assertTrue(isset($all[$rel]), "$slug: Verweis auf $rel existiert");
            }
            $this->assertFalse(str_contains(json_encode($t, JSON_UNESCAPED_UNICODE), 'ß'), "$slug: Schweizer Schreibweise");
        }
    }

    public function testVisibilityByAudience(): void
    {
        $ext = HelpTopics::visibleFor(false, false, false);
        foreach ($ext as $t) {
            $this->assertSame('all', $t['audience']);
        }
        $int = HelpTopics::visibleFor(false, false, true);
        $this->assertTrue(isset($int['vertrag-erfassen']) && !isset($int['benutzer-rollen']) && !isset($int['cronjob']));
        $adm = HelpTopics::visibleFor(false, true, true);
        $this->assertTrue(isset($adm['benutzer-rollen']) && !isset($adm['cronjob']));
        $sys = HelpTopics::visibleFor(true, false, false);
        $this->assertTrue(isset($sys['cronjob']) && isset($sys['einrichtung']));
    }
}
