<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\RoleConstants;
use App\Constants\TicketConstants;
use App\Domain\Event\TicketCreated;
use App\Model\Entity\Ticket;
use App\Model\Entity\TicketComment;
use App\Model\Entity\User;
use App\Service\Dto\SystemConfig;
use App\Service\Traits\HtmlSanitizerTrait;
use App\Service\Util\EmailHeaderParser;
use Cake\Event\EventManager;
use Cake\Event\EventManagerInterface;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Exception;

/**
 * Creates tickets and comments from external sources (Gmail today,
 * potentially WhatsApp/other channels in the future).
 */
class TicketIngestionService
{
    use LocatorAwareTrait;
    use HtmlSanitizerTrait;

    private TicketAttachmentService $attachments;
    private N8nService $n8n;
    private SystemConfig $config;
    private EventManagerInterface $eventManager;

    /**
     * @param \App\Service\Dto\SystemConfig|null $config System configuration VO
     * @param \App\Service\TicketAttachmentService|null $attachments Optional injected attachment service
     * @param \App\Service\N8nService|null $n8n Optional injected n8n webhook service
     * @param \Cake\Event\EventManagerInterface|null $eventManager Optional injected event manager
     */
    public function __construct(
        ?SystemConfig $config = null,
        ?TicketAttachmentService $attachments = null,
        ?N8nService $n8n = null,
        ?EventManagerInterface $eventManager = null,
    ) {
        $this->config = $config ?? SystemConfig::empty();
        $this->attachments = $attachments ?? new TicketAttachmentService();
        $this->n8n = $n8n ?? new N8nService($this->config);
        $this->eventManager = $eventManager ?? EventManager::instance();
    }

    /**
     * Create ticket from email data.
     *
     * @param array $emailData Parsed email data from GmailService
     * @return \App\Model\Entity\Ticket|null Created ticket or null on failure
     */
    public function createFromEmail(array $emailData): ?Ticket
    {
        $ticketsTable = $this->fetchTable('Tickets');

        // Check if ticket already exists
        if (!empty($emailData['gmail_message_id'])) {
            $existing = $ticketsTable->find()
                ->where(['gmail_message_id' => $emailData['gmail_message_id']])
                ->first();

            if ($existing) {
                Log::info('Ticket already exists for Gmail message: ' . $emailData['gmail_message_id']);

                return $existing;
            }
        }

        $fromEmail = EmailHeaderParser::extractEmailAddress($emailData['from']);
        $fromName = EmailHeaderParser::extractName($emailData['from']);

        // Find or create user
        $user = $this->findOrCreateUser($fromEmail, $fromName);

        if (!$user) {
            Log::error('Failed to create user for email: ' . $fromEmail);

            return null;
        }

        // Sanitize HTML content from email to prevent stored XSS
        $rawBody = $emailData['body_html'] ?: $emailData['body_text'];
        $description = $this->sanitizeHtml($rawBody);

        // Generate ticket number
        $ticketNumber = $ticketsTable->generateTicketNumber();

        $subject = trim($emailData['subject'] ?? '');

        // Determine channel: if email comes from the configured WhatsApp bot
        // address, classify as whatsapp instead of email.
        $channel = TicketConstants::CHANNEL_EMAIL;
        $botEmail = $this->config->whatsapp->botEmail;
        if ($botEmail !== '' && strtolower($fromEmail) === strtolower($botEmail)) {
            $channel = TicketConstants::CHANNEL_WHATSAPP;
        }

        // Build ticket via domain factory: status/priority defaults and
        // (Sin asunto) fallback live in the entity, not in this IO layer.
        $ticket = Ticket::fromEmailIngest(
            ticketNumber: $ticketNumber,
            requesterId: (int)$user->id,
            subject: $subject,
            sanitizedDescription: $description,
            channel: $channel,
            sourceEmail: $fromEmail,
            gmailMessageId: $emailData['gmail_message_id'] ?? null,
            gmailThreadId: $emailData['gmail_thread_id'] ?? null,
            emailTo: !empty($emailData['email_to']) ? $emailData['email_to'] : null,
            emailCc: !empty($emailData['email_cc']) ? $emailData['email_cc'] : null,
            rfcMessageId: $emailData['rfc_message_id'] ?? null,
            inReplyTo: $emailData['in_reply_to'] ?? null,
            referencesHeader: $emailData['references_header'] ?? null,
        );

        if (!$ticketsTable->save($ticket)) {
            Log::error('Failed to save ticket', ['errors' => $ticket->getErrors()]);

            return null;
        }

        // Process attachments
        if (!empty($emailData['attachments'])) {
            $this->attachments->processEmailAttachments($ticket, $emailData['attachments'], $user->id);
        }

        // Dispatch domain event — TicketNotificationListener handles notifications.
        $this->eventManager->dispatch(new TicketCreated(
            ticketId: (int)$ticket->id,
            requesterId: (int)$ticket->requester_id,
            source: TicketConstants::CHANNEL_EMAIL,
        ));

        // Send n8n webhook for AI tag assignment (lazy loaded only when creating tickets)
        try {
            $this->n8n->sendTicketCreatedWebhook($ticket);
        } catch (Exception $e) {
            Log::warning('n8n webhook failed (non-blocking): ' . $e->getMessage());
            // Don't block ticket creation if webhook fails
        }

        Log::info('Created ticket from email', [
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'from' => $fromEmail,
        ]);

        return $ticket;
    }

