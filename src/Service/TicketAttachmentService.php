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
        $gmailService = new GmailService(GmailService::loadConfigFromDatabase());

        foreach ($attachments as $attachmentData) {
            try {
                // PERFORMANCE FIX: Reduced sleep from 1000ms to 200ms
                // Gmail API allows 250 requests/second, 200ms = 5 requests/second is safe
                // Previous: 10 files = 10 seconds, Now: 10 files = 2 seconds (80% faster)
                usleep(200000);

                // Download attachment from Gmail
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
