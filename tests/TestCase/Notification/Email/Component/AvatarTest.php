<?php
declare(strict_types=1);

namespace App\Test\TestCase\Notification\Email\Component;

use App\Notification\Email\Component\Avatar;
use PHPUnit\Framework\TestCase;

final class AvatarTest extends TestCase
{
    public function testRendersInitialsWithGivenColorAndSize(): void
    {
        $html = Avatar::render(initials: 'AC', color: '#00A85E', size: 32);
        self::assertStringContainsString('AC', $html);
        self::assertStringContainsString('background:#00A85E', $html);
        self::assertStringContainsString('width:32px', $html);
        self::assertStringContainsString('height:32px', $html);
    }

    public function testInitialsFromNameTakesFirstLettersOfFirstTwoWords(): void
    {
        self::assertSame('AC', Avatar::initialsFromName('Alexander Caicedo'));
        self::assertSame('JL', Avatar::initialsFromName('Julián Loaiza Restrepo'));
        self::assertSame('S', Avatar::initialsFromName('Sistema'));
        self::assertSame('', Avatar::initialsFromName(''));
    }

    public function testEscapesInitials(): void
    {
        $html = Avatar::render('<x>', '#000', 32);
        self::assertStringNotContainsString('<x>', $html);
        self::assertStringContainsString('&lt;x&gt;', $html);
    }
}
