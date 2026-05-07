<?php
declare(strict_types=1);

namespace App\Service\Traits;

use App\Service\S3Service;
use Cake\Datasource\EntityInterface;
use Cake\Log\Log;
use Cake\Utility\Text;
use Psr\Http\Message\UploadedFileInterface;

/**
 * GenericAttachmentTrait
 *
 * Ticket attachment handling with security validation.
 * Supports local storage and AWS S3 (toggled by AWS_S3_ENABLED).
 *
 * Requires using class to have:
 * - fetchTable() method (from LocatorAwareTrait)
 */
trait GenericAttachmentTrait
{
    private const ATTACHMENTS_TABLE = 'Attachments';
    private const S3_PREFIX = 'tickets';
    private const LOCAL_BASE = 'attachments';
    private const UPLOAD_BASE_DIR = 'uploads' . DS . 'attachments';

    /**
     * Allowed file extensions with their valid MIME types
     */
    private const ALLOWED_TYPES = [
        // Images
        'jpg' => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'bmp' => ['image/bmp', 'image/x-ms-bmp'],
        'webp' => ['image/webp'],

        // Documents
        'pdf' => ['application/pdf', 'application/octet-stream'],
        'doc' => ['application/msword', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],

        // Text
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],

        // Archives
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'rar' => ['application/x-rar-compressed', 'application/octet-stream'],
        '7z' => ['application/x-7z-compressed'],
    ];

    /**
     * Dangerous executable extensions that are NEVER allowed
     */
    private const FORBIDDEN_EXTENSIONS = [
        'exe', 'bat', 'cmd', 'com', 'pif', 'scr', 'vbs', 'js', 'jar',
        'sh', 'app', 'deb', 'rpm', 'dmg', 'pkg', 'run', 'msi', 'dll',
        'sys', 'drv', 'cpl', 'scf', 'lnk', 'inf', 'reg',
        'php', 'phtml', 'php3', 'php4', 'php5', 'phps', 'phar', 'cgi',
        'pl', 'py', 'rb', 'asp', 'aspx', 'jsp', 'htaccess', 'htpasswd',
    ];

    private const MAX_FILE_SIZE = 10485760;
    private const MAX_IMAGE_SIZE = 5242880;

    private ?S3Service $s3Service = null;

    private function getS3Service(): S3Service
    {
        if ($this->s3Service === null) {
            $this->s3Service = new S3Service();
        }

        return $this->s3Service;
    }

    /**
     * Save uploaded file with robust security validation.
     */
    public function saveGenericUploadedFile(
        EntityInterface $entity,
        UploadedFileInterface $file,
        ?int $commentId = null,
        ?int $userId = null
    ): ?EntityInterface {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            Log::error('Ticket file upload error', ['error' => $file->getError()]);
            return null;
        }

        $originalFilename = $this->sanitizeFilename($file->getClientFilename());
        $mimeType = $file->getClientMediaType();
        $size = $file->getSize();

        $validation = $this->validateFile($originalFilename, $size, $mimeType);
        if ($validation !== true) {
            Log::error('Ticket file validation failed', [
                'filename' => $originalFilename,
                'reason' => $validation,
            ]);
            return null;
        }

        $tempPath = $file->getStream()->getMetadata('uri');
        if ($tempPath && !$this->verifyMimeTypeFromContent($tempPath, $mimeType, $originalFilename)) {
            Log::error('Ticket MIME type verification failed', [
                'filename' => $originalFilename,
                'claimed_mime' => $mimeType,
            ]);
            return null;
        }

        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $filename = Text::uuid() . '.' . $extension;
        $entityNumber = (string)$entity->ticket_number;

        $s3Service = $this->getS3Service();
        $useS3 = $s3Service->isEnabled();

        $filePath = null;
        $s3Key = null;

        if ($useS3) {
            $s3Key = $this->buildS3Key($entityNumber, $filename);
            $tempPath = sys_get_temp_dir() . DS . $filename;
            try {
                $file->moveTo($tempPath);

                if (!$s3Service->uploadFile($tempPath, $s3Key, $mimeType)) {
                    Log::error('Failed to upload ticket file to S3', ['s3_key' => $s3Key]);
                    @unlink($tempPath);
                    return null;
                }

                @unlink($tempPath);
                $filePath = $s3Key;
            } catch (\Exception $e) {
                Log::error('Failed to process ticket S3 upload', ['error' => $e->getMessage()]);
                @unlink($tempPath);
                return null;
            }
        } else {
            $uploadDir = $this->getUploadDirectory($entityNumber);
            if (!file_exists($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) {
                    Log::error('Failed to create ticket upload directory', ['dir' => $uploadDir]);
                    return null;
                }
            }

            $fullPath = $uploadDir . DS . $filename;
            try {
                $file->moveTo($fullPath);
            } catch (\Exception $e) {
                Log::error('Failed to move ticket file', ['error' => $e->getMessage()]);
                return null;
            }

            $filePath = $this->buildLocalPath($entityNumber, $filename);
        }

        $attachmentsTable = $this->fetchTable(self::ATTACHMENTS_TABLE);

        $data = $this->buildAttachmentData(
            $entity->id,
            $commentId,
            $userId,
            $originalFilename,
            $filename,
            $mimeType,
            $size,
            $filePath
        );

        $attachment = $attachmentsTable->newEntity($data, ['accessibleFields' => [
            'filename' => true, 'file_path' => true, 'mime_type' => true, 'file_size' => true,
            'is_inline' => true, 'content_id' => true, 'uploaded_by' => true,
        ]]);

        if (!$attachmentsTable->save($attachment)) {
            Log::error('Failed to save ticket attachment to database', [
                'errors' => $attachment->getErrors(),
            ]);
            if ($useS3 && $s3Key) {
                $s3Service->deleteFile($s3Key);
            } elseif (!$useS3 && isset($fullPath) && file_exists($fullPath)) {
                unlink($fullPath);
            }
            return null;
        }

        return $attachment;
    }

    private function buildS3Key(string $entityNumber, string $filename): string
    {
        return self::S3_PREFIX . "/{$entityNumber}/{$filename}";
    }

    private function buildLocalPath(string $entityNumber, string $filename): string
    {
        return 'uploads/' . self::LOCAL_BASE . '/' . $entityNumber . '/' . $filename;
    }

    private function getUploadDirectory(string $entityNumber): string
    {
        return WWW_ROOT . self::UPLOAD_BASE_DIR . DS . $entityNumber;
    }

    protected function getAttachmentTableName(): string
    {
        return self::ATTACHMENTS_TABLE;
    }

    private function buildAttachmentData(
        int $entityId,
        ?int $commentId,
        ?int $userId,
        string $originalFilename,
        string $filename,
        string $mimeType,
        int $size,
        string $filePath
    ): array {
        return [
            'ticket_id' => $entityId,
            'comment_id' => $commentId,
            'uploaded_by' => $userId,
            'is_inline' => false,
            'original_filename' => $originalFilename,
            'filename' => $filename,
            'file_path' => $filePath,
            'mime_type' => $mimeType,
            'file_size' => $size,
        ];
    }

    /**
     * Save attachment from binary content (for email attachments).
     */
    public function saveAttachmentFromBinary(
        EntityInterface $entity,
        string $filename,
        string $binaryContent,
        string $mimeType,
        ?int $commentId,
        int $userId
    ): ?EntityInterface {
        $filename = $this->sanitizeFilename($filename);
        $size = strlen($binaryContent);

        $validation = $this->validateFile($filename, $size, $mimeType);
        if ($validation !== true) {
            Log::error('Ticket attachment validation failed', [
                'filename' => $filename,
                'reason' => $validation,
            ]);
            return null;
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $uniqueFilename = Text::uuid() . '.' . $extension;
        $entityNumber = (string)$entity->ticket_number;

        $s3Service = $this->getS3Service();
        $useS3 = $s3Service->isEnabled();

        $filePath = null;

        if ($useS3) {
            $s3Key = $this->buildS3Key($entityNumber, $uniqueFilename);
            $tempPath = sys_get_temp_dir() . DS . $uniqueFilename;
            if (file_put_contents($tempPath, $binaryContent) === false) {
                Log::error('Failed to write ticket temp file', ['path' => $tempPath]);
                return null;
            }

            if (!$s3Service->uploadFile($tempPath, $s3Key, $mimeType)) {
                Log::error('Failed to upload ticket file to S3', ['s3_key' => $s3Key]);
                @unlink($tempPath);
                return null;
            }

            @unlink($tempPath);
            $filePath = $s3Key;
        } else {
            $uploadDir = $this->getUploadDirectory($entityNumber);

            if (!file_exists($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) {
                    Log::error('Failed to create ticket upload directory', ['dir' => $uploadDir]);
                    return null;
                }
            }

            $fullPath = $uploadDir . DS . $uniqueFilename;
            if (file_put_contents($fullPath, $binaryContent) === false) {
                Log::error('Failed to write ticket file', ['path' => $fullPath]);
                return null;
            }

            $filePath = $this->buildLocalPath($entityNumber, $uniqueFilename);
        }

        $data = $this->buildAttachmentData(
            $entity->id,
            $commentId,
            $userId,
            $filename,
            $uniqueFilename,
            $mimeType,
            $size,
            $filePath
        );

        $attachmentsTable = $this->fetchTable(self::ATTACHMENTS_TABLE);
        $attachment = $attachmentsTable->newEntity($data, ['accessibleFields' => [
            'filename' => true, 'file_path' => true, 'mime_type' => true, 'file_size' => true,
            'is_inline' => true, 'content_id' => true, 'uploaded_by' => true,
        ]]);

        if ($attachmentsTable->save($attachment)) {
            Log::info('Ticket attachment saved from binary', [
                'entity_id' => $entity->id,
                'filename' => $filename,
            ]);
            return $attachment;
        }

        if ($useS3) {
            $s3Service->deleteFile($filePath);
        } elseif (isset($fullPath)) {
            @unlink($fullPath);
        }
        Log::error('Failed to save ticket attachment to database', [
            'errors' => $attachment->getErrors(),
        ]);
        return null;
    }

    /**
     * Delete attachment file and database record.
     */
    public function deleteGenericAttachment(int $attachmentId): bool
    {
        try {
            $attachmentsTable = $this->fetchTable(self::ATTACHMENTS_TABLE);
            $attachment = $attachmentsTable->get($attachmentId);

            $isLocal = str_starts_with($attachment->file_path, 'uploads/');

            if ($isLocal) {
                $fullPath = WWW_ROOT . $attachment->file_path;
                if (file_exists($fullPath)) {
                    @unlink($fullPath);
                }
            } else {
                $s3Service = $this->getS3Service();
                if ($s3Service->isEnabled()) {
                    $s3Service->deleteFile($attachment->file_path);
                }
            }

            return $attachmentsTable->delete($attachment);
        } catch (\Exception $e) {
            Log::error('Failed to delete ticket attachment', [
                'attachment_id' => $attachmentId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Validate file for security threats.
     */
    private function validateFile(string $filename, int $size, ?string $mimeType = null)
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (in_array($extension, self::FORBIDDEN_EXTENSIONS)) {
            return 'Executable files are not allowed';
        }

        if (!isset(self::ALLOWED_TYPES[$extension])) {
            return 'File type not allowed: ' . $extension;
        }

        $maxSize = str_starts_with($mimeType ?? '', 'image/')
            ? self::MAX_IMAGE_SIZE
            : self::MAX_FILE_SIZE;

        if ($size > $maxSize) {
            $maxMB = round($maxSize / 1048576, 1);
            return "File too large. Maximum size: {$maxMB}MB";
        }

        if ($size === 0) {
            return 'File is empty';
        }

        if ($mimeType !== null) {
            $allowedMimes = self::ALLOWED_TYPES[$extension];
            if (!in_array($mimeType, $allowedMimes)) {
                return 'MIME type does not match file extension';
            }
        }

        $parts = explode('.', $filename);
        if (count($parts) > 2) {
            foreach ($parts as $part) {
                $partExt = strtolower($part);
                if (in_array($partExt, self::FORBIDDEN_EXTENSIONS)) {
                    return 'Suspicious filename detected';
                }
            }
        }

        return true;
    }

    private function verifyMimeTypeFromContent(string $filePath, string $claimedMime, string $originalFilename): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            Log::warning('finfo not available for MIME verification, rejecting file');
            return false;
        }

        $actualMime = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        if ($actualMime === false) {
            return false;
        }

        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED_TYPES[$extension])) {
            return false;
        }

        $allowedMimes = self::ALLOWED_TYPES[$extension];

        if (in_array($actualMime, $allowedMimes)) {
            return true;
        }

        if ($actualMime === 'application/zip' && in_array($extension, ['docx', 'xlsx', 'pptx'])) {
            return true;
        }

        if (in_array($claimedMime, $allowedMimes)) {
            return true;
        }

        return false;
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = basename($filename);
        $filename = str_replace("\0", '', $filename);
        $filename = str_replace(['../', '..\\'], '', $filename);
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

        if (strlen($filename) > 255) {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $basename = pathinfo($filename, PATHINFO_FILENAME);
            $basename = substr($basename, 0, 250 - strlen($extension));
            $filename = $basename . '.' . $extension;
        }

        return $filename;
    }

    /**
     * Get full filesystem path for an attachment (downloads from S3 to temp if needed).
     */
    public function getFullPath(EntityInterface $attachment): ?string
    {
        $isLocal = str_starts_with($attachment->file_path, 'uploads/');

        if (!$isLocal) {
            $s3Service = $this->getS3Service();
            if ($s3Service->isEnabled()) {
                $tempPath = sys_get_temp_dir() . DS . $attachment->filename;
                if ($s3Service->downloadFile($attachment->file_path, $tempPath)) {
                    return $tempPath;
                }
            }

            return null;
        }

        return WWW_ROOT . $attachment->file_path;
    }

    /**
     * Get web URL for an attachment (presigned for S3, relative for local).
     */
    public function getWebUrl(EntityInterface $attachment): ?string
    {
        $isLocal = str_starts_with($attachment->file_path, 'uploads/');

        if (!$isLocal) {
            $s3Service = $this->getS3Service();
            if ($s3Service->isEnabled()) {
                return $s3Service->getPresignedUrl($attachment->file_path, 60);
            }

            return null;
        }

        return '/' . str_replace(DS, '/', $attachment->file_path);
    }

    /**
     * Stream file content directly (for downloads).
     */
    public function getFileStream(EntityInterface $attachment)
    {
        $isLocal = str_starts_with($attachment->file_path, 'uploads/');

        if (!$isLocal) {
            $s3Service = $this->getS3Service();
            if ($s3Service->isEnabled()) {
                return $s3Service->getFileStream($attachment->file_path);
            }

            return null;
        }

        $fullPath = WWW_ROOT . $attachment->file_path;
        if (file_exists($fullPath)) {
            return fopen($fullPath, 'rb');
        }

        return null;
    }
}