    /**
     * Create comment from email response in existing thread.
     *
     * @param \App\Model\Entity\Ticket $ticket The ticket to add comment to
     * @param array $emailData Parsed email data from GmailService
     * @return \App\Model\Entity\TicketComment|null Created comment or null
     */
    public function createCommentFromEmail(Ticket $ticket, array $emailData): ?TicketComment
    {
        $ticketCommentsTable = $this->fetchTable('TicketComments');

        $fromEmail = EmailHeaderParser::extractEmailAddress($emailData['from']);
        $fromName = EmailHeaderParser::extractName($emailData['from']);

        // Validate sender using isEmailInTicketRecipients() - return null if unauthorized
        if (!$this->isEmailInTicketRecipients($ticket, $fromEmail)) {
            Log::warning('Unauthorized email sender attempted to reply to ticket', [
                'ticket_id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'from_email' => $fromEmail,
            ]);

            return null;
        }

        // Find or create user
        $user = $this->findOrCreateUser($fromEmail, $fromName);
        if (!$user) {
            Log::error('Failed to create user for email comment', ['email' => $fromEmail]);

            return null;
        }

        // Extract and sanitize body content from emailData to prevent stored XSS
        $rawBody = $emailData['body_html'] ?: $emailData['body_text'];
        $body = $this->sanitizeHtml($rawBody);

        // Truncate body if it exceeds the MySQL TEXT byte budget. The previous
        // implementation used a naive substr() which (a) could split UTF-8
        // multi-byte sequences and (b) cut HTML mid-tag, producing malformed
        // markup. truncateSanitizedHtml() handles both safely.
        $maxLength = 65000;
        $originalLength = strlen($body);
        if ($originalLength > $maxLength) {
            $body = $this->truncateSanitizedHtml($body, $maxLength);
            Log::warning('Email body truncated to prevent DB overflow', [
                'ticket_id' => $ticket->id,
                'original_length' => $originalLength,
                'truncated_length' => strlen($body),
            ]);
        }

        // Create TicketComment entity with comment_type='public'
        $comment = $ticketCommentsTable->newEntity([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => $body,
            'comment_type' => TicketConstants::COMMENT_PUBLIC,
            'is_system_comment' => false,
            'gmail_message_id' => $emailData['gmail_message_id'] ?? null,
            'sent_as_email' => false,
            'email_to' => !empty($emailData['email_to']) ? json_encode($emailData['email_to']) : null,
            'email_cc' => !empty($emailData['email_cc']) ? json_encode($emailData['email_cc']) : null,
            'rfc_message_id' => $emailData['rfc_message_id'] ?? null,
            'in_reply_to' => $emailData['in_reply_to'] ?? null,
            'references_header' => $emailData['references_header'] ?? null,
        ], ['accessibleFields' => [
            'user_id' => true,
            'is_system_comment' => true,
            'gmail_message_id' => true,
            'sent_as_email' => true,
            'rfc_message_id' => true,
            'in_reply_to' => true,
            'references_header' => true,
        ]]);
        assert($comment instanceof TicketComment);

        // Save comment, return null on failure
        if (!$ticketCommentsTable->save($comment)) {
            Log::error('Failed to save ticket comment from email', [
                'ticket_id' => $ticket->id,
                'errors' => $comment->getErrors(),
            ]);

            return null;
        }

        // Process attachments if present using processEmailAttachments()
        if (!empty($emailData['attachments'])) {
            $this->attachments->processEmailAttachments($ticket, $emailData['attachments'], $user->id, $comment->id);
        }

        // Do NOT send notifications (explicitly skip notification logic to prevent email loops)

        // Log success
        Log::info('Created ticket comment from email', [
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'comment_id' => $comment->id,
            'from_email' => $fromEmail,
        ]);

        return $comment;
    }

