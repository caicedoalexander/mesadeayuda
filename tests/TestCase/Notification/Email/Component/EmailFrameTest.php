<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Component;

use App\Notification\Email\Component\EmailFrame;
use App\Notification\Email\EmailBrand;
use App\Notification\Email\EmailTheme;
use Cake\Core\Configure;
use PHPUnit\Framework\TestCase;

final class EmailFrameTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('App.fullBaseUrl', 'https://mesa.example.com');
    }

    public function testRendersAccentBarLogoHeaderInnerAndFooter(): void
    {
        $html = EmailFrame::render(
            EmailTheme::creacion(),
            innerHtml: '<p>BODY</p>',
            ticketReference: '#1284',
        );

        self::assertStringContainsString('background:#CD6A15', $html);
        self::assertStringContainsString('<p>BODY</p>', $html);
        self::assertStringContainsString(EmailBrand::HEADER_TITLE, $html);
        self::assertStringContainsString(EmailBrand::HEADER_SUBTITLE, $html);
        self::assertStringContainsString('#1284', $html);
        self::assertStringContainsString('logo-mesa-ayuda.svg', $html);
        self::assertStringContainsString(EmailBrand::SUPPORT_EMAIL, $html);
        self::assertStringContainsString(EmailBrand::ORG_NIT, $html);
    }
}
