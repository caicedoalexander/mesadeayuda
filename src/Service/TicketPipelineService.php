<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\TicketConstants;
use App\Domain\Event\TicketAssigned;
use App\Domain\Event\TicketCommentAdded;
use App\Domain\Event\TicketResponded;
use App\Domain\Event\TicketStatusChanged;
use App\Model\Entity\Ticket;
use App\Model\Entity\User;
use App\Service\Dto\SystemConfig;
use App\Service\Exception\InvalidStatusTransitionException;
use App\Service\Exception\UnauthorizedAssignmentException;
use App\Service\Traits\TicketHistoryLoggerTrait;
use Authentication\IdentityInterface;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventManager;
use Cake\Event\EventManagerInterface;
use Cake\I18n\FrozenTime;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Orchestrates ticket pipeline operations: status transitions, assignment,
 * priority changes, tags, followers, and the combined handleResponse flow
 * (comment + status + uploads + notifications).
 */
class TicketPipelineService
{
    use LocatorAwareTrait;
    use TicketHistoryLoggerTrait;

    /**
     * UX string returned by addTag() when the (ticket_id, tag_id) pair
     * already exists. Public so callers (e.g., WebhooksController,
     * TicketActionsTrait) can compare against it without locale coupling.
     */
    public const MESSAGE_TAG_ALREADY_ADDED = 'Esta etiqueta ya está agregada.';

    private TicketCommentService $comments;
    private TicketAttachmentService $attachments;
    private AuthorizationService $authService;
    private SystemConfig $config;
    private EventManagerInterface $eventManager;

    /**
     * @param \App\Service\Dto\SystemConfig|null $config System configuration VO
     * @param \App\Service\TicketCommentService|null $comments Optional injected comment service
     * @param \App\Service\TicketAttachmentService|null $attachments Optional injected attachment service
     * @param \App\Service\AuthorizationService|null $authService Optional injected authorization service
     * @param \Cake\Event\EventManagerInterface|null $eventManager Optional injected event manager
     */
    public function __construct(
        ?SystemConfig $config = null,
        ?TicketCommentService $comments = null,
        ?TicketAttachmentService $attachments = null,
        ?AuthorizationService $authService = null,
        ?EventManagerInterface $eventManager = null,
    ) {
        $this->config = $config ?? SystemConfig::empty();
        $this->comments = $comments ?? new TicketCommentService($this->config);
        $this->attachments = $attachments ?? new TicketAttachmentService();
        $this->authService = $authService ?? new AuthorizationService();
        $this->eventManager = $eventManager ?? EventManager::instance();
    }

