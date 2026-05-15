<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Ticket\Component;

use App\Notification\Email\Ticket\Component\CommentBlock;
use PHPUnit\Framework\TestCase;

final class CommentBlockTest extends TestCase
{
    public function testRendersAuthorAndBodyHtmlRaw(): void
    {
        $html = CommentBlock::render(
            authorName: 'Maira Pérez',
            authorRole: 'Líder de soporte',
            authorColor: '#7c3aed',
            bodyHtml: '<p>Hola <em>Alex</em>, ya revisamos.</p>',
            accent: '#00A85E',
            accentSoft: '#E6F7EE',
            timestamp: '14 may · 13:50',
        );

        self::assertStringContainsString('Maira Pérez', $html);
        self::assertStringContainsString('Líder de soporte', $html);
        self::assertStringContainsString('respondió a tu ticket', $html);
        self::assertStringContainsString('<p>Hola <em>Alex</em>, ya revisamos.</p>', $html);
        self::assertStringContainsString('background:#E6F7EE', $html);
        self::assertStringContainsString('14 may · 13:50', $html);
    }

    public function testEscapesAuthorNameAndRoleButNotBody(): void
    {
        $html = CommentBlock::render(
            authorName: '<x>',
            authorRole: '<y>',
            authorColor: '#000',
            bodyHtml: '<p>OK</p>',
            accent: '#000',
            accentSoft: '#fff',
            timestamp: '',
        );
        self::assertStringNotContainsString('<x>', $html);
        self::assertStringNotContainsString('<y>', $html);
        self::assertStringContainsString('<p>OK</p>', $html);
    }
}
