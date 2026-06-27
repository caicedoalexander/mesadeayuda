<?php
declare(strict_types=1);

namespace App\Test\TestCase\Html;

use App\Html\HtmlSanitizerPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HtmlSanitizerPolicy::class)]
final class HtmlSanitizerPolicyTest extends TestCase
{
    public function testPreservesBasicTypographyOnDiv(): void
    {
        $html = '<div style="font-family: Calibri, Helvetica, sans-serif; font-size: 12pt; color: rgb(34,34,34); text-align: center; margin: 1em 0px;">Hola</div>';
        $out = HtmlSanitizerPolicy::createPurifier()->purify($html);

        $this->assertStringContainsString('font-family:Calibri, Helvetica, sans-serif', $out);
        $this->assertStringContainsString('font-size:12pt', $out);
        $this->assertStringContainsString('color:rgb(34,34,34)', $out);
        $this->assertStringContainsString('text-align:center', $out);
        $this->assertStringContainsString('margin:1em 0px', $out);
    }

    public function testPreservesBoldItalicUnderline(): void
    {
        $out = HtmlSanitizerPolicy::createPurifier()
            ->purify('<p style="font-weight:bold;font-style:italic;text-decoration:underline">t</p>');

        $this->assertStringContainsString('font-weight:bold', $out);
        $this->assertStringContainsString('font-style:italic', $out);
        $this->assertStringContainsString('text-decoration:underline', $out);
    }

    public function testStripsLayoutAndPositioning(): void
    {
        $out = HtmlSanitizerPolicy::createPurifier()
            ->purify('<div style="position:fixed;top:0;left:0;width:100%;height:100%;z-index:9999;color:rgb(255,0,0)">x</div>');

        $this->assertStringContainsString('color:rgb(255,0,0)', $out);
        $this->assertStringNotContainsString('position', $out);
        $this->assertStringNotContainsString('z-index', $out);
        $this->assertStringNotContainsString('width', $out);
        $this->assertStringNotContainsString('height', $out);
    }

    public function testStripsDangerousCssValues(): void
    {
        $expr = HtmlSanitizerPolicy::createPurifier()
            ->purify('<span style="color:expression(alert(1))">x</span>');
        $this->assertStringNotContainsString('expression', $expr);

        $url = HtmlSanitizerPolicy::createPurifier()
            ->purify('<span style="background:url(javascript:alert(1))">x</span>');
        $this->assertStringNotContainsString('javascript', $url);
        $this->assertStringNotContainsString('url(', $url);
    }

    public function testStripsScriptAndEventHandlers(): void
    {
        $script = HtmlSanitizerPolicy::createPurifier()
            ->purify('<script>alert(1)</script><p>ok</p>');
        $this->assertStringNotContainsString('<script', $script);
        $this->assertStringContainsString('<p>ok</p>', $script);

        $onclick = HtmlSanitizerPolicy::createPurifier()
            ->purify('<div onclick="alert(1)" style="color:rgb(0,0,255)">x</div>');
        $this->assertStringNotContainsString('onclick', $onclick);
        $this->assertStringContainsString('color:rgb(0,0,255)', $onclick);
    }

    public function testKeepsHttpsLinksWithTargetBlank(): void
    {
        $out = HtmlSanitizerPolicy::createPurifier()
            ->purify('<a href="https://forms.gle/x">link</a>');
        $this->assertStringContainsString('href="https://forms.gle/x"', $out);
        $this->assertStringContainsString('target="_blank"', $out);
    }
}
