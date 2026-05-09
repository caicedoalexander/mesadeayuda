<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\TicketConstants;
use App\Service\Dto\SystemConfig;
use App\Service\Traits\HtmlSanitizerTrait;
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

        return $comment;
    }
}
