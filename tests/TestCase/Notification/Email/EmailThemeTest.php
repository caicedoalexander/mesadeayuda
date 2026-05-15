<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email;

use App\Notification\Email\EmailTheme;
use PHPUnit\Framework\TestCase;

final class EmailThemeTest extends TestCase
{
    public function testCreacionFactoryReturnsOrangePalette(): void
    {
        $theme = EmailTheme::creacion();
        self::assertSame('#CD6A15', $theme->accent);
        self::assertSame('#FCEFE0', $theme->accentSoft);
        self::assertSame('#6b3306', $theme->accentInk);
        self::assertSame('Nuevo ticket', $theme->tag);
    }

    public function testEstadoFactoryReturnsBluePalette(): void
    {
        $theme = EmailTheme::estado();
        self::assertSame('#0066cc', $theme->accent);
        self::assertSame('#E3EFFC', $theme->accentSoft);
        self::assertSame('#0a3a78', $theme->accentInk);
        self::assertSame('Cambio de estado', $theme->tag);
    }

    public function testComentarioFactoryReturnsGreenPalette(): void
    {
        $theme = EmailTheme::comentario();
        self::assertSame('#00A85E', $theme->accent);
        self::assertSame('#E6F7EE', $theme->accentSoft);
        self::assertSame('#00432a', $theme->accentInk);
        self::assertSame('Nuevo comentario', $theme->tag);
    }

    public function testActualizacionFactoryReturnsPurplePalette(): void
    {
        $theme = EmailTheme::actualizacion();
        self::assertSame('#7c3aed', $theme->accent);
        self::assertSame('#F0EBFE', $theme->accentSoft);
        self::assertSame('#3c1d8a', $theme->accentInk);
        self::assertSame('Actualización', $theme->tag);
    }
}