    /**
     * Resolve the existing ticket an incoming email should reattach to, using
     * (in order): RFC 5322 In-Reply-To, References (newest-first), and finally
     * Gmail's threadId as a last-resort hint. Returns null when the message
     * should create a new ticket.
     *
     * Recency is enforced via TicketConstants::THREAD_REATTACH_WINDOW_DAYS:
     * matches outside the window are ignored to prevent ancient closed
     * threads from being resurrected by stale clients quoting old headers.
     *
     * @param array<string, mixed> $emailData Parsed email payload from GmailService::parseMessage
     */
    public function findExistingTicketByThreading(array $emailData): ?Ticket
    {
        $inReplyTo = $emailData['in_reply_to'] ?? null;
        if (is_string($inReplyTo) && $inReplyTo !== '') {
            $ticket = $this->lookupTicketByRfc($inReplyTo);
            if ($ticket !== null && $this->withinReattachWindow($ticket)) {
                return $ticket;
            }
        }

        $references = $emailData['references_header'] ?? null;
        if (is_string($references) && $references !== '') {
            foreach (array_reverse($this->parseReferences($references)) as $candidate) {
                $ticket = $this->lookupTicketByRfc($candidate);
                if ($ticket !== null && $this->withinReattachWindow($ticket)) {
                    return $ticket;
                }
            }
        }

        $threadId = $emailData['gmail_thread_id'] ?? null;
        if (is_string($threadId) && $threadId !== '') {
            return $this->fetchTable('Tickets')->find()
                ->where(['gmail_thread_id' => $threadId])
                ->first();
        }

        return null;
    }

    /**
     * Split a raw References: header into a list of message-id values
     * (angle brackets stripped, empty entries removed).
     *
     * @return list<string>
     */
    private function parseReferences(string $raw): array
    {
        $tokens = preg_split('/\s+/', trim($raw)) ?: [];
        $result = [];
        foreach ($tokens as $token) {
            $id = EmailHeaderParser::extractMessageId($token);
            if ($id !== null) {
                $result[] = $id;
            }
        }

        return $result;
    }

    /**
     * True when the ticket has been touched (created or modified) within
     * TicketConstants::THREAD_REATTACH_WINDOW_DAYS. Closed tickets are
     * additionally required to be inside the window; otherwise a stale reply
     * would resurrect them.
     */
    private function withinReattachWindow(Ticket $ticket): bool
    {
        $modified = $ticket->modified ?? $ticket->created;
        if ($modified === null) {
            return false;
        }
        $cutoff = DateTime::now()->subDays(TicketConstants::THREAD_REATTACH_WINDOW_DAYS);
        if ($ticket->isResolved() && $modified->lessThan($cutoff)) {
            return false;
        }

        return $modified->greaterThanOrEquals($cutoff);
    }