    /**
     * Handle a complete response (comment + status change + files + notifications).
     *
     * @param int $entityId Ticket ID
     * @param int $userId User performing the response
     * @param array $data Request data (comment_body, comment_type, status, email_to, email_cc)
     * @param array $files Uploaded files
     * @return array Result with 'success' (bool), 'message' (string), 'entity' (mixed)
     */
    public function handleResponse(int $entityId, int $userId, array $data, array $files): array
    {
        $commentBody = $data['comment_body'] ?? $data['body'] ?? '';
        $commentType = $data['comment_type'] ?? TicketConstants::COMMENT_PUBLIC;
        $newStatus = $data['status'] ?? null;

        $emailTo = $this->decodeEmailRecipients($data['email_to'] ?? null);
        $emailCc = $this->decodeEmailRecipients($data['email_cc'] ?? null);

        Log::debug('Response email recipients', [
            'raw_email_to' => $data['email_to'] ?? null,
            'raw_email_cc' => $data['email_cc'] ?? null,
            'decoded_email_to' => $emailTo,
            'decoded_email_cc' => $emailCc,
        ]);

        $hasComment = !empty(trim($commentBody));

        $entity = $this->fetchTable('Tickets')->get($entityId);
        assert($entity instanceof Ticket);

        $oldStatus = $entity->status;
        $hasStatusChange = $newStatus && $newStatus !== $oldStatus;

        if (!$hasComment && !$hasStatusChange) {
            return [
                'success' => false,
                'message' => 'Debes escribir un comentario o cambiar el estado.',
                'entity' => $entity,
            ];
        }

        $connection = $this->fetchTable('Tickets')->getConnection();
        $writtenFilePaths = [];
        $pendingEvents = [];
        $comment = null;
        $uploadedCount = 0;

        // TX1: comment + uploads. On rollback (callback returns false OR exception),
        // best-effort unlink any attachment files already moved to disk.
        if ($hasComment) {
            $tx1Ok = false;
            try {
                $tx1Ok = $connection->transactional(function () use (
                    $entityId,
                    $userId,
                    $commentBody,
                    $commentType,
                    $emailTo,
                    $emailCc,
                    $files,
                    $entity,
                    &$comment,
                    &$uploadedCount,
                    &$writtenFilePaths,
                ): bool {
                    $comment = $this->comments->addComment(
                        $entityId,
                        $userId,
                        $commentBody,
                        $commentType,
                        false,
                        $emailTo,
                        $emailCc,
                    );

                    if (!$comment) {
                        return false;
                    }

                    if (!empty($files['attachments'])) {
                        foreach ($files['attachments'] as $file) {
                            if ($file->getError() !== UPLOAD_ERR_OK) {
                                continue;
                            }
                            $attachment = $this->attachments->saveUploadedFile($entity, $file, $comment->id, $userId);
                            if ($attachment !== null) {
                                $writtenFilePaths[] = $attachment->file_path;
                                $uploadedCount++;
                            }
                        }
                    }

                    return true;
                });
            } finally {
                if ($tx1Ok !== true) {
                    $this->cleanupOrphanedFiles($writtenFilePaths);
                }
            }

            if ($tx1Ok !== true) {
                return [
                    'success' => false,
                    'message' => 'Error al agregar el comentario.',
                    'entity' => $entity,
                ];
            }
        }

        // TX2: status change. The TicketStatusChanged event is only buffered when
        // there's NO public comment — when there is, TicketResponded covers both
        // effects and TicketStatusChanged would duplicate the email.
        $hasPublicComment = $hasComment && $commentType === TicketConstants::COMMENT_PUBLIC && $comment !== null;
        $emitTicketResponded = $hasPublicComment && $hasStatusChange;

        if ($hasStatusChange) {
            $tx2Ok = false;
            try {
                $tx2Ok = $connection->transactional(function () use (
                    $entity,
                    $newStatus,
                    $oldStatus,
                    $userId,
                    $emitTicketResponded,
                    $comment,
                    &$pendingEvents,
                ): bool {
                    $statusCommentId = null;
                    $ok = $this->changeStatus(
                        $entity,
                        $newStatus,
                        $userId,
                        null,
                        true,
                        deferDispatch: true,
                        outSystemCommentId: $statusCommentId,
                    );
                    if (!$ok) {
                        return false;
                    }

                    if ($emitTicketResponded) {
                        $pendingEvents[] = new TicketResponded(
                            ticketId: (int)$entity->id,
                            commentId: (int)$comment->id,
                            oldStatus: $oldStatus,
                            newStatus: (string)$newStatus,
                            actorId: $userId,
                        );
                    } else {
                        $pendingEvents[] = new TicketStatusChanged(
                            ticketId: (int)$entity->id,
                            oldStatus: $oldStatus,
                            newStatus: (string)$newStatus,
                            actorId: $userId,
                            systemCommentId: $statusCommentId,
                        );
                    }

                    return true;
                });
            } catch (InvalidStatusTransitionException $e) {
                Log::warning('Response committed but status transition rejected', [
                    'ticket_id' => $entityId,
                    'from' => $oldStatus,
                    'to' => $newStatus,
                    'error' => $e->getMessage(),
                ]);

                return [
                    'success' => false,
                    'message' => sprintf(
                        'Comentario guardado, pero no se pudo cambiar el estado: %s',
                        $e->getMessage(),
                    ),
                    'entity' => $entity,
                ];
            }

            // Non-exception rollback path: the callback returned false (e.g.,
            // $table->save() returned false inside changeStatus()), so
            // transactional() rolled back silently without throwing. The
            // InvalidStatusTransitionException catch above does not cover this
            // path. Without this guard, $pendingEvents may still hold the
            // status-change event (appended before the early `return false`
            // could not have run, but defensive clear is cheap) and the final
            // buildResponseResult() would report success for a state change
            // that never committed. Surface a partial-success message so the
            // user knows TX1 (comment) committed but TX2 (status) did not.
            //
            // Note: TicketCommentAdded is intentionally NOT emitted here even
            // when there's a public comment. Emitting an email to the customer
            // while the agent's intended status transition silently failed
            // would leave the conversation in an inconsistent state from the
            // agent's perspective. The comment is persisted (TX1 committed)
            // and the agent can retry the status change.
            if ($tx2Ok !== true) {
                Log::warning('Response committed but status save returned false', [
                    'ticket_id' => $entityId,
                    'from' => $oldStatus,
                    'to' => $newStatus,
                ]);

                $pendingEvents = [];

                return [
                    'success' => false,
                    'message' => 'Comentario guardado, pero no se pudo cambiar el estado.',
                    'entity' => $entity,
                ];
            }
        }

        // Public-comment-only branch (no status change).
        if ($hasPublicComment && !$hasStatusChange) {
            $pendingEvents[] = new TicketCommentAdded(
                ticketId: (int)$entity->id,
                commentId: (int)$comment->id,
                actorId: $userId,
                isPublic: true,
            );
        }

        // Post-commit: dispatch buffered domain events. Notification routing is
        // fully delegated to the EventManager — no direct call to the service.
        foreach ($pendingEvents as $event) {
            $this->eventManager->dispatch($event);
        }

        return $this->buildResponseResult($hasComment, $hasStatusChange, $uploadedCount, $entity);
    }

