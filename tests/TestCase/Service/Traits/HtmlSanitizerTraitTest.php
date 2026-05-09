<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Traits;

use App\Service\Traits\HtmlSanitizerTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see HtmlSanitizerTrait::truncateSanitizedHtml()}.
 *
 * Covers MA-005: the previous substr() truncation could (a) split UTF-8
 * multi-byte sequences mid-character and (b) cut HTML mid-tag, leaving
 * downstream renders with malformed markup. The new helper guarantees
 * a UTF-8-safe, well-formed HTML output that always fits in the byte budget.
 */
#[CoversClass(HtmlSanitizerTrait::class)]
final class HtmlSanitizerTraitTest extends TestCase
{
    private object $harness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->harness = $this->makeHarness();
    }

    public function testReturnsInputWhenAlreadyUnderBudget(): void
    {
        $html = '<p>Short body.</p>';
        $this->assertSame(
            $html,
            $this->harness->truncate($html, 1000),
            'No truncation should occur when input fits in the budget.',
        );
    }

    public function testZeroBudgetReturnsEmptyString(): void
    {
        $this->assertSame('', $this->harness->truncate('<p>anything</p>', 0));
        $this->assertSame('', $this->harness->truncate('<p>anything</p>', -5));
    }

    public function testTruncatedOutputAlwaysFitsByteBudget(): void
    {
        $html = '<p>' . str_repeat('Lorem ipsum dolor sit amet. ', 5000) . '</p>';
        $this->assertGreaterThan(65535, strlen($html));

        $result = $this->harness->truncate($html, 65000);

        $this->assertLessThanOrEqual(65000, strlen($result));
        $this->assertNotSame('', $result);
    }

    public function testNeverSplitsUtf8MultibyteSequence(): void
    {
        // Each '€' is 3 bytes in UTF-8. A naive substr at an awkward byte offset
        // would corrupt one of them. The helper must always return valid UTF-8.
        $html = '<p>' . str_repeat('€', 1000) . '</p>';

        $result = $this->harness->truncate($html, 1500);

        $this->assertLessThanOrEqual(1500, strlen($result));
        $this->assertNotFalse(
            mb_check_encoding($result, 'UTF-8'),
            'Truncated output must remain valid UTF-8.',
        );
    }

    public function testReclosesTagsLeftOpenByTheCut(): void
    {
        // A naive substr would cut inside the <strong> producing
        // '<p>aaaa<strong>bbbbb' — broken markup. The helper must close it.
        $html = '<p>' . str_repeat('a', 100) . '<strong>'
            . str_repeat('b', 5000) . '</strong></p>';

        $result = $this->harness->truncate($html, 200);

        $this->assertLessThanOrEqual(200, strlen($result));
        $this->assertSame(
            substr_count($result, '<strong>'),
            substr_count($result, '</strong>'),
            'Re-purified output must have balanced <strong> tags.',
        );
        $this->assertSame(
            substr_count($result, '<p>'),
            substr_count($result, '</p>'),
            'Re-purified output must have balanced <p> tags.',
        );
    }

    public function testFallsBackToPlainTextWhenRePurifyOverflows(): void
    {
        // A pathological input that's nearly all entities/tags. The re-purify
        // could expand the cut chunk past the budget. The helper must still
        // honor the byte budget (falls back to strip_tags + trim).
        $html = '<p>' . str_repeat('&amp;&lt;&gt;', 5000) . '</p>';

        $result = $this->harness->truncate($html, 50);

        $this->assertLessThanOrEqual(50, strlen($result));
        $this->assertNotFalse(mb_check_encoding($result, 'UTF-8'));
    }

    public function testPlainTextInputIsHandled(): void
    {
        $plain = str_repeat('hello ', 20_000);
        $this->assertGreaterThan(65535, strlen($plain));

        $result = $this->harness->truncate($plain, 1000);

        $this->assertLessThanOrEqual(1000, strlen($result));
    }

    private function makeHarness(): object
    {
        return new class {
            use HtmlSanitizerTrait;

            public function truncate(string $html, int $max): string
            {
                return $this->truncateSanitizedHtml($html, $max);
            }
        };
    }
}
