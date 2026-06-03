<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Component;

use App\Notification\Email\Component\Card;
use PHPUnit\Framework\TestCase;

final class CardTest extends TestCase
{
    public function testRendersHeaderStripBodyAndMeta(): void
    {
        $html = Card::render(
            headerLeftHtml: '<span>#1284</span>',
            headerRightHtml: '<span>14 may</span>',
            title: 'Cafetera #14 no enciende',
            tags: ['Mantenimiento', 'Sucursal Norte'],
            metaColumns: [
                ['label' => 'Solicitante', 'valueHtml' => '<b>Alex</b>'],
                ['label' => 'Asignado a',  'valueHtml' => '<b>Maira</b>'],
            ],
        );

        self::assertStringContainsString('#1284', $html);
        self::assertStringContainsString('14 may', $html);
        self::assertStringContainsString('Cafetera #14 no enciende', $html);
        self::assertStringContainsString('Mantenimiento', $html);
        self::assertStringContainsString('Sucursal Norte', $html);
        self::assertStringContainsString('SOLICITANTE', strtoupper($html));
        self::assertStringContainsString('<b>Alex</b>', $html);
        self::assertStringContainsString('<b>Maira</b>', $html);
    }

    public function testEscapesTitleAndTags(): void
    {
        $html = Card::render('', '', '<X>', ['<T>'], []);
        self::assertStringNotContainsString('<X>', $html);
        self::assertStringNotContainsString('<T>', $html);
    }

    public function testOmitsTagsRowWhenEmpty(): void
    {
        $html = Card::render('', '', 'Title', [], []);
        // The tags row is the only block wrapped in `margin-top:10px`; with no
        // tags that wrapper must be absent.
        self::assertStringNotContainsString('margin-top:10px', $html);
    }

    public function testRendersTagsRowWhenPresent(): void
    {
        $html = Card::render('', '', 'Title', ['Mantenimiento'], []);
        self::assertStringContainsString('margin-top:10px', $html);
        self::assertStringContainsString('Mantenimiento', $html);
    }
}
