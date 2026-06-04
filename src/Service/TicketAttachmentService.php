<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Attachment;
use App\Model\Entity\Ticket;
use App\Service\Traits\GenericAttachmentTrait;
use App\Service\Traits\TicketHistoryLoggerTrait;
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
    use TicketHistoryLoggerTrait;

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
                $saved = $this->saveAttachmentFromBinary(
                    $ticket,
                    $attachmentData['filename'],
                    $content,
                    $attachmentData['mime_type'],
                    $commentId,
                    $userId,
                );

                if ($saved !== null) {
                    $this->logHistory(
                        'TicketHistory',
                        'ticket_id',
                        (int)$ticket->id,
                        'attachment_added',
                        null,
                        (string)$attachmentData['filename'],
                        $userId,
                        'Adjunto recibido por correo: ' . $attachmentData['filename'],
                    );
                }
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
     * Download inline images referenced by cid: in the email body, persist each
     * with is_inline=true / content_id=<cid>, and return a [content_id => local URL]
     * map so the caller can rewrite the body BEFORE sanitization.
     *
     * Kept separate from processEmailAttachments because the return contract
     * differs (map vs void) and only inline images need this round-trip. See
     * audit CRIT-4 (F1+F2+G1).
     *
     * @param \Cake\Datasource\EntityInterface $ticket Ticket entity (must have gmail_message_id)
     * @param array<int, array{filename: string, mime_type: string, attachment_id: string, content_id: string, size: int}> $inlineImages
     * @param int $userId Uploader user ID (typically the requester)
     * @param int|null $commentId Optional comment to associate inline images with (null for ticket-level)
     * @return array<string, string> Map content_id => '/uploads/attachments/{n}/{uuid}.ext'
     */
    public function processInlineImages(EntityInterface $ticket, array $inlineImages, int $userId, ?int $commentId = null): array
    {
        assert($ticket instanceof Ticket);
        if ($inlineImages === []) {
            return [];
        }

        $gmailService = $this->getGmailService();
        $map = [];

        foreach ($inlineImages as $img) {
            $cid = (string)($img['content_id'] ?? '');
            if ($cid === '') {
                continue;
            }
            try {
                $content = $gmailService->downloadAttachment(
                    $ticket->gmail_message_id,
                    $img['attachment_id'],
                );

                $attachment = $this->saveAttachmentFromBinary(
                    $ticket,
                    $img['filename'],
                    $content,
                    $img['mime_type'],
                    $commentId,
                    $userId,
                    isInline: true,
                    contentId: $cid,
                );

                if ($attachment !== null) {
                    $url = $this->getWebUrl($attachment);
                    if ($url !== null) {
                        $map[$cid] = $url;
                    }
                }
            } catch (Exception $e) {
                Log::error('Failed to process inline image', [
                    'ticket_id' => $ticket->id,
                    'cid' => $cid,
                    'filename' => $img['filename'] ?? '(unknown)',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $map;
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

        if ($result !== null) {
            $this->logHistory(
                'TicketHistory',
                'ticket_id',
                (int)$ticket->id,
                'attachment_added',
                null,
                (string)$result->filename,
                $userId,
                'Adjunto subido: ' . $result->filename,
            );
        }

        return $result;
    }
}