    /**
     * Change ticket status.
     *
     * @param \Cake\Datasource\EntityInterface $entity Ticket entity
     * @param string $newStatus New status
     * @param int|null $userId User performing the change
     * @param string|null $comment Optional override comment for the system entry
     * @param bool $sendNotifications Whether to dispatch the email notification
     * @param bool $deferDispatch When true, suppresses inline event dispatch even if
     *        $sendNotifications is true. Used by callers (e.g., handleResponse) that
     *        wrap this call in a transaction and need to dispatch post-commit.
     * @param int|null $outSystemCommentId Out parameter populated with the id of the
     *        internal system_comment recording the transition. Callers that buffer
     *        the TicketStatusChanged event for post-commit dispatch use this to
     *        anchor the outbound Message-ID against that comment (MEN-1).
     * @return bool
     */
    public function changeStatus(
        EntityInterface $entity,
        string $newStatus,
        ?int $userId = null,
        ?string $comment = null,
        bool $sendNotifications = true,
        bool $deferDispatch = false,
        ?int &$outSystemCommentId = null,
    ): bool {
        $table = $this->fetchTable('Tickets');
        $oldStatus = $entity->status;

        if ($oldStatus === $newStatus) {
            return true;
        }

        // Domain-level guard + mutation — Ticket::transitionTo() asserts the
        // transition is legal and applies it through the entity's setter,
        // so services can't drift away from the state machine.
        if ($entity instanceof Ticket) {
            $entity->transitionTo($newStatus);
        } else {
            $entity->status = $newStatus;
        }

        $now = FrozenTime::now();
        if ($newStatus === TicketConstants::STATUS_RESUELTO && !$entity->resolved_at) {
            $entity->resolved_at = $now;
        }

        if (!$table->save($entity)) {
            Log::error('Failed to change status', ['errors' => $entity->getErrors()]);

            return false;
        }

        $this->logHistory(
            'TicketHistory',
            'ticket_id',
            $entity->id,
            'status',
            $oldStatus,
            $newStatus,
            $userId,
            "Estado cambiado de '{$oldStatus}' a '{$newStatus}'",
        );

        $systemComment = $comment ?? "El estado cambió de '{$oldStatus}' a '{$newStatus}'";
        $systemCommentEntity = $this->comments->addComment(
            $entity->id,
            $userId,
            $systemComment,
            TicketConstants::COMMENT_INTERNAL,
            true,
        );
        if ($systemCommentEntity !== null) {
            $outSystemCommentId = (int)$systemCommentEntity->id;
        }

        if ($sendNotifications && !$deferDispatch) {
            $this->eventManager->dispatch(new TicketStatusChanged(
                ticketId: (int)$entity->id,
                oldStatus: $oldStatus,
                newStatus: $newStatus,
                actorId: $userId,
                systemCommentId: $outSystemCommentId,
            ));
        }

        return true;
    }

