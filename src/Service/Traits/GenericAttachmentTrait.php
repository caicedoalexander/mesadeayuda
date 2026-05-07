<?php
declare(strict_types=1);

namespace App\Service\Traits;

use Cake\Datasource\EntityInterface;
use Cake\Log\Log;
use Cake\Utility\Text;
use Exception;
use Psr\Http\Message\UploadedFileInterface;

/**
 * GenericAttachmentTrait
 *
 * Ticket attachment handling with security validation (local filesystem only).
 *
 * Requires using class to have:
 * - fetchTable() method (from LocatorAwareTrait)
 */
trait GenericAttachmentTrait
{
    private const ATTACHMENTS_TABLE = 'Attachments';
    private const LOCAL_BASE = 'attachments';
    private const UPLOAD_BASE_DIR = 'uploads' . DS . 'attachments';

    /**
     * Allowed file extensions with their valid MIME types
     */
    private const ALLOWED_TYPES = [
        'jpg' => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'bmp' => ['image/bmp', 'image/x-ms-bmp'],
        'webp' => ['image/webp'],
        'pdf' => ['application/pdf', 'application/octet-stream'],
        'doc' => ['application/msword', 'application/octet-stream'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
            'application/octet-stream',
        ],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/octet-stream',
        ],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/octet-stream'],
        'pptx' => [
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/zip',
            'application/octet-stream',
        ],
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
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

    /**
     * Save uploaded file with robust security validation.
     *
     * @param \Cake\Datasource\EntityInterface $entity Owning entity (ticket)
     * @param \Psr\Http\Message\UploadedFileInterface $file Uploaded file
     * @param int|null $commentId Optional comment ID
     * @param int|null $userId Optional uploader user ID
     * @return \Cake\Datasource\EntityInterface|null Saved attachment or null on failure
     */
    public function saveGenericUploadedFile(
        EntityInterface $entity,
        UploadedFileInterface $file,
        ?int $commentId = null,
        ?int $userId = null,
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
        } catch (Exception $e) {
            Log::error('Failed to move ticket file', ['error' => $e->getMessage()]);

            return null;
        }

        $filePath = $this->buildLocalPath($entityNumber, $filename);

        $attachmentsTable = $this->fetchTable(self::ATTACHMENTS_TABLE);

        $data = $this->buildAttachmentData(
            $entity->id,
            $commentId,
            $userId,
            $originalFilename,
            $filename,
            $mimeType,
            $size,
            $filePath,
        );

        $attachment = $attachmentsTable->newEntity($data, ['accessibleFields' => [
            'filename' => true, 'file_path' => true, 'mime_type' => true, 'file_size' => true,
            'is_inline' => true, 'content_id' => true, 'uploaded_by' => true,
        ]]);

        if (!$attachmentsTable->save($attachment)) {
            Log::error('Failed to save ticket attachment to database', [
                'errors' => $attachment->getErrors(),
            ]);
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }

            return null;
        }

        return $attachment;
    }

    /**
     * @param string $entityNumber Ticket number
     * @param string $filename Stored filename
     * @return string Relative path stored in DB
     */
    private function buildLocalPath(string $entityNumber, string $filename): string
    {
        return 'uploads/' . self::LOCAL_BASE . '/' . $entityNumber . '/' . $filename;
    }

    /**
     * @param string $entityNumber Ticket number
     * @return string Absolute upload directory
     */
    private function getUploadDirectory(string $entityNumber): string
    {
        return WWW_ROOT . self::UPLOAD_BASE_DIR . DS . $entityNumber;
    }

    /**
     * @return string Attachment table name
     */
    protected function getAttachmentTableName(): string
    {
        return self::ATTACHMENTS_TABLE;
    }

    /**
     * Build attachment data array.
     *
     * @param int $entityId Ticket ID
     * @param int|null $commentId Comment ID
     * @param int|null $userId User ID
     * @param string $originalFilename Original filename
     * @param string $filename Stored filename
     * @param string $mimeType MIME type
     * @param int $size File size
     * @param string $filePath Stored relative path
     * @return array
     */
    private function buildAttachmentData(
        int $entityId,
        ?int $commentId,
        ?int $userId,
        string $originalFilename,
        string $filename,
        string $mimeType,
        int $size,
        string $filePath,
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
     *
     * @param \Cake\Datasource\EntityInterface $entity Owning entity (ticket)
     * @param string $filename Original filename
     * @param string $binaryContent Binary content
     * @param string $mimeType MIME type
     * @param int|null $commentId Optional comment ID
     * @param int $userId Uploader user ID
     * @return \Cake\Datasource\EntityInterface|null Saved attachment or null on failure
     */
    public function saveAttachmentFromBinary(
        EntityInterface $entity,
        string $filename,
        string $binaryContent,
        string $mimeType,
        ?int $commentId,
        int $userId,
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

        $data = $this->buildAttachmentData(
            $entity->id,
            $commentId,
            $userId,
            $filename,
            $uniqueFilename,
            $mimeType,
            $size,
            $filePath,
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

        @unlink($fullPath);

        Log::error('Failed to save ticket attachment to database', [
            'errors' => $attachment->getErrors(),
        ]);

        return null;
    }

    /**
     * Delete attachment file and database record.
     *
     * @param int $attachmentId Attachment ID
     * @return bool True on success
     */
    public function deleteGenericAttachment(int $attachmentId): bool
    {
        try {
            $attachmentsTable = $this->fetchTable(self::ATTACHMENTS_TABLE);
            $attachment = $attachmentsTable->get($attachmentId);

            $fullPath = WWW_ROOT . $attachment->file_path;
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }

            return $attachmentsTable->delete($attachment);
        } catch (Exception $e) {
            Log::error('Failed to delete ticket attachment', [
                'attachment_id' => $attachmentId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Validate file for security threats.
     *
     * @param string $filename Filename
     * @param int $size File size
     * @param string|null $mimeType Claimed MIME type
     * @return string|bool True if valid, error string otherwise
     */
    private function validateFile(string $filename, int $size, ?string $mimeType = null): string|bool
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

    /**
     * @param string $filePath Temp file path
     * @param string $claimedMime Claimed MIME type
     * @param string $originalFilename Original filename
     * @return bool True if MIME matches extension
     */
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

    /**
     * @param string $filename Filename to sanitize
     * @return string Sanitized filename
     */
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
     * Get full filesystem path for an attachment.
     *
     * @param \Cake\Datasource\EntityInterface $attachment Attachment entity
     * @return string|null Absolute path or null if file missing
     */
    public function getFullPath(EntityInterface $attachment): ?string
    {
        $fullPath = WWW_ROOT . $attachment->file_path;

        return file_exists($fullPath) ? $fullPath : null;
    }

    /**
     * Get web URL for an attachment (always relative, local filesystem).
     *
     * @param \Cake\Datasource\EntityInterface $attachment Attachment entity
     * @return string|null Web URL
     */
    public function getWebUrl(EntityInterface $attachment): ?string
    {
        if (!str_starts_with((string)$attachment->file_path, 'uploads/')) {
            return null;
        }

        return '/' . str_replace(DS, '/', $attachment->file_path);
    }

    /**
     * Stream file content directly (for downloads).
     *
     * @param \Cake\Datasource\EntityInterface $attachment Attachment entity
     * @return resource|null
     */
    public function getFileStream(EntityInterface $attachment)
    {
        $fullPath = WWW_ROOT . $attachment->file_path;
        if (file_exists($fullPath)) {
            return fopen($fullPath, 'rb');
        }

        return null;
    }
}
