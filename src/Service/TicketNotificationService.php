<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\TicketConstants;
use App\Service\Dto\SystemConfig;
use Cake\Datasource\EntityInterface;
use Cake\Log\Log;
use Exception;

/**
 * Dispatches outbound notifications (email, WhatsApp, n8n) for ticket events.
 * Centralizes notification logic previously embedded in TicketService.
 */
class TicketNotificationService
{
    private EmailService $emailService;
    private WhatsappService $whatsappService;
    private ?N8nService $n8nService;
    private SystemConfig $config;

    /**
     * @param \App\Service\Dto\SystemConfig|null $config System configuration VO
     * @param \App\Service\EmailService|null $emailService Optional injected email service
     * @param \App\Service\WhatsappService|null $whatsappService Optional injected WhatsApp service
     * @param \App\Service\N8nService|null $n8nService Optional injected n8n service (lazy default)
     */
    public function __construct(
        ?SystemConfig $config = null,
        ?EmailService $emailService = null,
        ?WhatsappService $whatsappService = null,
        ?N8nService $n8nService = null,
    ) {
        $this->config = $config ?? SystemConfig::empty();
        $this->emailService = $emailService ?? new EmailService($this->config);
        $this->whatsappService = $whatsappService ?? new WhatsappService($this->config);
        $this->n8nService = $n8nService;
    }

    /**
     * Lazy-load N8nService.
     *
     * @return \App\Service\N8nService
     */
    public function getN8nService(): N8nService
    {
        if ($this->n8nService === null) {
            $this->n8nService = new N8nService($this->config);
        }

        return $this->n8nService;
    }

    /**
     * Dispatch creation notifications (Email + WhatsApp).
     *
     * @param \Cake\Datasource\EntityInterface $entity Ticket entity
     * @param bool $sendEmail Whether to send email
     * @param bool $sendWhatsapp Whether to send WhatsApp
     * @return void
     */
    public function dispatchCreationNotifications(
        EntityInterface $entity,
        bool $sendEmail = true,
        bool $sendWhatsapp = true,
    ): void {
        if ($sendEmail) {
            try {
                $this->emailService->sendNewEntityNotification($entity);
            } catch (Exception $e) {
                Log::error('Failed to send ticket creation email', [
                    'error' => $e->getMessage(),
                    'entity_id' => $entity->id,
                ]);
            }
        }

        if ($sendWhatsapp) {
            try {
                $this->whatsappService->sendNewEntityNotification($entity);
            } catch (Exception $e) {
                Log::error('Failed to send ticket creation WhatsApp', [
                    'error' => $e->getMessage(),
                    'entity_id' => $entity->id,
                ]);
            }
        }
    }

    /**
     * Dispatch update notifications (Email only).
     *
     * @param \Cake\Datasource\EntityInterface $entity Ticket entity
     * @param string $notificationType 'status_change', 'comment', 'response', 'assignment'
     * @param array $context Additional context (old_status, new_status, comment,
     *                       new_assignee_id, actor_id, etc.)
     * @return void
     */
    public function dispatchUpdateNotifications(
        EntityInterface $entity,
        string $notificationType,
        array $context = [],
    ): void {
        try {
            switch ($notificationType) {
                case 'status_change':
                    $this->emailService->sendEntityStatusChangeNotification(
                        $entity,
                        $context['old_status'] ?? '',
                        $context['new_status'] ?? '',
                    );
                    break;

                case 'comment':
                    $this->emailService->sendEntityCommentNotification(
                        $entity,
                        $context['comment'] ?? null,
                        $context['additional_to'] ?? [],
                        $context['additional_cc'] ?? [],
                    );
                    break;

                case 'response':
                    $this->emailService->sendEntityResponseNotification(
                        $entity,
                        $context['comment'] ?? null,
                        $context['old_status'] ?? '',
                        $context['new_status'] ?? '',
                        $context['additional_to'] ?? [],
                        $context['additional_cc'] ?? [],
                    );
                    break;

                case 'assignment':
                    $newAssigneeId = $context['new_assignee_id'] ?? null;
                    $actorId = $context['actor_id'] ?? null;
                    // No assignee to notify (unassign), or actor self-assigned:
                    // skip silently — we don't email an agent about their own action.
                    if ($newAssigneeId === null || $newAssigneeId === $actorId) {
                        break;
                    }
                    $this->emailService->sendEntityAssignmentNotification($entity);
                    break;

                default:
                    Log::warning("Unknown notification type: {$notificationType}");
            }
        } catch (Exception $e) {
            Log::error("Failed to send ticket {$notificationType} email", [
                'error' => $e->getMessage(),
                'entity_id' => $entity->id,
            ]);
        }
    }

    /**
     * Send notifications based on response changes (comment + status + files).
     *
     * @param mixed $entity Ticket entity
     * @param mixed $comment Comment entity or null
     * @param string $oldStatus Previous status
     * @param string|null $newStatus New status
     * @param bool $hasComment Whether a comment was added
     * @param string $commentType Comment type
     * @param bool $hasStatusChange Whether status changed
     * @param array $emailTo Additional To recipients
     * @param array $emailCc Additional Cc recipients
     * @return void
     */
    public function sendResponseNotifications(
        mixed $entity,
        mixed $comment,
        string $oldStatus,
        ?string $newStatus,
        bool $hasComment,
        string $commentType,
        bool $hasStatusChange,
        array $emailTo = [],
        array $emailCc = [],
    ): void {
        $hasPublicComment = $hasComment && $commentType === TicketConstants::COMMENT_PUBLIC;

        if ($hasPublicComment && $hasStatusChange && $comment) {
            $this->dispatchUpdateNotifications($entity, 'response', [
                'comment' => $comment,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'additional_to' => $emailTo,
                'additional_cc' => $emailCc,
            ]);
        } elseif ($hasPublicComment && $comment) {
            $this->dispatchUpdateNotifications($entity, 'comment', [
                'comment' => $comment,
                'additional_to' => $emailTo,
                'additional_cc' => $emailCc,
            ]);
        } elseif ($hasStatusChange) {
            $this->dispatchUpdateNotifications($entity, 'status_change', [
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]);
        }
    }
}