    /**
     * Assign ticket to a user.
     *
     * @param \Cake\Datasource\EntityInterface $entity Ticket entity
     * @param int|null $assigneeId New assignee user ID (0 or null clears)
     * @param int|null $userId User performing the change (for history)
     * @param \Authentication\IdentityInterface|null $actor Authenticated identity performing the assignment
     * @return bool
     * @throws \App\Service\Exception\UnauthorizedAssignmentException When actor lacks role or target is invalid
     */
    public function assign(
        EntityInterface $entity,
        ?int $assigneeId,
        ?int $userId = null,
        ?IdentityInterface $actor = null,
    ): bool {
        $table = $this->fetchTable('Tickets');
        $usersTable = $this->fetchTable('Users');

        // Guard 1: actor must be allowed to assign tickets
        if ($actor !== null && $this->authService->isAssignmentDisabled($actor)) {
            throw new UnauthorizedAssignmentException(
                'El usuario no tiene permisos para asignar tickets.',
            );
        }

        // Guard 2: target must be a valid assignee for this ticket (only when assigning, not clearing)
        $normalizedAssigneeId = $assigneeId === 0 || $assigneeId === '0' ? null : $assigneeId;
        $targetUser = null;
        if ($normalizedAssigneeId !== null) {
            $targetUser = $usersTable->get($normalizedAssigneeId);
            assert($targetUser instanceof User);
            if (!$entity->canBeAssignedTo($targetUser)) {
                throw new UnauthorizedAssignmentException(
                    'No es posible asignar este ticket a ese usuario.',
                );
            }
        }

        $oldAssigneeId = $entity->assignee_id;
        $entity->assignee_id = $normalizedAssigneeId;

        if (!$table->save($entity)) {
            $errors = $entity->getErrors();
            Log::error("Failed to assign ticket - ID: {$entity->id}");
            Log::error("Assignment details - New assignee: {$assigneeId}, Old assignee: {$oldAssigneeId}");
            Log::error('Validation errors: ' . print_r($errors, true));
            Log::error('Dirty fields: ' . print_r($entity->getDirty(), true));

            return false;
        }

        $oldAssigneeName = 'Sin asignar';
        if ($oldAssigneeId) {
            $oldUser = $usersTable->get($oldAssigneeId);
            $oldAssigneeName = $oldUser->first_name . ' ' . $oldUser->last_name;
        }

        // Reuse the user already fetched by the canBeAssignedTo guard.
        $newAssigneeName = $targetUser !== null
            ? $targetUser->first_name . ' ' . $targetUser->last_name
            : 'Sin asignar';

        $this->logHistory(
            'TicketHistory',
            'ticket_id',
            $entity->id,
            'assignee_id',
            $oldAssigneeName,
            $newAssigneeName,
            $userId,
            "Asignado a {$newAssigneeName}",
        );

        $this->comments->addComment($entity->id, $userId, "Asignado a {$newAssigneeName}", TicketConstants::COMMENT_INTERNAL, true);

        $this->eventManager->dispatch(new TicketAssigned(
            ticketId: (int)$entity->id,
            assigneeId: $normalizedAssigneeId,
            previousAssigneeId: $oldAssigneeId,
            actorId: $userId,
        ));

        return true;
    }

    /**
     * Change ticket priority.
     *
     * @param \Cake\Datasource\EntityInterface $entity Ticket entity
     * @param string $newPriority New priority
     * @param int|null $userId User performing the change
     * @return bool
     */
    public function changePriority(
        EntityInterface $entity,
        string $newPriority,
        ?int $userId = null,
    ): bool {
        $table = $this->fetchTable('Tickets');
        $oldPriority = $entity->priority;

        if ($oldPriority === $newPriority) {
            return true;
        }

        $entity->priority = $newPriority;

        if (!$table->save($entity)) {
            Log::error('Failed to change priority', ['errors' => $entity->getErrors()]);

            return false;
        }

        $this->logHistory(
            'TicketHistory',
            'ticket_id',
            $entity->id,
            'priority',
            $oldPriority,
            $newPriority,
            $userId,
            "Prioridad cambiada de '{$oldPriority}' a '{$newPriority}'",
        );

        $this->comments->addComment(
            $entity->id,
            $userId,
            "Prioridad cambiada de '{$oldPriority}' a '{$newPriority}'",
            TicketConstants::COMMENT_INTERNAL,
            true,
        );

        return true;
    }

