<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Maintenance\ChangelogService;
use Tests\TestCase;

final class ChangelogTest extends TestCase
{
    private function svc(string $md): ChangelogService
    {
        $f = tempnam(sys_get_temp_dir(), 'cl');
        file_put_contents($f, $md);
        return new ChangelogService($f);
    }

    private const MD = "# Verlauf\n\nVorwort\n\n## 1.2.0 – 2026-02-01\nKurz.\n\n### Neu\n- Eins\n- Zwei\n\n### Wichtig\n- Achtung\n\n## 1.10.0 – 2026-03-01\n### Behoben\n- Fix <b>x</b>\n\n## 1.1.0\n### Neu\n- Alt\n\n## 2.0.0 – 2026-09-01\n### Neu\n- Zukunft\n";

    public function testParsesAndSortsNumerically(): void
    {
        $e = $this->svc(self::MD)->entries();
        $this->assertSame(['2.0.0', '1.10.0', '1.2.0', '1.1.0'], array_column($e, 'version'), '1.10.0 kommt nach 1.2.0');
        $this->assertSame('Kurz.', $e[2]['intro']);
        $this->assertSame(['Eins', 'Zwei'], $e[2]['sections']['Neu']);
        $this->assertSame('', $e[3]['date']);
        $this->assertSame('Fix <b>x</b>', $e[1]['sections']['Behoben'][0], 'Rohtext; Ausgabe wird escaped');
    }

    public function testSinceRespectsSeenAndCurrent(): void
    {
        $s = $this->svc(self::MD);
        $this->assertSame(['1.10.0', '1.2.0'], array_column($s->since('1.1.0', '1.10.0'), 'version'));
        $this->assertSame([], $s->since('1.10.0', '1.10.0'));
        $this->assertSame(['1.10.0'], array_column($s->since(null, '1.10.0'), 'version'), 'ohne Merker nur die aktuelle Version');
        $this->assertSame(['1.10.0'], array_column($s->since('kaputt', '1.10.0'), 'version'));
        $this->assertSame([], array_column((new ChangelogService('/nicht/vorhanden'))->entries(), 'version'));
    }

    public function testIsUnseen(): void
    {
        $this->assertTrue(ChangelogService::isUnseen(null, '1.9.0'));
        $this->assertTrue(ChangelogService::isUnseen('1.8.0', '1.9.0'));
        $this->assertTrue(ChangelogService::isUnseen('1.9.0', '1.10.0'));
        $this->assertFalse(ChangelogService::isUnseen('1.9.0', '1.9.0'));
        $this->assertFalse(ChangelogService::isUnseen(null, '0.0.0'));
    }

    public function testShippedChangelogCoversCurrentVersion(): void
    {
        $root = dirname(__DIR__, 2);
        $cur = trim((string) file_get_contents($root . '/VERSION'));
        $e = (new ChangelogService($root . '/docs/CHANGELOG.md'))->entries();
        $this->assertSame($cur, $e[0]['version'], 'docs/CHANGELOG.md hat einen Eintrag für die aktuelle Version als neuesten');
        $this->assertTrue($e[0]['sections'] !== [], 'Eintrag hat Inhalt');
    }
}
