<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\Locator\LocatorAwareTrait;
use App\Service\EmailService;
use App\Service\WhatsappService;

/**
 * PQRS Service
 *
 * Handles PQRS (Peticiones, Quejas, Reclamos, Sugerencias) business logic:
 * - Creation from public form
 * - Status changes
 * - Comments
 * - Assignments
 * - Priority changes
 * - Attachments
 * - Notifications (Email + WhatsApp)
 */
class PqrsService
{
    use LocatorAwareTrait;
    use \App\Service\Traits\TicketSystemTrait;
    use \App\Service\Traits\NotificationDispatcherTrait;
    use \App\Service\Traits\GenericAttachmentTrait;
    use \App\Service\Traits\SlaAwareTrait;

    private EmailService $emailService;
    private WhatsappService $whatsappService;
    private SlaManagementService $slaService;

    /**
     * Constructor
     *
     * @param array|null $systemConfig Optional system configuration to avoid redundant DB queries
     */
    public function __construct(?array $systemConfig = null)
    {
        $this->emailService = new EmailService($systemConfig);
        $this->whatsappService = new WhatsappService($systemConfig);
        $this->slaService = new SlaManagementService();
    }

    /**
     * Create PQRS from public form submission
     *
     * @param array $formData Form data
     * @param array $files Uploaded files
     * @return \App\Model\Entity\Pqr|null Created PQRS or null on failure
     */
    public function createFromForm(array $formData, array $files = []): ?\App\Model\Entity\Pqr
    {
        $pqrsTable = $this->fetchTable('Pqrs');

        // Generate PQRS number
        $pqrsNumber = $pqrsTable->generatePqrsNumber();

        // Determine PQRS type
        $type = $formData['type'] ?? 'peticion';

        // Calculate SLA deadlines based on type
        $slaDeadlines = $this->slaService->calculatePqrsSlaDeadlines($type);

        // Create PQRS entity
        $pqrs = $pqrsTable->newEntity([
            'pqrs_number' => $pqrsNumber,
            'requester_name' => $formData['requester_name'] ?? '',
            'requester_email' => $formData['requester_email'] ?? '',
            'requester_phone' => $formData['requester_phone'] ?? null,
            'type' => $type,
            'subject' => $formData['subject'] ?? '',
            'description' => $formData['description'] ?? '',
            'status' => 'nuevo',
            'priority' => $formData['priority'] ?? 'media',
            'channel' => 'web',
            'first_response_sla_due' => $slaDeadlines['first_response_sla_due'],
            'resolution_sla_due' => $slaDeadlines['resolution_sla_due'],
        ], ['accessibleFields' => [
            'pqrs_number' => true, 'status' => true, 'channel' => true,
            'first_response_sla_due' => true, 'resolution_sla_due' => true,
        ]]);
        assert($pqrs instanceof \App\Model\Entity\Pqr);

        if (!$pqrsTable->save($pqrs)) {
            \Cake\Log\Log::error('Failed to create PQRS from form', ['errors' => $pqrs->getErrors()]);
            return null;
        }

        \Cake\Log\Log::info("PQRS created: {$pqrs->pqrs_number} from {$pqrs->requester_email}");

        // Process attachments
        if (!empty($files)) {
            foreach ($files as $file) {
                if ($file && $file->getError() === UPLOAD_ERR_OK) {
                    $result = $this->saveUploadedFile($pqrs, $file, null, null);
                    if ($result) {
                        \Cake\Log\Log::info("Attachment saved for PQRS {$pqrs->pqrs_number}: {$result->original_filename}");
                    }
                }
            }
        }

        // Send creation notifications (Email + WhatsApp)
        $this->dispatchCreationNotifications('pqrs', $pqrs);

        return $pqrs;
    }

