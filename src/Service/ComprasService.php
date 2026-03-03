<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Compra;
use App\Model\Entity\Ticket;
use App\Service\Traits\TicketSystemTrait;
use App\Service\Traits\EntityConversionTrait;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;

class ComprasService
{
    use LocatorAwareTrait;
    use TicketSystemTrait;
    use \App\Service\Traits\NotificationDispatcherTrait;
    use \App\Service\Traits\GenericAttachmentTrait;
    use EntityConversionTrait;
    use \App\Service\Traits\SlaAwareTrait;

    private EmailService $emailService;
    private WhatsappService $whatsappService;
    private SlaManagementService $slaService;
    private ?array $systemConfig;

    public function __construct(?array $systemConfig = null)
    {
        $this->systemConfig = $systemConfig;
        $this->emailService = new EmailService($systemConfig);
        $this->whatsappService = new WhatsappService($systemConfig);
        $this->slaService = new SlaManagementService();
    }

    /**
     * Convert compra to ticket (full workflow)
     *
     * This method handles the complete conversion workflow:
     * 1. Creates the ticket from compra
     * 2. Marks compra as converted
     * 3. Copies all data (comments, attachments)
     *
     * @param Compra $compra Source compra
     * @param int $userId User performing the conversion
     * @param \App\Service\TicketService $ticketService Injected TicketService
     * @return \App\Model\Entity\Ticket|null Created ticket or null on failure
     */
    public function convertToTicket(
        Compra $compra,
        int $userId,
        \App\Service\TicketService $ticketService
    ): ?\App\Model\Entity\Ticket {
        try {
            // Create ticket from compra
            $ticket = $ticketService->createFromCompra($compra, ['user_id' => $userId]);

            if (!$ticket) {
                Log::error('Failed to create ticket from compra', ['compra_id' => $compra->id]);
                return null;
            }

            // Mark compra as converted using trait method
            $this->markAsConverted('compra', $compra, 'ticket', $ticket, $userId);

            // Copy all data (comments and attachments)
            $ticketService->copyCompraData($compra, $ticket);

            Log::info('Compra converted to Ticket successfully', [
                'compra_id' => $compra->id,
                'compra_number' => $compra->compra_number,
                'ticket_id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
            ]);

            return $ticket;

        } catch (\Exception $e) {
            Log::error('Error in convertToTicket: ' . $e->getMessage(), [
                'compra_id' => $compra->id,
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * Crea una compra desde un ticket existente
     * Workflow: Ticket → Convertir → Compra
     */
    public function createFromTicket(Ticket $ticket, array $data = []): ?Compra
    {
        $comprasTable = $this->fetchTable('Compras');

        try {
            $compraNumber = $comprasTable->generateCompraNumber();

            // Calculate SLA deadlines using SlaManagementService
            $slaDeadlines = $this->slaService->calculateComprasSlaDeadlines();

            $compra = $comprasTable->newEntity([
                'compra_number' => $compraNumber,
                'original_ticket_number' => $ticket->ticket_number,
                'subject' => $ticket->subject,
                'description' => $ticket->description,
                'status' => 'nuevo',
                'priority' => $ticket->priority,
                'requester_id' => $ticket->requester_id,
                'assignee_id' => $data['assignee_id'] ?? null,
                'channel' => $ticket->channel ?? 'email',
                'email_to' => $ticket->email_to,
                'email_cc' => $ticket->email_cc,
                'sla_due_date' => $slaDeadlines['resolution_sla_due'],
                'first_response_sla_due' => $slaDeadlines['first_response_sla_due'],
                'resolution_sla_due' => $slaDeadlines['resolution_sla_due'],
            ], ['accessibleFields' => [
                'compra_number' => true, 'status' => true, 'requester_id' => true,
                'channel' => true, 'sla_due_date' => true,
                'first_response_sla_due' => true, 'resolution_sla_due' => true,
            ]]);

            if ($comprasTable->save($compra)) {
                $this->logHistory(
                    'ComprasHistory',
                    'compra_id',
                    $compra->id,
                    'created',
                    null,
                    'nuevo',
                    $data['user_id'] ?? null,
                    "Compra creada desde ticket {$ticket->ticket_number}"
                );

                // Send creation notifications (Email + WhatsApp)
                $this->dispatchCreationNotifications('compra', $compra);

                return $compra;
            }

            Log::error('Error al guardar compra', ['ticket_id' => $ticket->id]);
            return null;

        } catch (\Exception $e) {
            Log::error('Error en createFromTicket: ' . $e->getMessage(), [
                'ticket_id' => $ticket->id,
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * Copia datos del ticket a la compra
     *
     * REFACTORED: Now uses EntityConversionTrait for generic copying logic
     *
     * @param Ticket $ticket Source ticket
     * @param Compra $compra Destination compra
     * @return bool Success status
     */
    public function copyTicketData(Ticket $ticket, Compra $compra): bool
    {
        try {
            // Load ticket with associations
            $ticketsTable = $this->fetchTable('Tickets');
            $ticket = $ticketsTable->get($ticket->id, [
                'contain' => ['TicketComments', 'Attachments']
            ]);

            // Copy comments using trait
            $commentsCopied = $this->copyComments('ticket', $ticket, 'compra', $compra);

            // Copy attachments using trait
            $attachmentsCopied = $this->copyAttachments(
                'ticket',
                $ticket,
                'compra',
                $compra,
                $compra->compra_number
            );

            Log::info('Copied ticket data to compra', [
                'ticket_id' => $ticket->id,
                'compra_id' => $compra->id,
                'comments_copied' => $commentsCopied,
                'attachments_copied' => $attachmentsCopied,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Error copiando datos: ' . $e->getMessage(), [
                'ticket_id' => $ticket->id,
                'compra_id' => $compra->id,
                'exception' => $e,
            ]);
            return false;
        }
    }

    /**
     * SLA methods provided by SlaAwareTrait:
     * - isFirstResponseSLABreached($entity)
     * - isResolutionSLABreached($entity) (uses getResolutionSlaDue() for legacy fallback)
     * - getSlaStatus($entity)
     */

    /**
     * Override to support legacy sla_due_date field in Compras
     *
     * @param \Cake\Datasource\EntityInterface $entity Compra entity
     * @return \Cake\I18n\DateTime|null
     */
    protected function getResolutionSlaDue(\Cake\Datasource\EntityInterface $entity): ?\Cake\I18n\DateTime
    {
        return $entity->resolution_sla_due ?? $entity->sla_due_date;
    }

    /**
     * Obtiene compras con SLA de resolución vencido
     *
     * @return array
     */
    public function getBreachedSLACompras(): array
    {
        $comprasTable = $this->fetchTable('Compras');

        return $comprasTable->find()
            ->where([
                'OR' => [
                    'resolution_sla_due <' => new DateTime(),
                    'sla_due_date <' => new DateTime(), // Legacy field support
                ],
                'status NOT IN' => ['completado', 'rechazado']
            ])
            ->contain(['Requesters', 'Assignees'])
            ->order(['resolution_sla_due' => 'ASC'])
            ->toArray();
    }

    /**
     * Note: addComment() method is provided by TicketSystemTrait
     *
     * This service uses the trait's addComment() method directly.
     * When calling from external code, use:
     *
     * $comprasService->addComment(
     *     $compraId,
     *     $userId,
     *     $body,
     *     $type,              // 'public' or 'internal'
     *     $isSystem,          // true for system comments
     *     $sendNotifications, // false by default
     *     'compra',           // entityType (REQUIRED: 'ticket', 'pqrs', or 'compra')
     *     $emailTo,           // optional array
     *     $emailCc            // optional array
     * );
     */

    /**
     * Save uploaded file for compra (using GenericAttachmentTrait)
     *
     * @param Compra $compra Compra entity
     * @param \Psr\Http\Message\UploadedFileInterface $file Uploaded file
     * @param int|null $commentId Comment ID
     * @param int|null $userId User ID
     * @return \App\Model\Entity\ComprasAttachment|null
     */
    public function saveUploadedFile(
        Compra $compra,
        \Psr\Http\Message\UploadedFileInterface $file,
        ?int $commentId = null,
        ?int $userId = null
    ): ?\App\Model\Entity\ComprasAttachment {
        $result = $this->saveGenericUploadedFile('compra', $compra, $file, $commentId, $userId);
        assert($result instanceof \App\Model\Entity\ComprasAttachment || $result === null);
        return $result;
    }
}

