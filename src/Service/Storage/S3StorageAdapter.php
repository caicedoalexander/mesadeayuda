<?php
declare(strict_types=1);

namespace App\Service\Storage;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Cake\Core\Configure;
use Cake\Log\Log;

/**
 * S3StorageAdapter
 *
 * AWS S3 implementation of FileStorageInterface.
 * Handles upload, download, delete, presigned URLs, and streaming.
 */
class S3StorageAdapter implements FileStorageInterface
{
    private ?S3Client $client = null;
    private string $bucket;
    private string $region;
    private bool $enabled;

    /**
     * @param array<string, mixed>|null $config Optional config override. Keys: enabled, bucket, region, key, secret.
     *   When null, reads from Configure ('AWS.S3.*').
     */
    public function __construct(?array $config = null)
    {
        $this->enabled = (bool)($config['enabled'] ?? Configure::read('AWS.S3.enabled', false));
        $this->bucket = (string)($config['bucket'] ?? Configure::read('AWS.S3.bucket', ''));
        $this->region = (string)($config['region'] ?? Configure::read('AWS.S3.region', 'us-east-1'));

        if ($this->enabled) {
            $this->initializeClient($config);
        }
    }

    /**
     * @param array<string, mixed>|null $config Optional config override
     */
    private function initializeClient(?array $config = null): void
    {
        try {
            $this->client = new S3Client([
                'version' => 'latest',
                'region' => $this->region,
                'credentials' => [
                    'key' => $config['key'] ?? Configure::read('AWS.S3.key'),
                    'secret' => $config['secret'] ?? Configure::read('AWS.S3.secret'),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to initialize S3 client: ' . $e->getMessage());
            $this->enabled = false;
        }
    }

    /**
     * @inheritDoc
     */
    public function isEnabled(): bool
    {
        return $this->enabled && $this->client !== null;
    }

    /**
     * @inheritDoc
     */
    public function upload(string $localPath, string $storagePath, string $contentType = 'application/octet-stream'): bool
    {
        if (!$this->isEnabled()) {
            Log::warning('S3StorageAdapter: Cannot upload, S3 is disabled');
            return false;
        }

        if (!file_exists($localPath)) {
            Log::error("S3StorageAdapter: Local file not found: {$localPath}");
            return false;
        }

        try {
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $storagePath,
                'SourceFile' => $localPath,
                'ContentType' => $contentType,
                'ServerSideEncryption' => 'AES256',
            ]);

            Log::info("S3StorageAdapter: File uploaded successfully to {$storagePath}");
            return true;
        } catch (AwsException $e) {
            Log::error("S3StorageAdapter: Failed to upload file: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function download(string $storagePath, string $localPath): bool
    {
        if (!$this->isEnabled()) {
            Log::warning('S3StorageAdapter: Cannot download, S3 is disabled');
            return false;
        }

        try {
            $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $storagePath,
                'SaveAs' => $localPath,
            ]);

            Log::info("S3StorageAdapter: File downloaded successfully from {$storagePath}");
            return true;
        } catch (AwsException $e) {
            Log::error("S3StorageAdapter: Failed to download file: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function delete(string $storagePath): bool
    {
        if (!$this->isEnabled()) {
            Log::warning('S3StorageAdapter: Cannot delete, S3 is disabled');
            return false;
        }

        try {
            $this->client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $storagePath,
            ]);

            Log::info("S3StorageAdapter: File deleted successfully from {$storagePath}");
            return true;
        } catch (AwsException $e) {
            Log::error("S3StorageAdapter: Failed to delete file: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function getUrl(string $storagePath, int $expirationMinutes = 60): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $cmd = $this->client->getCommand('GetObject', [
                'Bucket' => $this->bucket,
                'Key' => $storagePath,
            ]);

            $request = $this->client->createPresignedRequest($cmd, "+{$expirationMinutes} minutes");

            return (string)$request->getUri();
        } catch (AwsException $e) {
            Log::error("S3StorageAdapter: Failed to generate presigned URL: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * @inheritDoc
     */
    public function getStream(string $storagePath)
    {
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $result = $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $storagePath,
            ]);

            return $result->get('Body')->detach();
        } catch (AwsException $e) {
            Log::error("S3StorageAdapter: Failed to get file stream: {$e->getMessage()}");
            return null;
        }
    }
}
