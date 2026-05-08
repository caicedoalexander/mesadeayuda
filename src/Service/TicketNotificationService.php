<?php
declare(strict_types=1);

namespace App\Service;

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
    private ?array $systemConfig;

    /**
     * @param array|null $systemConfig System settings snapshot
     * @param \App\Service\EmailService|null $emailService Optional injected email service
     * @param \App\Service\WhatsappService|null $whatsappService Optional injected WhatsApp service
     * @param \App\Service\N8nService|null $n8nService Optional injected n8n service (lazy default)
     */
    public function __construct(
        ?array $systemConfig = null,
        ?EmailService $emailService = null,
        ?WhatsappService $whatsappService = null,
        ?N8nService $n8nService = null,
    ) {
        $this->systemConfig = $systemConfig;
        $this->emailService = $emailService ?? new EmailService($systemConfig);
        $this->whatsappService = $whatsappService ?? new WhatsappService($systemConfig);
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
            $this->n8nService = new N8nService($this->systemConfig);
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
     * @param string $notificationType 'status_change', 'comment', 'response'
     * @param array $context Additional context (old_status, new_status, comment, etc.)
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
        $hasPublicComment = $hasComment && $commentType === 'public';

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

    /**
     * Send a status-change email notification with try/catch logging.
     *
     * @param \Cake\Datasource\EntityInterface $entity Ticket entity
     * @param string $oldStatus Previous status
     * @param string $newStatus New status
     * @return void
     */
    public function sendStatusChangeEmail(EntityInterface $entity, string $oldStatus, string $newStatus): void
    {
        try {
            $this->emailService->sendEntityStatusChangeNotification($entity, $oldStatus, $newStatus);
        } catch (Exception $e) {
            Log::error('Failed to send status change email notification: ' . $e->getMessage());
        }
    }
}