    /**
     * Add tag to ticket.
     *
     * @param int $ticketId Ticket ID
     * @param int $tagId Tag ID
     * @return array{success: bool, message: string}
     */
    public function addTag(int $ticketId, int $tagId): array
    {
        $ticketsTable = $this->fetchTable('Tickets');
        $ticketsTable->get($ticketId);

        $ticketTagsTable = $this->fetchTable('TicketTags');

        $exists = $ticketTagsTable->find()
            ->where(['ticket_id' => $ticketId, 'tag_id' => $tagId])
            ->count();

        if ($exists) {
            return ['success' => false, 'message' => self::MESSAGE_TAG_ALREADY_ADDED];
        }

        $ticketTag = $ticketTagsTable->newEntity([
            'ticket_id' => $ticketId,
            'tag_id' => $tagId,
        ]);

        if ($ticketTagsTable->save($ticketTag)) {
            return ['success' => true, 'message' => 'Etiqueta agregada.'];
        }

        // Race fallback: a concurrent request may have inserted the same
        // (ticket_id, tag_id) pair between our existence check and this save.
        // The isUnique rule on TicketTagsTable surfaces it as a validation
        // error; treat as the same "already added" outcome rather than a
        // real failure.
        $errors = $ticketTag->getErrors();
        if (isset($errors['ticket_id']['_isUnique']) || isset($errors['tag_id']['_isUnique'])) {
            return ['success' => false, 'message' => self::MESSAGE_TAG_ALREADY_ADDED];
        }

        return ['success' => false, 'message' => 'Error al agregar la etiqueta.'];
    }

    /**
     * Remove tag from ticket.
     *
     * @param int $ticketId Ticket ID
     * @param int $tagId Tag ID
     * @return array{success: bool, message: string}
     */
    public function removeTag(int $ticketId, int $tagId): array
    {
        $ticketTagsTable = $this->fetchTable('TicketTags');

        $ticketTag = $ticketTagsTable->find()
            ->where(['ticket_id' => $ticketId, 'tag_id' => $tagId])
            ->first();

        if ($ticketTag && $ticketTagsTable->delete($ticketTag)) {
            return ['success' => true, 'message' => 'Etiqueta eliminada.'];
        }

        return ['success' => false, 'message' => 'Error al eliminar la etiqueta.'];
    }

    /**
     * Add follower to ticket.
     *
     * @param int $ticketId Ticket ID
     * @param int $userId User ID
     * @return array{success: bool, message: string}
     */
    public function addFollower(int $ticketId, int $userId): array
    {
        $followersTable = $this->fetchTable('TicketFollowers');

        $exists = $followersTable->find()
            ->where(['ticket_id' => $ticketId, 'user_id' => $userId])
            ->count();

        if ($exists) {
            return ['success' => false, 'message' => 'Este usuario ya está siguiendo el ticket.'];
        }

        $follower = $followersTable->newEntity([
            'ticket_id' => $ticketId,
            'user_id' => $userId,
        ]);

        if ($followersTable->save($follower)) {
            return ['success' => true, 'message' => 'Seguidor agregado.'];
        }

        return ['success' => false, 'message' => 'Error al agregar seguidor.'];
    }

    /**
     * Build success message for response operations.
     *
     * @param bool $hasComment Whether a comment was added
     * @param bool $hasStatusChange Whether status changed
     * @param int $uploadedCount Number of attachments uploaded
     * @param mixed $entity Ticket entity
     * @return array
     */
    private function buildResponseResult(bool $hasComment, bool $hasStatusChange, int $uploadedCount, mixed $entity): array
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
     * Best-effort removal of attachment files that were written to disk during
     * a transaction that subsequently rolled back. Failures are logged but never
     * propagated — the caller's primary error is more important than cleanup.
     *
     * @param array<int, string> $relativePaths Relative paths as stored in attachments.file_path
     *        (e.g., "uploads/attachments/T-0001/uuid.pdf"). Resolved against WWW_ROOT.
     */
    private function cleanupOrphanedFiles(array $relativePaths): void
    {
        foreach ($relativePaths as $relativePath) {
            $absolute = WWW_ROOT . $relativePath;
            if (!file_exists($absolute)) {
                continue;
            }
            if (@unlink($absolute) === false) {
                Log::warning('Failed to cleanup orphaned attachment after TX rollback', [
                    'path' => $absolute,
                ]);
            }
        }
    }

    /**
     * Decode email recipients from JSON string or array.
     *
     * @param mixed $data Raw recipients value
     * @return array
     */
    private function decodeEmailRecipients(mixed $data): array
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
