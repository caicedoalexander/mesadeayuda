<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email;

use App\Notification\Email\EmailBrand;
use Cake\Core\Configure;
use PHPUnit\Framework\TestCase;

final class EmailBrandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('App.fullBaseUrl', 'https://mesa.example.com');
    }

    public function testConstantsHaveExpectedValues(): void
    {
        self::assertSame('Operadora Cafetera S.A.S.', EmailBrand::ORG_NAME);
        self::assertSame('MESA DE AYUDA · OPERADORA CAFETERA', EmailBrand::ORG_TAG_LINE);
        self::assertSame('Carrera 43A #1-50, Medellín', EmailBrand::ORG_ADDRESS);
        self::assertSame('901.234.567-8', EmailBrand::ORG_NIT);
        self::assertSame('soporte@operadoracafetera.com', EmailBrand::SUPPORT_EMAIL);
        self::assertSame('Mesa de Ayuda', EmailBrand::HEADER_TITLE);
        self::assertSame('Soporte Interno', EmailBrand::HEADER_SUBTITLE);
    }

    public function testLogoUrlReturnsAbsoluteUrlFromFullBaseUrl(): void
    {
        $url = EmailBrand::logoUrl();
        self::assertStringStartsWith('https://mesa.example.com', $url);
        self::assertStringEndsWith('/img/logo-mesa-ayuda.svg', $url);
    }
}
