<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\S3StorageService;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Cake\Core\Configure;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

final class S3StorageServiceTest extends TestCase
{
    private MockHandler $mock;

    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('S3', [
            'key' => 'test-key',
            'secret' => 'test-secret',
            'region' => 'us-east-1',
            'bucket' => 'test-bucket',
        ]);
        $this->mock = new MockHandler();
    }

    protected function tearDown(): void
    {
        Configure::delete('S3');
        parent::tearDown();
    }

    private function makeService(): S3StorageService
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
            'handler' => $this->mock,
        ]);

        return new S3StorageService($client);
    }

    public function testPutSendsPutObjectWithKeyMimeAndEncryption(): void
    {
        $this->mock->append(new Result([]));
        $service = $this->makeService();

        $ok = $service->put('attachments/1000/abc.pdf', 'binario', 'application/pdf');

        $this->assertTrue($ok);
        $cmd = $this->mock->getLastCommand();
        $this->assertSame('PutObject', $cmd->getName());
        $this->assertSame('test-bucket', $cmd['Bucket']);
        $this->assertSame('attachments/1000/abc.pdf', $cmd['Key']);
        $this->assertSame('application/pdf', $cmd['ContentType']);
        $this->assertSame('AES256', $cmd['ServerSideEncryption']);
    }

    public function testPutReturnsFalseOnAwsError(): void
    {
        $this->mock->append(function (CommandInterface $cmd) {
            return new S3Exception('acceso denegado', $cmd);
        });
        $service = $this->makeService();

        $this->assertFalse($service->put('attachments/1000/abc.pdf', 'x', 'application/pdf'));
    }

    public function testDeleteSendsDeleteObject(): void
    {
        $this->mock->append(new Result([]));
        $service = $this->makeService();

        $this->assertTrue($service->delete('attachments/1000/abc.pdf'));
        $cmd = $this->mock->getLastCommand();
        $this->assertSame('DeleteObject', $cmd->getName());
        $this->assertSame('attachments/1000/abc.pdf', $cmd['Key']);
        $this->assertSame('test-bucket', $cmd['Bucket']);
    }

    public function testDeleteReturnsFalseOnAwsError(): void
    {
        $this->mock->append(function (CommandInterface $cmd) {
            return new S3Exception('fallo', $cmd);
        });
        $service = $this->makeService();

        $this->assertFalse($service->delete('attachments/1000/abc.pdf'));
    }

    public function testPresignedUrlContainsKeySignatureAndAttachmentDisposition(): void
    {
        $service = $this->makeService();

        $url = $service->presignedUrl('attachments/1000/abc.pdf', 'informe final.pdf');

        $this->assertNotNull($url);
        $this->assertStringContainsString('attachments/1000/abc.pdf', $url);
        $this->assertStringContainsString('X-Amz-Signature=', $url);
        $this->assertStringContainsString(
            rawurlencode('attachment; filename="informe final.pdf"'),
            $url,
        );
    }

    public function testPresignedUrlInlineDisposition(): void
    {
        $service = $this->makeService();

        $url = $service->presignedUrl('attachments/1000/img.png', 'foto.png', inline: true);

        $this->assertNotNull($url);
        $this->assertStringContainsString(
            rawurlencode('inline; filename="foto.png"'),
            $url,
        );
    }

    public function testPresignedUrlEscapesQuotesInFilename(): void
    {
        $service = $this->makeService();

        $url = $service->presignedUrl('attachments/1000/a.pdf', 'ra"ro.pdf');

        $this->assertNotNull($url);
        $this->assertStringContainsString(rawurlencode('filename="ra\"ro.pdf"'), $url);
    }

    public function testPresignedUrlStripsControlCharsFromFilename(): void
    {
        $service = $this->makeService();

        $url = $service->presignedUrl('attachments/1000/a.pdf', "exploit\r\nX-Injected: h.pdf");

        $this->assertNotNull($url);
        $this->assertStringContainsString(
            rawurlencode('filename="exploitX-Injected: h.pdf"'),
            $url,
        );
    }

    public function testGetStreamReturnsResourceWithBody(): void
    {
        $this->mock->append(new Result(['Body' => Utils::streamFor('contenido')]));
        $service = $this->makeService();

        $stream = $service->getStream('attachments/1000/abc.pdf');

        $this->assertIsResource($stream);
        $this->assertSame('contenido', stream_get_contents($stream));
        fclose($stream);
    }

    public function testGetStreamReturnsNullOnAwsError(): void
    {
        $this->mock->append(function (CommandInterface $cmd) {
            return new S3Exception('no existe', $cmd);
        });
        $service = $this->makeService();

        $this->assertNull($service->getStream('attachments/1000/no.pdf'));
    }
}
