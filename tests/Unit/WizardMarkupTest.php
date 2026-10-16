<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Web\Ui;
use Tests\TestCase;

final class WizardMarkupTest extends TestCase
{
    public function testFormWizardFlagsAreRendered(): void
    {
        require_once dirname(__DIR__, 2) . '/src/Web/helpers.php';
        $ui = new Ui('tok');
        $this->assertContains('data-wizard="auto"', $ui->form('/x', '', false, '', 'wizard-auto'));
        $this->assertContains('data-wizard="on"', $ui->form('/x', '', false, '', 'wizard-on'));
        $this->assertFalse(str_contains($ui->form('/x', ''), 'data-wizard'), 'ohne Angabe bleibt das Formular unverändert');
    }

    public function testEveryWizardTemplateHasStepsAndActions(): void
    {
        $root = dirname(__DIR__, 2) . '/templates';
        $n = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if (!str_ends_with((string) $f, '.php')) {
                continue;
            }
            $t = (string) file_get_contents((string) $f);
            if (!str_contains($t, 'data-wizard') && !str_contains($t, "'wizard-")) {
                continue;
            }
            $n++;
            $this->assertTrue(substr_count($t, 'wz-step') >= 2, basename((string) $f) . ': mindestens zwei Schritte');
            $this->assertTrue(str_contains($t, 'wz-actions'), basename((string) $f) . ': Aktionsbereich fehlt');
            $this->assertSame(substr_count($t, '<div'), substr_count($t, '</div>'), basename((string) $f) . ': div-Struktur unausgeglichen');
        }
        $this->assertTrue($n >= 8, 'erwartet: Assistenten in mehreren Vorlagen');
    }

    public function testWizardScriptIsPresentAndLoaded(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertTrue(is_file($root . '/public/assets/wizard.js'));
        $this->assertContains('/assets/wizard.js', (string) file_get_contents($root . '/templates/layout/app.php'));
    }
}
