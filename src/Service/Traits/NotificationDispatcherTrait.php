<?php
declare(strict_types=1);

namespace App\Service\Traits;

use Cake\Datasource\EntityInterface;
use Cake\Log\Log;

/**
 * NotificationDispatcherTrait
 *
 * Centralizes notification dispatch logic:
 * - Email: Creation, status changes, comments, responses
 * - WhatsApp: ONLY on entity creation (via dispatchCreationNotifications)
 *
 * Requires using class to have:
 * - emailService property (EmailService instance)
 * - whatsappService property (WhatsappService instance) - only needed for creation notifications
 */
trait NotificationDispatcherTrait
{
    /**
     * Dispatch creation notifications (Email + WhatsApp)
     *
     * WhatsApp is ONLY sent for entity creation events
     *
     * @param string $entityType 'ticket', 'pqrs', 'compra'
     * @param EntityInterface $entity Entity instance
     * @param bool $sendEmail Send email notification
     * @param bool $sendWhatsapp Send WhatsApp notification
     * @return void
     */
    public function dispatchCreationNotifications(
        string $entityType,
        EntityInterface $entity,
        bool $sendEmail = true,
        bool $sendWhatsapp = true
    ): void {
        // Send Email
        if ($sendEmail) {
            try {
                $this->emailService->sendNewEntityNotification($entityType, $entity);
            } catch (\Exception $e) {
                Log::error("Failed to send {$entityType} creation email", [
                    'error' => $e->getMessage(),
                    'entity_id' => $entity->id,
                ]);
            }
        }

        // Send WhatsApp (ONLY for creation)
        if ($sendWhatsapp) {
            try {
                $this->whatsappService->sendNewEntityNotification($entityType, $entity);
            } catch (\Exception $e) {
                Log::error("Failed to send {$entityType} creation WhatsApp", [
                    'error' => $e->getMessage(),
                    'entity_id' => $entity->id,
                ]);
            }
        }
    }

    /**
     * Dispatch update notifications (Email ONLY)
     *
     * Uses generic EmailService methods that accept entityType parameter.
     *
     * @param string $entityType 'ticket', 'pqrs', 'compra'
     * @param EntityInterface $entity Entity instance
     * @param string $notificationType 'status_change', 'comment', 'response'
     * @param array $context Additional context (old_status, new_status, comment, etc.)
     * @return void
     */
    public function dispatchUpdateNotifications(
        string $entityType,
        EntityInterface $entity,
        string $notificationType,
        array $context = []
    ): void {
        try {
            switch ($notificationType) {
                case 'status_change':
                    $this->emailService->sendEntityStatusChangeNotification(
                        $entityType,
                        $entity,
                        $context['old_status'] ?? '',
                        $context['new_status'] ?? ''
                    );
                    break;

                case 'comment':
                    $this->emailService->sendEntityCommentNotification(
                        $entityType,
                        $entity,
                        $context['comment'] ?? null,
                        $context['additional_to'] ?? [],
                        $context['additional_cc'] ?? []
                    );
                    break;

                case 'response':
                    $this->emailService->sendEntityResponseNotification(
                        $entityType,
                        $entity,
                        $context['comment'] ?? null,
                        $context['old_status'] ?? '',
                        $context['new_status'] ?? '',
                        $context['additional_to'] ?? [],
                        $context['additional_cc'] ?? []
                    );
                    break;

                default:
                    Log::warning("Unknown notification type: {$notificationType}");
            }
        } catch (\Exception $e) {
            Log::error("Failed to send {$entityType} {$notificationType} email", [
                'error' => $e->getMessage(),
                'entity_id' => $entity->id,
            ]);
        }
    }
}
