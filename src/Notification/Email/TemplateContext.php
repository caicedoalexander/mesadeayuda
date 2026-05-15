<?php
declare(strict_types=1);

namespace App\Notification\Email;

use App\Model\Entity\Ticket;
use App\Model\Entity\TicketComment;
use App\Model\Entity\User;

/**
 * Input bag for EmailTemplate::render().
 *
 * Security contract: `$comment->body` MUST be already sanitized by
 * HtmlSanitizerTrait before reaching this VO. CommentBlock inserts it raw.
 * All other string fields are treated as user-controlled text and escaped
 * inside the components with `htmlspecialchars`.
 */
final readonly class TemplateContext
{
    /**
     * @param array<int, mixed> $commentAttachments Attachments scoped to the comment (for hint only; not inlined)
     */
    public function __construct(
        public Ticket $ticket,
        public string $ticketUrl,
        public string $recipientName,
        public ?TicketComment $comment = null,
        public ?string $oldStatus = null,
        public ?string $newStatus = null,
        public ?User $actor = null,
        public array $commentAttachments = [],
    ) {
    }
}
