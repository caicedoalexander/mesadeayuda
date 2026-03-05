<?php
declare(strict_types=1);

namespace App\Service\Traits;

use App\Utility\EntityType;
use Cake\Log\Log;
use Cake\I18n\FrozenTime;

/**
 * TicketSystemTrait
 * 
 * Shared logic for Tickets and PQRS management.
 * Requires the using class to have:
 * - fetchTable() method (from LocatorAwareTrait)
 * - emailService property
 */
trait TicketSystemTrait
{
    /**
     * Change entity status
     *
     * REFACTORED: Now supports all 3 entity types (Ticket, PQRS, Compra)
     *
     * @param \Cake\Datasource\EntityInterface $entity Ticket, PQRS, or Compra entity
     * @param string $newStatus New status
     * @param int|null $userId User making the change
     * @param string|null $comment Optional comment
     * @param bool $sendNotifications Whether to send notifications
     * @return bool Success
     */
    public function changeStatus(
        \Cake\Datasource\EntityInterface $entity,
        string $newStatus,
        ?int $userId = null,
        ?string $comment = null,
        bool $sendNotifications = true
    ): bool {
        $table = $this->fetchTable($entity->getSource());
        $oldStatus = $entity->status;

        if ($oldStatus === $newStatus) {
            return true; // No change needed
        }

        $entity->status = $newStatus;

        // Update timestamps based on status
        $now = FrozenTime::now();
        if ($newStatus === 'resuelto' && !$entity->resolved_at) {
            $entity->resolved_at = $now;
        }
        if ($newStatus === 'cerrado' && isset($entity->closed_at) && !$entity->closed_at) {
            $entity->closed_at = $now;
        }

        if (!$table->save($entity)) {
            Log::error('Failed to change status', ['errors' => $entity->getErrors()]);
            return false;
        }

        // Determine entity type from source
        $entityType = $this->getEntityTypeFromSource($entity->getSource());
        $historyTable = $this->getHistoryTableName($entityType);
        $foreignKey = $this->getForeignKeyName($entityType);

        // Log the change
        $this->logHistory(
            $historyTable,
            $foreignKey,
            $entity->id,
            'status',
            $oldStatus,
            $newStatus,
            $userId,
            "Estado cambiado de '{$oldStatus}' a '{$newStatus}'"
        );

        // Add system comment (always internal)
        if ($comment) {
            $this->addComment($entity->id, $userId, $comment, $entityType, 'internal', true);
        } else {
            $systemComment = "El estado cambió de '{$oldStatus}' a '{$newStatus}'";
            $this->addComment($entity->id, $userId, $systemComment, $entityType, 'internal', true);
        }

        // Send notifications ONLY if requested
        if ($sendNotifications) {
            try {
                $this->emailService->sendEntityStatusChangeNotification($entityType, $entity, $oldStatus, $newStatus);
            } catch (\Exception $e) {
                Log::error('Failed to send status change email notification: ' . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * Add comment to entity
     *
     * REFACTORED: Now supports all 3 entity types using string instead of bool
     *
     * NOTE: This method does NOT send notifications. Notifications are handled
     * by each service's handleResponse() via sendResponseNotifications() for proper
     * coordination of comment + status change + file uploads.
     *
     * @param int $entityId Entity ID
     * @param int|null $userId User ID (null for public/anonymous comments)
     * @param string $body Comment body
     * @param string $type 'public' or 'internal'
     * @param bool $isSystem Is this a system-generated comment?
     * @param string $entityType Entity type: 'ticket', 'pqrs', or 'compra'
     * @param array|null $emailTo Array of TO recipients [{'name': '...', 'email': '...'}]
     * @param array|null $emailCc Array of CC recipients [{'name': '...', 'email': '...'}]
     * @return \Cake\Datasource\EntityInterface|null Created comment or null
     */
    public function addComment(
        int $entityId,
        ?int $userId,
        string $body,
        string $entityType, // REQUIRED: 'ticket', 'pqrs', or 'compra'
        string $type = 'public',
        bool $isSystem = false,
        ?array $emailTo = null,
        ?array $emailCc = null
    ): ?\Cake\Datasource\EntityInterface {
        $commentsTableName = $this->getCommentsTableName($entityType);
        $commentsTable = $this->fetchTable($commentsTableName);

        $entityTableName = $this->getEntityTableName($entityType);
        $entityTable = $this->fetchTable($entityTableName);
        $entity = $entityTable->get($entityId);

        // Sanitize HTML content to prevent stored XSS
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', 'p,br,b,i,u,strong,em,a[href],ul,ol,li,blockquote,h1,h2,h3,h4,h5,h6,img[src|alt|width|height],table,thead,tbody,tr,td,th,span,div,pre,code,hr');
        $config->set('HTML.TargetBlank', true);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('Cache.DefinitionImpl', null);
        $purifier = new \HTMLPurifier($config);
        $sanitizedBody = $purifier->purify($body);

        $data = [
            'user_id' => $userId,
            'comment_type' => $type,
            'body' => $sanitizedBody,
            'is_system_comment' => $isSystem,
        ];

        // Add email recipients if provided (only for public comments)
        if ($type === 'public' && !$isSystem) {
            if (is_array($emailTo) && count($emailTo) > 0) {
                $data['email_to'] = json_encode($emailTo);
            }
            if (is_array($emailCc) && count($emailCc) > 0) {
                $data['email_cc'] = json_encode($emailCc);
            }
        }

        // Set foreign key based on entity type
        $foreignKey = $this->getForeignKeyName($entityType);
        $data[$foreignKey] = $entityId;

        // Add sent_as_email field for PQRS and Compras
        if ($entityType === 'pqrs' || $entityType === 'compra') {
            $data['sent_as_email'] = false;
        }

        $comment = $commentsTable->newEntity($data, ['accessibleFields' => [
            'user_id' => true, 'is_system_comment' => true, 'sent_as_email' => true,
        ]]);

        if (!$commentsTable->save($comment)) {
            Log::error('Failed to add comment', ['errors' => $comment->getErrors()]);
            return null;
        }

        // Update first_response_at if this is the first non-system comment
        if (!$isSystem && !$entity->first_response_at && $userId) {
            $entity->first_response_at = FrozenTime::now();
            $entityTable->save($entity);
        }

        return $comment;
    }

    /**
     * Assign entity to a user
     *
     * REFACTORED: Now supports all 3 entity types (Ticket, PQRS, Compra)
     *
     * @param \Cake\Datasource\EntityInterface $entity Ticket, PQRS, or Compra entity
     * @param int|null $assigneeId User ID to assign to (null to unassign)
     * @param int|null $userId User making the assignment
     * @return bool Success
     */
    public function assign(
        \Cake\Datasource\EntityInterface $entity,
        ?int $assigneeId,
        ?int $userId = null
    ): bool {
        $table = $this->fetchTable($entity->getSource());
        $usersTable = $this->fetchTable('Users');

        $oldAssigneeId = $entity->assignee_id;

        // Convert 0 to null for "unassigned" option
        $entity->assignee_id = ($assigneeId === 0 || $assigneeId === '0') ? null : $assigneeId;

        if (!$table->save($entity)) {
            $errors = $entity->getErrors();
            $entityClass = get_class($entity);

            Log::error("Failed to assign entity - Type: {$entityClass}, ID: {$entity->id}");
            Log::error("Assignment details - New assignee: {$assigneeId}, Old assignee: {$oldAssigneeId}");
            Log::error("Validation errors: " . print_r($errors, true));
            Log::error("Dirty fields: " . print_r($entity->getDirty(), true));

            return false;
        }

        // Get assignee names for history
        $oldAssigneeName = 'Sin asignar';
        if ($oldAssigneeId) {
            $oldUser = $usersTable->get($oldAssigneeId);
            $oldAssigneeName = $oldUser->first_name . ' ' . $oldUser->last_name;
        }

        $newAssigneeName = 'Sin asignar';
        if ($assigneeId) {
            $newUser = $usersTable->get($assigneeId);
            $newAssigneeName = $newUser->first_name . ' ' . $newUser->last_name;
        }

        // Determine entity type from source
        $entityType = $this->getEntityTypeFromSource($entity->getSource());
        $historyTable = $this->getHistoryTableName($entityType);
        $foreignKey = $this->getForeignKeyName($entityType);

        // Log the change
        $this->logHistory(
            $historyTable,
            $foreignKey,
            $entity->id,
            'assignee_id',
            $oldAssigneeName,
            $newAssigneeName,
            $userId,
            "Asignado a {$newAssigneeName}"
        );

        // Add system comment
        $systemComment = "Asignado a {$newAssigneeName}";
        $this->addComment($entity->id, $userId, $systemComment, $entityType, 'internal', true);

        return true;
    }

    /**
     * Change entity priority
     *
     * REFACTORED: Now supports all 3 entity types (Ticket, PQRS, Compra)
     *
     * @param \Cake\Datasource\EntityInterface $entity Ticket, PQRS, or Compra entity
     * @param string $newPriority New priority
     * @param int|null $userId User making the change
     * @return bool Success
     */
    public function changePriority(
        \Cake\Datasource\EntityInterface $entity,
        string $newPriority,
        ?int $userId = null
    ): bool {
        $table = $this->fetchTable($entity->getSource());
        $oldPriority = $entity->priority;

        if ($oldPriority === $newPriority) {
            return true;
        }

        $entity->priority = $newPriority;

        if (!$table->save($entity)) {
            Log::error('Failed to change priority', ['errors' => $entity->getErrors()]);
            return false;
        }

        // Determine entity type from source
        $entityType = $this->getEntityTypeFromSource($entity->getSource());
        $historyTable = $this->getHistoryTableName($entityType);
        $foreignKey = $this->getForeignKeyName($entityType);

        // Log the change
        $this->logHistory(
            $historyTable,
            $foreignKey,
            $entity->id,
            'priority',
            $oldPriority,
            $newPriority,
            $userId,
            "Prioridad cambiada de '{$oldPriority}' a '{$newPriority}'"
        );

        // Add system comment
        $systemComment = "Prioridad cambiada de '{$oldPriority}' a '{$newPriority}'";
        $this->addComment($entity->id, $userId, $systemComment, $entityType, 'internal', true);

        return true;
    }

    /**
     * Log change to history
     */
    private function logHistory(
        string $tableName,
        string $foreignKey,
        int $entityId,
        string $fieldName,
        ?string $oldValue,
        ?string $newValue,
        ?int $userId = null,
        ?string $description = null
    ): void {
        $historyTable = $this->fetchTable($tableName);

        // Assuming both history tables have a logChange method or similar structure
        // If not, we might need to implement it here or rely on the Table method if it exists
        if (method_exists($historyTable, 'logChange')) {
            $historyTable->logChange($entityId, $fieldName, $oldValue, $newValue, $userId, $description);
        } else {
            // Fallback implementation
            $history = $historyTable->newEntity([
                $foreignKey => $entityId,
                'changed_by' => $userId,
                'field_name' => $fieldName,
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'description' => $description,
            ], ['accessibleFields' => ['changed_by' => true]]);
            $historyTable->save($history);
        }
    }

    /**
     * Helper methods for entity type mapping
     * These methods provide consistent naming across all 3 entity types
     */

    /**
     * Get entity type from table source name
     *
     * @param string $source Source name (Tickets, Pqrs, Compras)
     * @return string Entity type (ticket, pqrs, compra)
     */
    private function getEntityTypeFromSource(string $source): string
    {
        return EntityType::fromSource($source)->value;
    }

    /**
     * Get entity table name from entity type
     *
     * @param string $entityType Entity type (ticket, pqrs, compra)
     * @return string Table name
     */
    private function getEntityTableName(string $entityType): string
    {
        return EntityType::from($entityType)->tableName();
    }

    /**
     * Get comments table name from entity type
     *
     * Protected: also used by EntityConversionTrait
     *
     * @param string $entityType Entity type (ticket, pqrs, compra)
     * @return string Comments table name
     */
    protected function getCommentsTableName(string $entityType): string
    {
        return EntityType::from($entityType)->commentsTable();
    }

    /**
     * Get history table name from entity type
     *
     * @param string $entityType Entity type (ticket, pqrs, compra)
     * @return string History table name
     */
    private function getHistoryTableName(string $entityType): string
    {
        return EntityType::from($entityType)->historyTable();
    }

    /**
     * Get foreign key name from entity type
     *
     * Protected: also used by EntityConversionTrait
     *
     * @param string $entityType Entity type (ticket, pqrs, compra)
     * @return string Foreign key name
     */
    protected function getForeignKeyName(string $entityType): string
    {
        return EntityType::from($entityType)->foreignKey();
    }

    /**
     * Mark entity as converted to another entity type
     *
     * Generic method to handle conversion workflow:
     * 1. Update source entity status to 'convertido'
     * 2. Set resolved_at timestamp
     * 3. Save entity
     * 4. Add internal system comment
     * 5. Log to history
     *
     * @param string $sourceType Source entity type ('ticket' or 'compra')
     * @param \Cake\Datasource\EntityInterface $sourceEntity Source entity being converted
     * @param string $targetType Target entity type ('ticket' or 'compra')
     * @param \Cake\Datasource\EntityInterface $targetEntity Newly created target entity
     * @param int $userId User performing the conversion
     * @return void
     */
    protected function markAsConverted(
        string $sourceType,
        \Cake\Datasource\EntityInterface $sourceEntity,
        string $targetType,
        \Cake\Datasource\EntityInterface $targetEntity,
        int $userId
    ): void {
        $sourceTableName = $this->getEntityTableName($sourceType);
        $sourceTable = $this->fetchTable($sourceTableName);

        // Update source entity status
        $sourceEntity->status = 'convertido';
        $sourceEntity->resolved_at = new \Cake\I18n\DateTime();
        $sourceTable->save($sourceEntity);

        // Get entity numbers for messages
        $sourceNumber = $this->getEntityNumber($sourceType, $sourceEntity);
        $targetNumber = $this->getEntityNumber($targetType, $targetEntity);

        // Get readable type names
        $sourceTypeName = ucfirst($sourceType);
        $targetTypeName = ucfirst($targetType);

        // Add internal system comment
        $this->addComment(
            $sourceEntity->id,
            $userId,
            "{$sourceTypeName} convertido a {$targetTypeName} #{$targetNumber}",
            $sourceType, // entityType
            'internal',
            true        // isSystem
        );

        // Log to history
        $historyTable = $this->getHistoryTableName($sourceType);
        $foreignKey = $this->getForeignKeyName($sourceType);

        $this->logHistory(
            $historyTable,
            $foreignKey,
            $sourceEntity->id,
            "converted_to_{$targetType}",
            null,
            $targetNumber,
            $userId,
            "Convertido a {$targetTypeName} #{$targetNumber}"
        );
    }

    /**
     * NOTE: getEntityNumber() method is provided by GenericAttachmentTrait
     * to avoid duplication.
     */

    /**
     * Send notifications based on response changes (comment + status + files)
     *
     * Requires NotificationDispatcherTrait to be used by the implementing class.
     *
     * @param string $type Entity type ('ticket', 'pqrs', 'compra')
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @param \Cake\Datasource\EntityInterface|null $comment Comment entity
     * @param string $oldStatus Old status
     * @param string|null $newStatus New status
     * @param bool $hasComment Whether a comment was added
     * @param string $commentType Comment type (public/internal)
     * @param bool $hasStatusChange Whether status changed
     * @param array $emailTo Additional TO recipients
     * @param array $emailCc Additional CC recipients
     * @return void
     */
    protected function sendResponseNotifications(
        string $type,
        $entity,
        $comment,
        string $oldStatus,
        ?string $newStatus,
        bool $hasComment,
        string $commentType,
        bool $hasStatusChange,
        array $emailTo = [],
        array $emailCc = []
    ): void {
        $hasPublicComment = $hasComment && $commentType === 'public';

        if ($hasPublicComment && $hasStatusChange && $comment) {
            $this->dispatchUpdateNotifications($type, $entity, 'response', [
                'comment' => $comment,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'additional_to' => $emailTo,
                'additional_cc' => $emailCc,
            ]);
        } elseif ($hasPublicComment && $comment) {
            $this->dispatchUpdateNotifications($type, $entity, 'comment', [
                'comment' => $comment,
                'additional_to' => $emailTo,
                'additional_cc' => $emailCc,
            ]);
        } elseif ($hasStatusChange) {
            $this->dispatchUpdateNotifications($type, $entity, 'status_change', [
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]);
        }
    }

    /**
     * Build success message for response operations
     *
     * @param bool $hasComment Whether a comment was added
     * @param bool $hasStatusChange Whether status changed
     * @param int $uploadedCount Number of files uploaded
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @return array{success: bool, message: string, entity: \Cake\Datasource\EntityInterface}
     */
    protected function buildResponseResult(bool $hasComment, bool $hasStatusChange, int $uploadedCount, $entity): array
    {
        $successMessage = '';
        if ($hasComment && $hasStatusChange) {
            $successMessage = 'Comentario agregado y estado actualizado exitosamente.';
        } elseif ($hasComment) {
            $successMessage = 'Comentario agregado exitosamente.';
        } elseif ($hasStatusChange) {
            $successMessage = 'Estado actualizado exitosamente.';
        }

        if ($uploadedCount > 0) {
            $successMessage .= " ({$uploadedCount} archivo(s) adjunto(s))";
        }

        return [
            'success' => true,
            'message' => $successMessage,
            'entity' => $entity,
        ];
    }

    /**
     * Decode email recipients from JSON string or array
     *
     * @param mixed $data Email recipients as JSON string or array
     * @return array Decoded recipients array
     */
    protected function decodeEmailRecipients($data): array
    {
        if (empty($data)) {
            return [];
        }

        if (is_string($data)) {
            $decoded = json_decode($data, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        if (is_array($data)) {
            return $data;
        }

        return [];
    }
}
