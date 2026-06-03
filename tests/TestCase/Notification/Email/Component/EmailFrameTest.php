<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Component;

use App\Notification\Email\Component\EmailFrame;
use App\Notification\Email\EmailBrand;
use Cake\Core\Configure;
use PHPUnit\Framework\TestCase;

final class EmailFrameTest extends TestCase
{
    private mixed $previousFullBaseUrl = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousFullBaseUrl = Configure::read('App.fullBaseUrl');
        Configure::write('App.fullBaseUrl', 'https://mesa.example.com');
    }

    protected function tearDown(): void
    {
        if ($this->previousFullBaseUrl === null) {
            Configure::delete('App.fullBaseUrl');
        } else {
            Configure::write('App.fullBaseUrl', $this->previousFullBaseUrl);
        }
        parent::tearDown();
    }

    public function testRendersInnerBodyAndMinimalFooter(): void
    {
        $html = EmailFrame::render('<p>BODY</p>');

        self::assertStringContainsString('<p>BODY</p>', $html);
        // Footer shows the small logo and the two brand lines.
        self::assertStringContainsString('logo-mesa-ayuda.svg', $html);
        self::assertStringContainsString(EmailBrand::TEAM_NAME, $html);
        self::assertStringContainsString(EmailBrand::ORG_NAME, $html);
    }

    public function testDoesNotRenderLegacyChrome(): void
    {
        $html = EmailFrame::render('<p>x</p>');

        // The old frame painted a 4px accent bar (`height:4px`), a header
        // strip with a logo and "Soporte Interno" subtitle, and a footer
        // with NIT/address/support email. None of that should remain.
        self::assertStringNotContainsString('height:4px', $html);
        self::assertStringNotContainsString('Soporte Interno', $html);
        self::assertStringNotContainsString('NIT', $html);
        self::assertStringNotContainsString('@operadoracafetera.com', $html);
    }
}
