<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Storage\FileStorageInterface;
use App\Service\Storage\S3StorageAdapter;

/**
 * S3 Service
 *
 * Backward-compatible facade over S3StorageAdapter.
 * New code should depend on FileStorageInterface instead.
 */
class S3Service
{
    private FileStorageInterface $adapter;

    /**
     * @param array<string, mixed>|null $config Optional config override for S3StorageAdapter
     */
    public function __construct(?array $config = null)
    {
        $this->adapter = new S3StorageAdapter($config);
    }

    public function isEnabled(): bool
    {
        return $this->adapter->isEnabled();
    }

    public function uploadFile(string $localPath, string $s3Path, string $contentType = 'application/octet-stream'): bool
    {
        return $this->adapter->upload($localPath, $s3Path, $contentType);
    }

    public function downloadFile(string $s3Path, string $localPath): bool
    {
        return $this->adapter->download($s3Path, $localPath);
    }

    public function deleteFile(string $s3Path): bool
    {
        return $this->adapter->delete($s3Path);
    }

    public function getPresignedUrl(string $s3Path, int $expirationMinutes = 60): ?string
    {
        return $this->adapter->getUrl($s3Path, $expirationMinutes);
    }

    /**
     * @return resource|null
     */
    public function getFileStream(string $s3Path)
    {
        return $this->adapter->getStream($s3Path);
    }
}
