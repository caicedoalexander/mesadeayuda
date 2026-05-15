<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Component;

use App\Notification\Email\Component\CtaButton;
use PHPUnit\Framework\TestCase;

final class CtaButtonTest extends TestCase
{
    public function testRendersLabelWithAccentBackgroundAndHref(): void
    {
        $html = CtaButton::render(
            label: 'Ver mi ticket',
            accent: '#CD6A15',
            url: 'https://example.com/t/1',
        );
        self::assertStringContainsString('Ver mi ticket', $html);
        self::assertStringContainsString('background:#CD6A15', $html);
        self::assertStringContainsString('href="https://example.com/t/1"', $html);
    }

    public function testIncludesFallbackUrlLine(): void
    {
        $html = CtaButton::render('Open', '#000', 'https://example.com/abc');
        self::assertStringContainsString('https://example.com/abc', $html);
        self::assertStringContainsString('pega este enlace', $html);
    }

    public function testEscapesLabelAndUrl(): void
    {
        $html = CtaButton::render('<X>', '#000', 'https://e.com/"><script>');
        self::assertStringNotContainsString('<X>', $html);
        self::assertStringNotContainsString('<script>', $html);
    }
}
