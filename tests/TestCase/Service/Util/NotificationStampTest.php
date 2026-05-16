<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Util;

use App\Service\Util\NotificationStamp;
use Cake\Core\Configure;
use PHPUnit\Framework\TestCase;

final class NotificationStampTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('Security.salt', str_repeat('a', 64));
    }

    public function testAppendProducesStampedSuffix(): void
    {
        $stamped = NotificationStamp::append('Tu ticket #1284 fue creado', '1284');
        $this->assertMatchesRegularExpression(
            '/^Tu ticket #1284 fue creado \[#1284·s=[0-9a-f]{8}\]$/u',
            $stamped,
        );
    }

    public function testVerifiedTicketNumberReturnsTicketWhenStampValid(): void
    {
        $stamped = NotificationStamp::append('Asunto cualquiera #42', '42');
        $this->assertSame('42', NotificationStamp::verifiedTicketNumber($stamped));
    }

    public function testVerifiedTicketNumberReturnsNullWhenNoStamp(): void
    {
        $this->assertNull(NotificationStamp::verifiedTicketNumber('Tu ticket #1284 fue creado'));
    }

    public function testVerifiedTicketNumberRejectsTamperedHmac(): void
    {
        $stamped = NotificationStamp::append('S', '1');
        $tampered = preg_replace_callback(
            '/(·s=[0-9a-f]{7})([0-9a-f])\]$/u',
            static fn(array $m): string => $m[1] . dechex((hexdec($m[2]) + 1) % 16) . ']',
            $stamped,
        );
        $this->assertNotSame($stamped, $tampered, 'precondition: tamper actually changed the stamp');
        $this->assertNull(NotificationStamp::verifiedTicketNumber($tampered));
    }

    public function testVerifiedTicketNumberRejectsStampMintedWithDifferentSalt(): void
    {
        $stamped = NotificationStamp::append('S', '99');
        Configure::write('Security.salt', str_repeat('b', 64));
        $this->assertNull(NotificationStamp::verifiedTicketNumber($stamped));
    }

    public function testStampIsDeterministicForSameTicketAndSalt(): void
    {
        $a = NotificationStamp::append('X', '7');
        $b = NotificationStamp::append('Y', '7');
        preg_match('/·s=([0-9a-f]{8})\]/u', $a, $ma);
        preg_match('/·s=([0-9a-f]{8})\]/u', $b, $mb);
        $this->assertSame($ma[1], $mb[1]);
    }
}
