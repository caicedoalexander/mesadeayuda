<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Util;

use App\Service\Util\EmailHeaderParser;
use PHPUnit\Framework\TestCase;

final class EmailHeaderParserTest extends TestCase
{
    public function testExtractMessageIdReturnsNullOnEmptyString(): void
    {
        $this->assertNull(EmailHeaderParser::extractMessageId(''));
    }

    public function testExtractMessageIdStripsAngleBrackets(): void
    {
        $this->assertSame(
            'CAEPj=abc123@mail.gmail.com',
            EmailHeaderParser::extractMessageId('<CAEPj=abc123@mail.gmail.com>'),
        );
    }

    public function testExtractMessageIdTrimsWhitespace(): void
    {
        $this->assertSame(
            'CAEPj=abc123@mail.gmail.com',
            EmailHeaderParser::extractMessageId("   <CAEPj=abc123@mail.gmail.com>   \r\n"),
        );
    }

    public function testExtractMessageIdAcceptsRawIdWithoutBrackets(): void
    {
        $this->assertSame(
            'plain-id@example.com',
            EmailHeaderParser::extractMessageId('plain-id@example.com'),
        );
    }

    public function testExtractMessageIdReturnsNullWhenOnlyWhitespace(): void
    {
        $this->assertNull(EmailHeaderParser::extractMessageId("   \r\n   "));
    }
}
