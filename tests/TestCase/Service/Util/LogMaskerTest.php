<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Util;

use App\Service\Util\LogMasker;
use PHPUnit\Framework\TestCase;

final class LogMaskerTest extends TestCase
{
    public function testMasksTypicalEmail(): void
    {
        $this->assertSame('a***@example.com', LogMasker::email('alex@example.com'));
    }

    public function testMasksSingleCharLocal(): void
    {
        $this->assertSame('*@example.com', LogMasker::email('a@example.com'));
    }

    public function testMasksMultiRecipientCommaSeparated(): void
    {
        $this->assertSame(
            '*@x.com, b***@y.com',
            LogMasker::email('a@x.com, bob@y.com'),
        );
    }

    public function testReturnsEmptyStringForEmpty(): void
    {
        $this->assertSame('', LogMasker::email(''));
    }

    public function testReturnsInputUnchangedWhenNoAtSign(): void
    {
        $this->assertSame('notanemail', LogMasker::email('notanemail'));
    }

    public function testReturnsInputUnchangedWhenAtSignAtStart(): void
    {
        $this->assertSame('@example.com', LogMasker::email('@example.com'));
    }

    public function testMasksE164PhoneToCountryAndLast4(): void
    {
        $this->assertSame('+57***4567', LogMasker::phone('+573001234567'));
    }

    public function testReturnsTripleStarForTooShortInput(): void
    {
        $this->assertSame('***', LogMasker::phone('+57'));
    }

    public function testReturnsEmptyForEmptyInput(): void
    {
        $this->assertSame('', LogMasker::phone(''));
    }
}
