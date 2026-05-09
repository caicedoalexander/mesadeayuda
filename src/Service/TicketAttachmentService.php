<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Attachment;
use App\Model\Entity\Ticket;
use App\Service\Traits\GenericAttachmentTrait;
use Cake\Datasource\EntityInterface;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Exception;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Handles attachment processing for tickets: uploaded files (forms) and
 * inline email attachments. Wraps GenericAttachmentTrait with ticket-specific
 * coercion (int id → Ticket entity).
 */
class TicketAttachmentService
{
    use LocatorAwareTrait;
    use GenericAttachmentTrait;

    private ?GmailService $gmail;

    /**
     * @param \App\Service\GmailService|null $gmail Optional pre-built Gmail client.
     *        When null, a client is built lazily on first use (only when an email
     *        with attachments is actually processed). This avoids the OAuth-refresh
     *        roundtrip during construction and lets callers (e.g., GmailImportService)
     *        share a single authenticated instance across many tickets.
     */
    public function __construct(?GmailService $gmail = null)
    {
        $this->gmail = $gmail;
    }

    /**
     * Process email attachments using GenericAttachmentTrait.
     *
     * @param \Cake\Datasource\EntityInterface $ticket Ticket entity
     * @param array $attachments Array of attachment data
     * @param int $userId User ID who uploaded
     * @param int|null $commentId Optional comment ID to associate attachments with
     * @return void
     */
    public function processEmailAttachments(EntityInterface $ticket, array $attachments, int $userId, ?int $commentId = null): void
    {
        assert($ticket instanceof Ticket);

        if ($attachments === []) {
            return;
        }

        $gmailService = $this->getGmailService();

        foreach ($attachments as $attachmentData) {
            try {
                // Download attachment from Gmail. Rate-limit (Gmail API quota)
                // is enforced inside GmailService::downloadAttachment().
                $content = $gmailService->downloadAttachment(
                    $ticket->gmail_message_id,
                    $attachmentData['attachment_id'],
                );

                // Save attachment using GenericAttachmentTrait
                $this->saveAttachmentFromBinary(
                    $ticket,
                    $attachmentData['filename'],
                    $content,
                    $attachmentData['mime_type'],
                    $commentId,
                    $userId,
                );
            } catch (Exception $e) {
                Log::error('Failed to process attachment', [
                    'ticket_id' => $ticket->id,
                    'filename' => $attachmentData['filename'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Lazily build (or return cached) Gmail client. Subsequent calls reuse
     * the instance, avoiding repeated OAuth refresh roundtrips when many
     * tickets are ingested in a single batch.
     */
    private function getGmailService(): GmailService
    {
        return $this->gmail ??= new GmailService(GmailService::loadConfigFromDatabase());
    }

    /**
     * Save uploaded file (form upload).
     *
     * @param \App\Model\Entity\Ticket|int $ticket Ticket entity or ID
     * @param \Psr\Http\Message\UploadedFileInterface $file Uploaded file
     * @param int|null $commentId Comment ID
     * @param int|null $userId User ID
     * @return \App\Model\Entity\Attachment|null
     */
    public function saveUploadedFile(
        Ticket|int $ticket,
        UploadedFileInterface $file,
        ?int $commentId = null,
        ?int $userId = null,
    ): ?Attachment {
        if (is_int($ticket)) {
            $ticketsTable = $this->fetchTable('Tickets');
            $ticket = $ticketsTable->get($ticket);
        }
        assert($ticket instanceof Ticket);

        $result = $this->saveGenericUploadedFile($ticket, $file, $commentId, $userId);
        assert($result instanceof Attachment || $result === null);

        return $result;
    }
}
