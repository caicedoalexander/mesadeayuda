<?php
declare(strict_types=1);

namespace App\Test\TestCase\View\Helper;

use App\View\Helper\SanitizeHelper;
use Cake\Core\Configure;
use Cake\View\View;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SanitizeHelper::class)]
final class SanitizeHelperTest extends TestCase
{
    private SanitizeHelper $helper;

    protected function setUp(): void
    {
        parent::setUp();
        // The pure-unit bootstrap doesn't load full app config; View builds a
        // Response that needs App.encoding to be set.
        Configure::write('App.encoding', 'UTF-8');
        $this->helper = new SanitizeHelper(new View());
    }

    public function testReturnsEmptyStringForNullOrEmpty(): void
    {
        $this->assertSame('', $this->helper->html(null));
        $this->assertSame('', $this->helper->html(''));
    }

    public function testPreservesBasicTypography(): void
    {
        $out = $this->helper->html('<div style="font-size:12pt;text-align:center">x</div>');

        $this->assertStringContainsString('font-size:12pt', $out);
        $this->assertStringContainsString('text-align:center', $out);
    }

    public function testStripsScript(): void
    {
        $out = $this->helper->html('<script>alert(1)</script><p>ok</p>');

        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringContainsString('<p>ok</p>', $out);
    }
}
