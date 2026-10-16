<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\ValidationException;
use App\Modules\Templates\TemplateEngine;
use Tests\TestCase;

final class TemplateEngineTest extends TestCase
{
    private function r(string $body, array $v = [], array $clauses = []): string
    {
        return TemplateEngine::render($body, $v, $clauses)['html'];
    }

    public function testPlaceholdersAreHtmlEscaped(): void
    {
        $this->assertSame('<p>Müller &amp; Co &lt;script&gt;</p>', $this->r('<p>{{name}}</p>', ['name' => 'Müller & Co <script>']));
    }

    public function testDatesAreFormattedSwissStyle(): void
    {
        $this->assertSame('01.07.2026', $this->r('{{start}}', ['start' => '2026-07-01']));
    }

    public function testConditionalBlocks(): void
    {
        $b = '{{#if premium}}A{{else}}B{{/if}}-{{#unless premium}}C{{/unless}}';
        $this->assertSame('A-', $this->r($b, ['premium' => '1']));
        $this->assertSame('B-C', $this->r($b, ['premium' => '']));
        $this->assertSame('X', $this->r('{{#if art == "Kauf"}}X{{/if}}', ['art' => 'Kauf']));
        $this->assertSame('', $this->r('{{#if art == "Kauf"}}X{{/if}}', ['art' => 'Miete']));
        $this->assertSame('Y', $this->r('{{#if art != "Kauf"}}Y{{/if}}', ['art' => 'Miete']));
    }

    public function testNestedConditions(): void
    {
        $b = '{{#if a}}1{{#if b}}2{{else}}3{{/if}}4{{else}}5{{/if}}';
        $this->assertSame('124', $this->r($b, ['a' => '1', 'b' => '1']));
        $this->assertSame('134', $this->r($b, ['a' => '1', 'b' => '']));
        $this->assertSame('5', $this->r($b, ['a' => '', 'b' => '1']));
    }

    public function testClausesIncludeRecursivelyWithConditionsInside(): void
    {
        $c = ['HAFT' => ['body' => '<p>Haftung {{#if cap}}max. {{cap}}{{/if}}</p>', 'review_status' => 'approved', 'title' => 'H']];
        $res = TemplateEngine::render('{{clause:HAFT}}', ['cap' => 'CHF 1000'], $c);
        $this->assertSame('<p>Haftung max. CHF 1000</p>', $res['html']);
        $this->assertSame(['HAFT'], $res['used_clauses']);
    }

    public function testCircularClausesAreRejected(): void
    {
        $c = ['A' => ['body' => '{{clause:B}}', 'review_status' => 'approved', 'title' => ''], 'B' => ['body' => '{{clause:A}}', 'review_status' => 'approved', 'title' => '']];
        $this->assertThrows(ValidationException::class, fn () => TemplateEngine::render('{{clause:A}}', [], $c));
    }

    public function testMissingClauseIsRejected(): void
    {
        $this->assertThrows(ValidationException::class, fn () => TemplateEngine::render('{{clause:NOPE}}', [], []));
    }

    public function testStructuralErrorsAreRejected(): void
    {
        foreach (['{{#if a}}x', 'x{{/if}}', '{{else}}', '{{#if a}}x{{/unless}}', '{{ 1+1 }}', '{{#if a ?? b}}x{{/if}}', '{{system("ls")}}'] as $bad) {
            $this->assertThrows(ValidationException::class, fn () => TemplateEngine::analyze($bad, ['a', 'b']), $bad);
        }
    }

    public function testUnknownFieldsAreReported(): void
    {
        $e = $this->assertThrows(ValidationException::class, fn () => TemplateEngine::analyze('{{kunde}} {{#if foo}}x{{/if}}', ['kunde']));
        $this->assertContains('foo', $e->errors['body']);
    }

    public function testNoCodeExecutionPossible(): void
    {
        $out = $this->r('{{x}}', ['x' => '{{#if y}}boom{{/if}} <?php echo 1; ?>']);
        $this->assertContains('{{#if y}}boom{{/if}}', $out, 'Werte erscheinen wörtlich, werden nicht erneut interpretiert');
        $this->assertContains('&lt;?php', $out);
    }

    public function testHtmlEntitiesInsideConditionAreDecoded(): void
    {
        $this->assertSame('ok', $this->r('{{#if art == &quot;Kauf&quot;}}ok{{/if}}', ['art' => 'Kauf']));
    }
}
