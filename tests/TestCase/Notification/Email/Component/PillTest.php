<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Component;

use App\Notification\Email\Component\Pill;
use PHPUnit\Framework\TestCase;

final class PillTest extends TestCase
{
    public function testRendersLabelWithBackgroundAndForegroundColors(): void
    {
        $html = Pill::render(
            label: 'Pendiente',
            bg: '#E3EFFC',
            fg: '#0a3a78',
        );
        self::assertStringContainsString('Pendiente', $html);
        self::assertStringContainsString('background:#E3EFFC', $html);
        self::assertStringContainsString('color:#0a3a78', $html);
    }

    public function testRendersOptionalDot(): void
    {
        $html = Pill::render(
            label: 'Pendiente',
            bg: '#E3EFFC',
            fg: '#0a3a78',
            dotColor: '#0066cc',
        );
        self::assertStringContainsString('background:#0066cc', $html);
        self::assertStringContainsString('border-radius:50%', $html);
    }

    public function testWithoutDotOmitsDotSpan(): void
    {
        $html = Pill::render('X', '#fff', '#000');
        self::assertStringNotContainsString('border-radius:50%', $html);
    }

    public function testEscapesLabel(): void
    {
        $html = Pill::render('<script>x</script>', '#fff', '#000');
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testForStatusKnownStatusReturnsPillWithCorrectLabel(): void
    {
        $html = Pill::forStatus('pendiente');
        self::assertStringContainsString('Pendiente', $html);
    }

    public function testForStatusUnknownFallsBackToCapitalizedKey(): void
    {
        $html = Pill::forStatus('foo');
        self::assertStringContainsString('Foo', $html);
    }
}
