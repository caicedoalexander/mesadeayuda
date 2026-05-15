<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Component;

use App\Notification\Email\Component\InfoBox;
use PHPUnit\Framework\TestCase;

final class InfoBoxTest extends TestCase
{
    public function testRendersUppercaseLabelAndContent(): void
    {
        $html = InfoBox::render('Próximos pasos', '<p>Hola</p>', InfoBox::VARIANT_DASHED);
        self::assertStringContainsString('Próximos pasos', $html);
        self::assertStringContainsString('text-transform:uppercase', $html);
        self::assertStringContainsString('<p>Hola</p>', $html);
    }

    public function testDashedVariantUsesDashedBorder(): void
    {
        $html = InfoBox::render('L', '', InfoBox::VARIANT_DASHED);
        self::assertStringContainsString('border:1px dashed', $html);
    }

    public function testSolidVariantUsesSolidBorder(): void
    {
        $html = InfoBox::render('L', '', InfoBox::VARIANT_SOLID);
        self::assertStringContainsString('border:1px solid', $html);
    }

    public function testSoftVariantUsesAccentSoftBackgroundWhenProvided(): void
    {
        $html = InfoBox::render('L', '', InfoBox::VARIANT_SOFT, accentSoft: '#F0EBFE');
        self::assertStringContainsString('background:#F0EBFE', $html);
    }

    public function testEscapesLabel(): void
    {
        $html = InfoBox::render('<x>', '', InfoBox::VARIANT_DASHED);
        self::assertStringNotContainsString('<x>', $html);
    }
}
