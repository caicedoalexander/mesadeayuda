<?php
declare(strict_types=1);

namespace App\Service\Traits;

use App\Utility\EntityType;
use Cake\Datasource\EntityInterface;
use Cake\Log\Log;

/**
 * EntityConversionTrait
 *
 * Provides generic methods for copying data between entities (Tickets ↔ Compras)
 * Eliminates ~160 lines of duplicated code
 *
 * Requirements:
 * - Using class must use LocatorAwareTrait (for fetchTable())
 * - Using class must use TicketSystemTrait (for getCommentsTableName(), getForeignKeyName())
 * - Using class must use GenericAttachmentTrait (for getAttachmentTableName())
 */
trait EntityConversionTrait
{

    /**
     * Copy comments from source entity to target entity
     *
     * Generic method that works for any entity type conversion
     *
     * @param string $sourceType Source entity type ('ticket', 'compra', 'pqrs')
     * @param EntityInterface $sourceEntity Source entity with comments loaded
     * @param string $targetType Target entity type ('ticket', 'compra', 'pqrs')
     * @param EntityInterface $targetEntity Target entity
     * @return int Number of comments copied
     */
    protected function copyComments(
        string $sourceType,
        EntityInterface $sourceEntity,
        string $targetType,
        EntityInterface $targetEntity
    ): int {
        // Get table names and foreign keys
        $sourceCommentsTable = $this->getCommentsTableName($sourceType);
        $targetCommentsTable = $this->getCommentsTableName($targetType);
        $targetForeignKey = $this->getForeignKeyName($targetType);

        // Get association name for source comments
        $sourceCommentsAssoc = $this->getCommentsAssociationName($sourceType);

        // Get loaded comments from source entity
        $sourceComments = $sourceEntity->get($sourceCommentsAssoc);

        if (empty($sourceComments)) {
            return 0;
        }

        $targetTable = $this->fetchTable($targetCommentsTable);
        $copiedCount = 0;

        foreach ($sourceComments as $comment) {
            $newComment = $targetTable->newEntity([
                $targetForeignKey => $targetEntity->id,
                'user_id' => $comment->user_id,
                'comment_type' => $comment->comment_type,
                'body' => $comment->body,
                'is_system_comment' => $comment->is_system_comment,
                'sent_as_email' => false,
            ], ['accessibleFields' => [
                'user_id' => true, 'is_system_comment' => true, 'sent_as_email' => true,
            ]]);

            if ($targetTable->save($newComment)) {
                $copiedCount++;
            } else {
                Log::error('Failed to copy comment', [
                    'source_type' => $sourceType,
                    'target_type' => $targetType,
                    'errors' => $newComment->getErrors(),
                ]);
            }
        }

        return $copiedCount;
    }

