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
        self::assertSame('Compañía Operadora Portuaria Cafetera S.A.', EmailBrand::ORG_NAME);
        self::assertSame('Mesa de Ayuda', EmailBrand::TEAM_NAME);
    }

    public function testLogoUrlReturnsAbsoluteUrlFromFullBaseUrl(): void
    {
        $url = EmailBrand::logoUrl();
        self::assertStringStartsWith('https://mesa.example.com', $url);
        self::assertStringEndsWith('/img/logo-mesa-ayuda.svg', $url);
    }
}
