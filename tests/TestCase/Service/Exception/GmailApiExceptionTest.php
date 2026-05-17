<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Exception;

use App\Service\Exception\GmailApiException;
use App\Service\Gmail\GmailErrorCategory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GmailApiExceptionTest extends TestCase
{
    public function testConstructorPopulatesCategoryCodeAndMessage(): void
    {
        $exception = new GmailApiException(
            GmailErrorCategory::RATE,
            429,
            'quota exceeded',
        );

        $this->assertSame(GmailErrorCategory::RATE, $exception->getCategory());
        $this->assertSame(429, $exception->getCode());
        $this->assertSame('quota exceeded', $exception->getMessage());
        $this->assertNull($exception->getPrevious());
    }

    public function testPreviousExceptionIsPreserved(): void
    {
        $root = new RuntimeException('socket closed');
        $exception = new GmailApiException(
            GmailErrorCategory::TRANSIENT,
            503,
            'service unavailable',
            previous: $root,
        );

        $this->assertSame($root, $exception->getPrevious());
    }
}