    /**
     * Copy attachments from source entity to target entity
     *
     * Generic method that handles file copying and database records
     *
     * @param string $sourceType Source entity type ('ticket', 'compra', 'pqrs')
     * @param EntityInterface $sourceEntity Source entity with attachments loaded
     * @param string $targetType Target entity type ('ticket', 'compra', 'pqrs')
     * @param EntityInterface $targetEntity Target entity
     * @param string $targetEntityNumber Target entity number (for directory naming)
     * @return int Number of attachments copied
     */
    protected function copyAttachments(
        string $sourceType,
        EntityInterface $sourceEntity,
        string $targetType,
        EntityInterface $targetEntity,
        string $targetEntityNumber
    ): int {
        // Get table names and foreign keys (getAttachmentTableName from GenericAttachmentTrait)
        $sourceAttachmentsTable = $this->getAttachmentTableName($sourceType);
        $targetAttachmentsTable = $this->getAttachmentTableName($targetType);
        $targetForeignKey = $this->getForeignKeyName($targetType);

        // Get association name for source attachments
        $sourceAttachmentsAssoc = $this->getAttachmentsAssociationName($sourceType);

        // Get loaded attachments from source entity
        $sourceAttachments = $sourceEntity->get($sourceAttachmentsAssoc);

        if (empty($sourceAttachments)) {
            return 0;
        }

        // Create target directory
        $targetDir = $this->getAttachmentsDirectory($targetType, $targetEntityNumber);
        $targetPath = WWW_ROOT . $targetDir;

        if (!file_exists($targetPath)) {
            mkdir($targetPath, 0755, true);
        }

        $targetTable = $this->fetchTable($targetAttachmentsTable);
        $copiedCount = 0;

        $s3Service = new \App\Service\S3Service();

        foreach ($sourceAttachments as $attachment) {
            try {
                $isS3 = !str_starts_with($attachment->file_path, 'uploads/');
                $newFilePath = null;
                $newStoredPath = null;

                if ($isS3 && $s3Service->isEnabled()) {
                    // S3→S3: copy by downloading to temp then re-uploading
                    $tempFile = sys_get_temp_dir() . DS . $attachment->filename;
                    if (!$s3Service->downloadFile($attachment->file_path, $tempFile)) {
                        Log::warning('Failed to download S3 attachment for copy', [
                            's3_path' => $attachment->file_path,
                            'attachment_id' => $attachment->id,
                        ]);
                        continue;
                    }

                    // Upload to target S3 path
                    $targetS3Key = EntityType::from($targetType)->uploadBasePath()
                        . '/' . $targetEntityNumber . '/' . $attachment->filename;
                    if (!$s3Service->uploadFile($tempFile, $targetS3Key, $attachment->mime_type ?? 'application/octet-stream')) {
                        Log::warning('Failed to upload S3 attachment for copy', [
                            'target_key' => $targetS3Key,
                        ]);
                        @unlink($tempFile);
                        continue;
                    }
                    @unlink($tempFile);
                    $newStoredPath = $targetS3Key;
                } else {
                    // Local→Local copy
                    $oldPath = WWW_ROOT . $attachment->file_path;
                    $newFilePath = $targetPath . $attachment->filename;

                    if (file_exists($oldPath)) {
                        copy($oldPath, $newFilePath);
                    } else {
                        Log::warning('Source attachment file not found', [
                            'path' => $oldPath,
                            'attachment_id' => $attachment->id,
                        ]);
                        continue;
                    }
                    $newStoredPath = $targetDir . $attachment->filename;
                }

                // Create database record
                $newAttachment = $targetTable->newEntity([
                    $targetForeignKey => $targetEntity->id,
                    $this->getCommentForeignKey($targetType) => null,
                    'filename' => $attachment->filename,
                    'original_filename' => $attachment->original_filename,
                    'file_path' => $newStoredPath,
                    'mime_type' => $attachment->mime_type,
                    'file_size' => $attachment->file_size,
                    'is_inline' => $attachment->is_inline,
                    'content_id' => $attachment->content_id,
                    'uploaded_by' => $this->getUploadedByField($attachment, $sourceType),
                ], ['accessibleFields' => [
                    'filename' => true, 'file_path' => true, 'mime_type' => true, 'file_size' => true,
                    'is_inline' => true, 'content_id' => true, 'uploaded_by' => true, 'uploaded_by_user_id' => true,
                ]]);

                if ($targetTable->save($newAttachment)) {
                    $copiedCount++;
                } else {
                    Log::error('Failed to save attachment record', [
                        'errors' => $newAttachment->getErrors(),
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Error copying attachment', [
                    'source_type' => $sourceType,
                    'target_type' => $targetType,
                    'filename' => $attachment->filename,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $copiedCount;
    }

    /**
     * Get comment foreign key field name for attachments table
     *
     * @param string $entityType Entity type
     * @return string Comment foreign key field name
     */
    private function getCommentForeignKey(string $entityType): string
    {
        return EntityType::from($entityType)->commentForeignKey();
    }

    /**
     * Get comments association name for entity
     *
     * @param string $entityType Entity type
     * @return string Association name
     */
    private function getCommentsAssociationName(string $entityType): string
    {
        return EntityType::from($entityType)->commentsAssociation();
    }

    /**
     * Get attachments association name for entity
     *
     * @param string $entityType Entity type
     * @return string Association name
     */
    private function getAttachmentsAssociationName(string $entityType): string
    {
        return EntityType::from($entityType)->attachmentsAssociation();
    }

    /**
     * Get attachments directory for entity type
     *
     * @param string $entityType Entity type
     * @param string $entityNumber Entity number (e.g., TKT-2025-00001)
     * @return string Directory path (relative to WWW_ROOT)
     */
    private function getAttachmentsDirectory(string $entityType, string $entityNumber): string
    {
        return EntityType::from($entityType)->uploadBasePath() . DS . $entityNumber . DS;
    }

    /**
     * Get uploaded_by field value (handles different field names across entities)
     *
     * @param EntityInterface $attachment Source attachment
     * @param string $sourceType Source entity type
     * @return int|null User ID
     */
    private function getUploadedByField(EntityInterface $attachment, string $sourceType): ?int
    {
        // Different entity types use different field names
        if ($sourceType === 'ticket' && isset($attachment->uploaded_by)) {
            return $attachment->uploaded_by;
        }

        if (isset($attachment->uploaded_by_user_id)) {
            return $attachment->uploaded_by_user_id;
        }

        return null;
    }
}
