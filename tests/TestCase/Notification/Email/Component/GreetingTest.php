<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Component;

use App\Notification\Email\Component\Greeting;
use PHPUnit\Framework\TestCase;

final class GreetingTest extends TestCase
{
    public function testRendersHeadlineH1AndIntroParagraph(): void
    {
        $html = Greeting::render(
            headline: 'Tu ticket fue creado',
            intro: 'Hemos recibido tu solicitud.',
            recipientName: 'Alexander',
        );
        self::assertStringContainsString('Tu ticket fue creado', $html);
        self::assertStringContainsString('Hemos recibido tu solicitud.', $html);
        self::assertStringContainsString('Hola <strong', $html);
        self::assertStringContainsString('Alexander', $html);
    }

    public function testEscapesHeadlineIntroAndName(): void
    {
        $html = Greeting::render('<h>', '<i>', '<n>');
        self::assertStringNotContainsString('<h>', $html);
        self::assertStringNotContainsString('<i>', $html);
        self::assertStringNotContainsString('<n>', $html);
    }

    public function testEmptyRecipientNameOmitsHola(): void
    {
        $html = Greeting::render('H', 'I', '');
        self::assertStringNotContainsString('Hola', $html);
        self::assertStringContainsString('I', $html);
    }
}
