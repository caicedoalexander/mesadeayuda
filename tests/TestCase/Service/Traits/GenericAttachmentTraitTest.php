<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Traits;

use App\Model\Entity\Attachment;
use App\Service\Traits\GenericAttachmentTrait;
use Cake\Datasource\EntityInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Security tests for {@see GenericAttachmentTrait::validateFile} and
 * {@see GenericAttachmentTrait::sanitizeFilename}.
 *
 * This is the most security-sensitive surface in the Service layer (SU-002):
 * attachments come from authenticated agents but ALSO from auto-ingested
 * Gmail messages, where a hostile sender controls every byte of the filename
 * and MIME type. The two methods are the gatekeepers — they must reject
 * weaponized inputs and never produce a stored filename that escapes the
 * intended directory.
 *
 * Test surface:
 *  - Forbidden / executable extensions (.php, .exe, ...).
 *  - Double extensions (payload.php.jpg).
 *  - MIME mismatch vs. claimed extension.
 *  - Empty / oversize files.
 *  - Path traversal in filenames (../, ..\).
 *  - Null-byte injection (a\0.exe.jpg).
 *  - Long filenames (truncation without losing the extension).
 *  - Special-char laundering (whitespace, parens) → safe ASCII.
 */
#[CoversClass(GenericAttachmentTrait::class)]
final class GenericAttachmentTraitTest extends TestCase
{
    private object $harness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->harness = $this->makeHarness();
    }

    // -------------------------------------------------------------------
    // validateFile()
    // -------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, int, ?string, true|string}>
     */
    public static function validateFileCases(): iterable
    {
        // Happy paths
        yield 'png with matching mime' => ['photo.png', 100_000, 'image/png', true];
        yield 'pdf with matching mime' => ['report.pdf', 500_000, 'application/pdf', true];
        yield 'multi-dot legitimate filename' => ['my.report.v2.pdf', 100_000, 'application/pdf', true];
        yield 'jpg without claimed mime (skips mime check)' => ['photo.jpg', 100_000, null, true];

        // Forbidden / executable extensions
        yield 'php extension rejected' => ['shell.php', 1000, 'application/octet-stream', 'Executable files are not allowed'];
        yield 'phtml extension rejected' => ['shell.phtml', 1000, null, 'Executable files are not allowed'];
        yield 'exe extension rejected' => ['payload.exe', 1000, 'application/octet-stream', 'Executable files are not allowed'];
        yield 'bat extension rejected' => ['script.bat', 1000, null, 'Executable files are not allowed'];
        yield 'phar extension rejected' => ['malicious.phar', 1000, null, 'Executable files are not allowed'];
        yield 'js extension rejected' => ['malware.js', 1000, null, 'Executable files are not allowed'];

        // Double-extension: legitimate-looking but contains forbidden in middle
        yield 'double extension php.jpg rejected by multi-dot scan' => [
            'payload.php.jpg', 1000, 'image/jpeg', 'Suspicious filename detected',
        ];
        yield 'double extension exe.txt rejected by multi-dot scan' => [
            'attack.exe.txt', 1000, 'text/plain', 'Suspicious filename detected',
        ];
        yield 'triple extension hides phar in middle' => [
            'note.phar.jpg', 1000, 'image/jpeg', 'Suspicious filename detected',
        ];

        // Disallowed extensions (not forbidden, just unknown)
        yield 'xyz extension not allowed' => ['mystery.xyz', 1000, null, 'File type not allowed: xyz'];
        yield 'extension-less file rejected' => ['README', 1000, null, 'File type not allowed: '];

        // MIME mismatch
        yield 'pdf claimed as html rejected' => [
            'report.pdf', 1000, 'text/html', 'MIME type does not match file extension',
        ];
        yield 'jpg claimed as pdf rejected' => [
            'photo.jpg', 1000, 'application/pdf', 'MIME type does not match file extension',
        ];

        // Size limits
        yield 'image over 5MB rejected' => [
            'big.png', 5_242_881, 'image/png', 'File too large. Maximum size: 5MB',
        ];
        yield 'non-image over 10MB rejected' => [
            'big.pdf', 10_485_761, 'application/pdf', 'File too large. Maximum size: 10MB',
        ];
        yield 'image at exactly 5MB ok' => ['ok.png', 5_242_880, 'image/png', true];
        yield 'pdf at exactly 10MB ok' => ['ok.pdf', 10_485_760, 'application/pdf', true];

        // Empty file
        yield 'zero-byte file rejected' => ['empty.pdf', 0, 'application/pdf', 'File is empty'];

        // docx / xlsx accept zip mime (Office Open XML packages are zip containers)
        yield 'docx with zip mime accepted' => [
            'doc.docx', 100_000, 'application/zip', true,
        ];
        yield 'xlsx with octet-stream accepted' => [
            'sheet.xlsx', 100_000, 'application/octet-stream', true,
        ];
    }

    #[DataProvider('validateFileCases')]
    public function testValidateFile(string $filename, int $size, ?string $mime, bool|string $expected): void
    {
        $this->assertSame($expected, $this->harness->validate($filename, $size, $mime));
    }

    public function testValidateFileIsCaseInsensitiveOnExtension(): void
    {
        // Attackers often use uppercase to dodge naive lowercase comparisons.
        $this->assertSame(
            'Executable files are not allowed',
            $this->harness->validate('shell.PHP', 1000, null),
        );
        $this->assertSame(
            'Executable files are not allowed',
            $this->harness->validate('shell.PhP', 1000, null),
        );
    }

    // -------------------------------------------------------------------
    // sanitizeFilename()
    // -------------------------------------------------------------------

    public function testSanitizeStripsLeadingPathAndTraversal(): void
    {
        // basename() handles classic traversal — verify the contract.
        $this->assertSame('passwd', $this->harness->sanitize('../../etc/passwd'));
        $this->assertSame('passwd', $this->harness->sanitize('/etc/passwd'));
        $this->assertSame('cmd.exe', $this->harness->sanitize('..\\Windows\\system32\\cmd.exe'));
    }

    public function testSanitizeStripsNullBytes(): void
    {
        // Null-byte injection: classic trick to confuse C-string parsers
        // (e.g., older ImageMagick or shell tools). After strip the file
        // still ends up with two extensions and is rejected by validateFile.
        $cleaned = $this->harness->sanitize("a\0.exe.jpg");

        $this->assertStringNotContainsString("\0", $cleaned);
        $this->assertSame('a.exe.jpg', $cleaned);

        $followUp = $this->harness->validate($cleaned, 1000, 'image/jpeg');
        $this->assertSame('Suspicious filename detected', $followUp);
    }

    public function testSanitizeNeverEmitsTraversalSequencesInOutput(): void
    {
        // Contract: no '../' or '..\' in the result, regardless of input.
        // basename() does the heavy lifting (any '../' contains a '/' which
        // is a separator); the literal str_replace is defense-in-depth.
        $this->assertStringNotContainsString('../', $this->harness->sanitize('safe../file.txt'));
        $this->assertStringNotContainsString('..\\', $this->harness->sanitize('a..\\b.txt'));
        $this->assertStringNotContainsString('../', $this->harness->sanitize('../../etc/passwd'));
    }

    public function testSanitizeReplacesUnsafeCharsWithUnderscore(): void
    {
        $this->assertSame(
            'report__final_.pdf',
            $this->harness->sanitize('report (final).pdf'),
        );
        $this->assertSame(
            '_my_invoice_.pdf',
            $this->harness->sanitize(';my invoice!.pdf'),
        );
    }

    public function testSanitizePreservesAlphanumericDotDashUnderscore(): void
    {
        $this->assertSame('My-Report_v2.pdf', $this->harness->sanitize('My-Report_v2.pdf'));
        $this->assertSame('photo.jpeg', $this->harness->sanitize('photo.jpeg'));
    }

    public function testSanitizeTruncatesLongFilenamesPreservingExtension(): void
    {
        $longName = str_repeat('a', 300) . '.pdf';

        $cleaned = $this->harness->sanitize($longName);

        $this->assertLessThanOrEqual(255, strlen($cleaned));
        $this->assertStringEndsWith('.pdf', $cleaned);
    }

    public function testSanitizeOnAlreadyCleanFilenameIsIdempotent(): void
    {
        $clean = 'invoice_2026-05-09.pdf';
        $this->assertSame($clean, $this->harness->sanitize($clean));
        $this->assertSame($clean, $this->harness->sanitize($this->harness->sanitize($clean)));
    }

    public function testSanitizeStripsUnicodeFilenamesToAscii(): void
    {
        // Unicode characters land outside the [a-zA-Z0-9._-] whitelist and
        // are replaced with underscores. Files keep a usable name on disk.
        $cleaned = $this->harness->sanitize('cliente_müller_año.pdf');

        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9._-]+$/', $cleaned);
        $this->assertStringEndsWith('.pdf', $cleaned);
    }

    // -------------------------------------------------------------------
    // getWebUrl()
    // -------------------------------------------------------------------

    public function testGetWebUrlReturnsStableAppRouteForS3Keys(): void
    {
        $attachment = new Attachment([
            'id' => 42,
            'file_path' => 'attachments/1000/abc.pdf',
        ]);

        $this->assertSame('/attachments/view/42', $this->harness->webUrl($attachment));
    }

    public function testGetWebUrlReturnsNullForLegacyLocalPaths(): void
    {
        $attachment = new Attachment([
            'id' => 42,
            'file_path' => 'uploads/attachments/1000/abc.pdf',
        ]);

        $this->assertNull($this->harness->webUrl($attachment));
    }

    // -------------------------------------------------------------------
    // reconcileFilenameToContent() — content-truth for mail attachments
    // -------------------------------------------------------------------

    public function testReconcileRewritesGifAnnouncedAsPng(): void
    {
        // Outlook names inline signature images "image.png" regardless of the
        // real format. These bytes are a GIF; the declared .png must be
        // corrected to .gif instead of the attachment being dropped — this is
        // the root cause of signatures disappearing from Outlook-sourced mail.
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        $this->assertSame(
            ['image.gif', 'image/gif'],
            $this->harness->reconcile('image.png', $gif),
        );
    }

    public function testReconcileKeepsCorrectlyNamedPng(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        $this->assertSame(
            ['photo.png', 'image/png'],
            $this->harness->reconcile('photo.png', $png),
        );
    }

    public function testReconcileKeepsJpegDeclaredExtension(): void
    {
        // A correctly named JPEG keeps its declared extension (not forced to a
        // single canonical alias) so round-trips are lossless.
        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgKCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACP/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AfwD/2Q==');

        $this->assertSame(
            ['photo.jpg', 'image/jpeg'],
            $this->harness->reconcile('photo.jpg', $jpeg),
        );
    }

    public function testReconcilePreservesOfficeXlsxWithZipContent(): void
    {
        // Office Open XML packages sniff as application/zip. The declared .xlsx
        // is authoritative; its canonical office MIME is pinned so the file
        // still downloads/opens as a spreadsheet.
        $emptyZip = "PK\x05\x06" . str_repeat("\x00", 18);

        $this->assertSame(
            ['Libro17.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            $this->harness->reconcile('Libro17.xlsx', $emptyZip),
        );
    }

    public function testReconcileRejectsExecutableDisguisedAsImage(): void
    {
        // Security: a hostile sender renames an opaque binary to image.png.
        // The type is decided on content, so the disguised binary is rejected
        // (finfo reports application/octet-stream, not on the allowlist).
        $binary = "\x7fELF\x02\x01\x01\x00" . str_repeat("\x00", 16);

        $this->assertNull($this->harness->reconcile('image.png', $binary));
    }

    private function makeHarness(): object
    {
        return new class {
            use GenericAttachmentTrait;

            public function validate(string $filename, int $size, ?string $mime): bool|string
            {
                return $this->validateFile($filename, $size, $mime);
            }

            public function sanitize(string $filename): string
            {
                return $this->sanitizeFilename($filename);
            }

            public function webUrl(EntityInterface $attachment): ?string
            {
                return $this->getWebUrl($attachment);
            }

            /**
             * @return array{0: string, 1: string}|null
             */
            public function reconcile(string $filename, string $content): ?array
            {
                return $this->reconcileFilenameToContent($filename, $content);
            }
        };
    }
}
