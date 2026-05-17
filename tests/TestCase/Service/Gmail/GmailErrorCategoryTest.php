<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Gmail;

use App\Service\Gmail\GmailErrorCategory;
use Google\Service\Exception as GoogleServiceException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GmailErrorCategoryTest extends TestCase
{
    /**
     * @return array<string, array{int, string}>
     */
    public static function httpCodeProvider(): array
    {
        return [
            '401 unauthorized -> auth' => [401, GmailErrorCategory::AUTH],
            '403 forbidden -> auth' => [403, GmailErrorCategory::AUTH],
            '429 too many requests -> rate' => [429, GmailErrorCategory::RATE],
            '500 -> transient' => [500, GmailErrorCategory::TRANSIENT],
            '502 -> transient' => [502, GmailErrorCategory::TRANSIENT],
            '503 -> transient' => [503, GmailErrorCategory::TRANSIENT],
            '504 -> transient' => [504, GmailErrorCategory::TRANSIENT],
            '400 bad request -> permanent' => [400, GmailErrorCategory::PERMANENT],
            '418 teapot -> permanent' => [418, GmailErrorCategory::PERMANENT],
            '200 ok (no error) -> unknown' => [200, GmailErrorCategory::UNKNOWN],
        ];
    }

    #[DataProvider('httpCodeProvider')]
    public function testFromHttpCodeMapsToExpectedCategory(int $code, string $expected): void
    {
        $this->assertSame($expected, GmailErrorCategory::fromHttpCode($code));
    }

    public function testCategorizeReadsGoogleServiceExceptionCode(): void
    {
        $exception = new GoogleServiceException('rate limit', 429);
        $this->assertSame(GmailErrorCategory::RATE, GmailErrorCategory::categorize($exception));
    }

    public function testCategorizePlainThrowableIsUnknown(): void
    {
        $exception = new RuntimeException('boom');
        $this->assertSame(GmailErrorCategory::UNKNOWN, GmailErrorCategory::categorize($exception));
    }
}
