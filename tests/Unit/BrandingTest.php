<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Web\Branding;
use Tests\TestCase;

final class BrandingTest extends TestCase
{
    private function page(string $mark): string
    {
        return '<body><aside class="sidebar"><nav></nav>' . $mark . '</aside><main></main></body>';
    }

    public function testIntactMarkIsUntouched(): void
    {
        $h = $this->page(Branding::html());
        $this->assertSame($h, Branding::enforce($h));
    }

    public function testRemovedMarkIsRestored(): void
    {
        $out = Branding::enforce($this->page(''));
        $this->assertTrue(str_contains($out, Branding::html()));
        $this->assertSame(1, substr_count($out, 'data-vylaris-mark'));
    }

    public function testAlteredMarkIsReplacedByOriginal(): void
    {
        $fake = '<div class="vmark" data-vylaris-mark style="display:none"><a href="https://example.com">eigener Text</a></div>';
        $out = Branding::enforce($this->page($fake));
        $this->assertTrue(str_contains($out, Branding::html()));
        $this->assertTrue(!str_contains($out, 'eigener Text'));
        $this->assertSame(1, substr_count($out, 'data-vylaris-mark'));
    }

    public function testDuplicatedMarkIsCollapsed(): void
    {
        $out = Branding::enforce($this->page(Branding::html() . Branding::html()));
        $this->assertSame(1, substr_count($out, 'data-vylaris-mark'));
    }

    public function testPagesWithoutSidebarAreLeftAlone(): void
    {
        $h = '<body>Login</body>';
        $this->assertSame($h, Branding::enforce($h));
    }
}