    /**
     * Resolve an RFC 5322 message-id to a ticket. Searches ticket_comments
     * first (most reattachments target the latest thread participant), then
     * tickets as a fallback for replies to the very first message in a thread.
     */
    private function lookupTicketByRfc(string $rfcId): ?Ticket
    {
        // Most reattachments target a comment (latest thread participant).
        $comment = $this->fetchTable('TicketComments')->find()
            ->where(['rfc_message_id' => $rfcId])
            ->order(['id' => 'DESC'])
            ->first();
        if ($comment !== null) {
            return $this->fetchTable('Tickets')->find()
                ->where(['id' => $comment->ticket_id])
                ->first();
        }

        return $this->fetchTable('Tickets')->find()
            ->where(['rfc_message_id' => $rfcId])
            ->first();
    }

    /**
     * Find existing user or create new one.
     *
     * @param string $email User email
     * @param string $name User name
     * @return \App\Model\Entity\User|null
     */
    private function findOrCreateUser(string $email, string $name): ?User
    {
        $usersTable = $this->fetchTable('Users');

        $user = $usersTable->find()
            ->where(['email' => $email])
            ->first();

        if ($user) {
            return $user;
        }

        // Split name into first and last name
        $nameParts = explode(' ', $name);
        $firstName = array_shift($nameParts);
        $lastName = implode(' ', $nameParts);

        if (empty($lastName)) {
            $lastName = $firstName; // Fallback if no last name
        }

        // Auto-create as 'external': non-functional marker, never logs in.
        // Exists so tickets.requester_id can FK to a real users row.
        $user = $usersTable->newEntity([
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'role' => RoleConstants::ROLE_EXTERNAL,
            'password' => null,
            'is_active' => true,
        ], ['accessibleFields' => ['role' => true, 'is_active' => true]]);
        assert($user instanceof User);

        if ($usersTable->save($user)) {
            Log::info('Auto-created user from email', ['email' => $email, 'name' => $name]);

            return $user;
        }

        Log::error('Failed to create user', ['email' => $email, 'errors' => $user->getErrors()]);

        return null;
    }

    /**
     * Check if email address is in ticket's original To/CC recipients.
     *
     * @param \App\Model\Entity\Ticket $ticket The ticket entity
     * @param string $email Email address to check
     * @return bool True if email is authorized
     */
    private function isEmailInTicketRecipients(Ticket $ticket, string $email): bool
    {
        // Normalize email for case-insensitive comparison
        $normalizedEmail = strtolower(trim($email));

        // Check email_to array
        $emailTo = $ticket->email_to_array;
        if (!empty($emailTo)) {
            foreach ($emailTo as $recipient) {
                if (isset($recipient['email']) && strtolower(trim($recipient['email'])) === $normalizedEmail) {
                    return true;
                }
            }
        }

        // Check email_cc array
        $emailCc = $ticket->email_cc_array;
        if (!empty($emailCc)) {
            foreach ($emailCc as $recipient) {
                if (isset($recipient['email']) && strtolower(trim($recipient['email'])) === $normalizedEmail) {
                    return true;
                }
            }
        }

        // Check if email is the original requester's email
        // Load ticket with Requesters association if not already loaded
        if (!isset($ticket->requester)) {
            $ticketsTable = $this->fetchTable('Tickets');
            $ticket = $ticketsTable->get($ticket->id, [
                'contain' => ['Requesters'],
            ]);
        }

        if (isset($ticket->requester->email) && strtolower(trim($ticket->requester->email)) === $normalizedEmail) {
            return true;
        }

        return false;
    }
}
