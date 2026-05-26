<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\TicketConstants;
use App\Service\Dto\SystemConfig;
use App\Service\Traits\HtmlSanitizerTrait;
use App\Service\Traits\TicketHistoryLoggerTrait;
use Cake\Datasource\EntityInterface;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Persists ticket comments. Sanitizes HTML body via HtmlSanitizerTrait.
 * Does NOT dispatch notifications — that responsibility belongs to
 * TicketPipelineService::handleResponse (response coordination) or callers
 * that need notification side-effects.
 */
class TicketCommentService
{
    use LocatorAwareTrait;
    use HtmlSanitizerTrait;
    use TicketHistoryLoggerTrait;

    /**
     * @param \App\Service\Dto\SystemConfig|null $config Accepted for symmetry with sibling services; not used today.
     */
    public function __construct(?SystemConfig $config = null)
    {
    }

    /**
     * Add comment to a ticket.
     *
     * NOTE: This method does NOT send notifications. Notifications are handled
     * by TicketPipelineService::handleResponse() via sendResponseNotifications()
     * for proper coordination of comment + status change + file uploads.
     *
     * @param int $entityId Ticket ID
     * @param int|null $userId User ID
     * @param string $body Comment body (HTML)
     * @param string $type Comment type
     * @param bool $isSystem Whether this is a system comment
     * @param array|null $emailTo Additional To recipients
     * @param array|null $emailCc Additional Cc recipients
     * @return \Cake\Datasource\EntityInterface|null Created comment or null on failure
     */
    public function addComment(
        int $entityId,
        ?int $userId,
        string $body,
        string $type = TicketConstants::COMMENT_PUBLIC,
        bool $isSystem = false,
        ?array $emailTo = null,
        ?array $emailCc = null,
    ): ?EntityInterface {
        $commentsTable = $this->fetchTable('TicketComments');

        $sanitizedBody = $this->sanitizeHtml($body);

        $data = [
            'ticket_id' => $entityId,
            'user_id' => $userId,
            'comment_type' => $type,
            'body' => $sanitizedBody,
            'is_system_comment' => $isSystem,
        ];

        if ($type === TicketConstants::COMMENT_PUBLIC && !$isSystem) {
            if (is_array($emailTo) && count($emailTo) > 0) {
                $data['email_to'] = json_encode($emailTo);
            }
            if (is_array($emailCc) && count($emailCc) > 0) {
                $data['email_cc'] = json_encode($emailCc);
            }
        }

        $comment = $commentsTable->newEntity($data, ['accessibleFields' => [
            'user_id' => true, 'is_system_comment' => true, 'sent_as_email' => true,
        ]]);

        if (!$commentsTable->save($comment)) {
            Log::error('Failed to add comment', ['errors' => $comment->getErrors()]);

            return null;
        }

        // Audit non-system comments. System comments (status/assign/priority
        // notes) are already covered by the parent change's history entry, so
        // logging them again here would duplicate the row.
        if (!$isSystem) {
            $this->logHistory(
                'TicketHistory',
                'ticket_id',
                $entityId,
                $type === TicketConstants::COMMENT_INTERNAL ? 'internal_comment_added' : 'comment_added',
                null,
                (string)$comment->id,
                $userId,
                $type === TicketConstants::COMMENT_INTERNAL
                    ? 'Nota interna agregada'
                    : 'Comentario público agregado',
            );
        }

        return $comment;
    }

    /**
     * Persist the RFC Message-ID assigned by Gmail to an outbound notification
     * onto the originating ticket_comment, so a future client reply with
     * In-Reply-To: <that-id> reattaches via lookupTicketByRfc(). Also stores
     * the References chain we sent for completeness (used to extend on
     * subsequent sends).
     *
     * Idempotent: re-running with the same args is a no-op safe overwrite.
     * Errors are logged but not propagated — the email already went out and
     * threading-by-RFC degrades gracefully to gmail_thread_id matching.
     *
     * @param int $commentId Target ticket_comments.id
     * @param string $rfcMessageId The Message-ID Gmail assigned to the outbound
     *   (already stripped of angle brackets by EmailHeaderParser::extractMessageId)
     * @param string|null $referencesHeader The References: header we sent, or null
     */
    public function attachOutboundMessageId(int $commentId, string $rfcMessageId, ?string $referencesHeader): void
    {
        $table = $this->fetchTable('TicketComments');
        $comment = $table->get($commentId);
        $comment->set('rfc_message_id', $rfcMessageId, ['guard' => false]);
        if ($referencesHeader !== null && $referencesHeader !== '') {
            $comment->set('references_header', $referencesHeader, ['guard' => false]);
        }

        if (!$table->save($comment)) {
            Log::error('Failed to persist outbound Message-ID on comment', [
                'comment_id' => $commentId,
                'rfc_message_id' => $rfcMessageId,
                'errors' => $comment->getErrors(),
            ]);
        }
    }
}