    /**
     * Save uploaded file (using GenericAttachmentTrait)
     *
     * @param \App\Model\Entity\Pqr $pqrs PQRS entity
     * @param \Psr\Http\Message\UploadedFileInterface $file Uploaded file
     * @param int|null $commentId Comment ID
     * @param int|null $userId User ID
     * @return \App\Model\Entity\PqrsAttachment|null
     */
    public function saveUploadedFile(
        \App\Model\Entity\Pqr $pqrs,
        \Psr\Http\Message\UploadedFileInterface $file,
        ?int $commentId = null,
        ?int $userId = null
    ): ?\App\Model\Entity\PqrsAttachment {
        $result = $this->saveGenericUploadedFile('pqrs', $pqrs, $file, $commentId, $userId);
        assert($result instanceof \App\Model\Entity\PqrsAttachment || $result === null);
        return $result;
    }

    /**
     * Handle a complete response (comment + status change + files + notifications)
     *
     * @param int $entityId The PQRS ID
     * @param int $userId The ID of the user making the response
     * @param array $data Request data (comment_body, comment_type, status, email_to, email_cc)
     * @param array $files Uploaded files
     * @return array Result with 'success' (bool), 'message' (string), and 'entity' (mixed)
     */
    public function handleResponse(int $entityId, int $userId, array $data, array $files): array
    {
        $commentBody = $data['comment_body'] ?? $data['body'] ?? '';
        $commentType = $data['comment_type'] ?? 'public';
        $newStatus = $data['status'] ?? null;

        $emailTo = $this->decodeEmailRecipients($data['email_to'] ?? null);
        $emailCc = $this->decodeEmailRecipients($data['email_cc'] ?? null);

        $hasComment = !empty(trim($commentBody));

        $entity = $this->fetchTable('Pqrs')->get($entityId);
        assert($entity instanceof \App\Model\Entity\Pqr);

        $oldStatus = $entity->status;
        $hasStatusChange = $newStatus && $newStatus !== $oldStatus;

        if (!$hasComment && !$hasStatusChange) {
            return [
                'success' => false,
                'message' => 'Debes escribir un comentario o cambiar el estado.',
                'entity' => $entity,
            ];
        }

        $comment = null;
        $uploadedCount = 0;

        if ($hasComment) {
            $comment = $this->addComment($entityId, $userId, $commentBody, 'pqrs', $commentType, false, $emailTo, $emailCc);

            if (!$comment) {
                return [
                    'success' => false,
                    'message' => 'Error al agregar el comentario.',
                    'entity' => $entity,
                ];
            }

            if (!empty($files['attachments'])) {
                foreach ($files['attachments'] as $file) {
                    if ($file->getError() === UPLOAD_ERR_OK) {
                        $result = $this->saveUploadedFile($entity, $file, $comment->id, $userId);
                        if ($result) {
                            $uploadedCount++;
                        }
                    }
                }
            }
        }

        if ($hasStatusChange) {
            $this->changeStatus($entity, $newStatus, $userId, null, false);
            $entity->status = $newStatus;
        }

        $this->sendResponseNotifications('pqrs', $entity, $comment, $oldStatus, $newStatus, $hasComment, $commentType, $hasStatusChange, $emailTo, $emailCc);

        return $this->buildResponseResult($hasComment, $hasStatusChange, $uploadedCount, $entity);
    }

    /**
     * SLA methods provided by SlaAwareTrait:
     * - isFirstResponseSLABreached($entity)
     * - isResolutionSLABreached($entity)
     * - getSlaStatus($entity)
     */

    /**
     * Obtiene PQRS con SLA de resolución vencido
     *
     * @return array
     */
    public function getBreachedSLAPqrs(): array
    {
        $pqrsTable = $this->fetchTable('Pqrs');

        return $pqrsTable->find()
            ->where([
                'resolution_sla_due <' => new \Cake\I18n\DateTime(),
                'status NOT IN' => ['completado', 'cerrado', 'resuelto']
            ])
            ->order(['resolution_sla_due' => 'ASC'])
            ->toArray();
    }
}
