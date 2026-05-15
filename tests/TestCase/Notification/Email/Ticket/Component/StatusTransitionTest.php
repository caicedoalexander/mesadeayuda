<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Ticket\Component;

use App\Notification\Email\Ticket\Component\StatusTransition;
use PHPUnit\Framework\TestCase;

final class StatusTransitionTest extends TestCase
{
    public function testRendersAntesAhoraLabelsAndBothStatusPills(): void
    {
        $html = StatusTransition::render('abierto', 'pendiente', '#0066cc');

        self::assertStringContainsString('ANTES', strtoupper($html));
        self::assertStringContainsString('AHORA', strtoupper($html));
        self::assertStringContainsString('Abierto', $html);
        self::assertStringContainsString('Pendiente', $html);
        self::assertStringContainsString('#0066cc', $html);
        self::assertStringContainsString('CAMBIO APLICADO', strtoupper($html));
    }
}
