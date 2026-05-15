<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Ticket\Component;

use App\Notification\Email\Ticket\Component\PriorityArrow;
use PHPUnit\Framework\TestCase;

final class PriorityArrowTest extends TestCase
{
    public function testAltaRendersRedUpArrow(): void
    {
        $html = PriorityArrow::render('alta');
        self::assertStringContainsString('Alta', $html);
        self::assertStringContainsString('↑', $html);
        self::assertStringContainsString('#dc3545', $html);
    }

    public function testMediaRendersOrangeRightArrow(): void
    {
        $html = PriorityArrow::render('media');
        self::assertStringContainsString('Media', $html);
        self::assertStringContainsString('→', $html);
        self::assertStringContainsString('#CD6A15', $html);
    }

    public function testBajaRendersGrayDownArrow(): void
    {
        $html = PriorityArrow::render('baja');
        self::assertStringContainsString('Baja', $html);
        self::assertStringContainsString('↓', $html);
        self::assertStringContainsString('#6B7280', $html);
    }

    public function testUnknownPriorityFallsBackToMedia(): void
    {
        $html = PriorityArrow::render('weird');
        self::assertStringContainsString('Weird', $html);
    }
}
