<?php
declare(strict_types=1);

namespace App\Service\Storage;

/**
 * FileStorageInterface
 *
 * Abstraction for file storage backends (S3, local filesystem, etc.).
 */
interface FileStorageInterface
{
    /**
     * Check if this storage backend is enabled and ready
     *
     * @return bool
     */
    public function isEnabled(): bool;

    /**
     * Upload a file to storage
     *
     * @param string $localPath Local file path to upload
     * @param string $storagePath Destination path in storage
     * @param string $contentType MIME type
     * @return bool Success status
     */
    public function upload(string $localPath, string $storagePath, string $contentType = 'application/octet-stream'): bool;

    /**
     * Download a file from storage to a local path
     *
     * @param string $storagePath Path in storage
     * @param string $localPath Local destination path
     * @return bool Success status
     */
    public function download(string $storagePath, string $localPath): bool;

    /**
     * Delete a file from storage
     *
     * @param string $storagePath Path in storage
     * @return bool Success status
     */
    public function delete(string $storagePath): bool;

    /**
     * Get a public/presigned URL for a file
     *
     * @param string $storagePath Path in storage
     * @param int $expirationMinutes URL expiration in minutes
     * @return string|null URL or null on failure
     */
    public function getUrl(string $storagePath, int $expirationMinutes = 60): ?string;

    /**
     * Get a stream resource for a file
     *
     * @param string $storagePath Path in storage
     * @return resource|null Stream resource or null on failure
     */
    public function getStream(string $storagePath);
}
