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
use App\Service\Dto\WhatsappIngestPayload;
use App\Service\Dto\WhatsappIngestPayloadAttachment;
use App\Service\Traits\HtmlSanitizerTrait;
use App\Service\Util\EmailHeaderParser;
use App\Service\Util\LogMasker;
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

        // Inline images: download, persist with is_inline=true/content_id, then
        // rewrite cid: references in the body and re-save the ticket description.
        // Must run AFTER the initial ticket save so we have ticket.id and ticket_number
        // (the latter is used to compute the local upload URL). See audit CRIT-4 (F1+F2+G1).
        if (!empty($emailData['inline_images'])) {
            $cidMap = $this->attachments->processInlineImages($ticket, $emailData['inline_images'], (int)$user->id);
            if ($cidMap !== []) {
                $rewritten = $this->rewriteCidReferences($rawBody, $cidMap);
                $ticket->description = $this->sanitizeHtml($rewritten);
                if (!$ticketsTable->save($ticket)) {
                    Log::warning('Inline images persisted but description rewrite failed to save', [
                        'ticket_id' => $ticket->id,
                        'errors' => $ticket->getErrors(),
                    ]);
                }
            }
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
            'from' => LogMasker::email($fromEmail),
        ]);

        return $ticket;
    }

    /**
     * Create ticket from a validated WhatsApp ingest payload.
     *
     * Idempotente por whatsapp_message_id: si el mensaje ya fue importado,
     * retorna el ticket existente sin recrear.
     *
     * @param \App\Service\Dto\WhatsappIngestPayload $payload Validated payload
     * @return array{ticket: \App\Model\Entity\Ticket|null, created: bool}
     */
    public function createFromWhatsapp(WhatsappIngestPayload $payload): array
    {
        $ticketsTable = $this->fetchTable('Tickets');

        // Idempotency: dedupe by whatsapp_message_id (unique index in BD).
        $existing = $ticketsTable->find()
            ->where(['whatsapp_message_id' => $payload->messageId])
            ->first();

        if ($existing) {
            Log::info('WhatsApp message already imported', [
                'message_id' => $payload->messageId,
                'ticket_id' => $existing->id,
            ]);

            return ['ticket' => $existing, 'created' => false];
        }

        $user = $this->findOrCreateUserByPhone($payload->phoneNumber, $payload->contactName);
        if (!$user) {
            Log::error('Failed to resolve user from WhatsApp phone', [
                'phone' => LogMasker::phone($payload->phoneNumber),
            ]);

            return ['ticket' => null, 'created' => false];
        }

        // Sanitize description (treat as untrusted free text from user).
        $description = $this->sanitizeHtml($payload->description);

        $ticketNumber = $ticketsTable->generateTicketNumber();

        $ticket = Ticket::fromWhatsappIngest(
            ticketNumber: $ticketNumber,
            requesterId: (int)$user->id,
            subject: $payload->subject,
            sanitizedDescription: $description,
            sourcePhone: $payload->phoneNumber,
            whatsappMessageId: $payload->messageId,
        );

        if (!$ticketsTable->save($ticket)) {
            // Race fallback: a concurrent retry may have inserted the row
            // between our initial dedupe SELECT and this save attempt. The
            // unique index on whatsapp_message_id ensures only one wins; the
            // loser surfaces the duplicate-key as a save failure. Re-query
            // and treat as the "already imported" branch instead of 5xx.
            $racingExisting = $ticketsTable->find()
                ->where(['whatsapp_message_id' => $payload->messageId])
                ->first();
            if ($racingExisting !== null) {
                Log::info('WhatsApp ticket already imported (race winner)', [
                    'message_id' => $payload->messageId,
                    'ticket_id' => $racingExisting->id,
                ]);

                return ['ticket' => $racingExisting, 'created' => false];
            }

            Log::error('Failed to save WhatsApp ticket', [
                'errors' => $ticket->getErrors(),
                'message_id' => $payload->messageId,
            ]);

            return ['ticket' => null, 'created' => false];
        }

        // Best-effort attachments: failures logged as warning, ticket still created.
        foreach ($payload->attachments as $attachment) {
            $this->downloadAndStoreWhatsappAttachment($ticket, $attachment, (int)$user->id);
        }

        $this->eventManager->dispatch(new TicketCreated(
            ticketId: (int)$ticket->id,
            requesterId: (int)$ticket->requester_id,
            source: TicketConstants::CHANNEL_WHATSAPP,
        ));

        Log::info('Created ticket from WhatsApp', [
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'phone' => LogMasker::phone($payload->phoneNumber),
        ]);

        return ['ticket' => $ticket, 'created' => true];
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

        // Inline images for this comment (associated via $comment->id). Same
        // ordering rationale as createFromEmail: persist first, then rewrite
        // cid: references against local URLs, then re-sanitize and re-save.
        // See audit CRIT-4 (F1+F2+G1).
        if (!empty($emailData['inline_images'])) {
            $cidMap = $this->attachments->processInlineImages($ticket, $emailData['inline_images'], (int)$user->id, (int)$comment->id);
            if ($cidMap !== []) {
                $rewritten = $this->rewriteCidReferences($rawBody, $cidMap);
                $rewrittenSanitized = $this->sanitizeHtml($rewritten);
                if (strlen($rewrittenSanitized) > $maxLength) {
                    $rewrittenSanitized = $this->truncateSanitizedHtml($rewrittenSanitized, $maxLength);
                }
                $comment->body = $rewrittenSanitized;
                if (!$ticketCommentsTable->save($comment)) {
                    Log::warning('Inline images persisted but comment body rewrite failed to save', [
                        'comment_id' => $comment->id,
                        'errors' => $comment->getErrors(),
                    ]);
                }
            }
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
     * Replace cid:<id> references in <img src=...> with persisted local URLs.
     * Must run on RAW (pre-sanitize) HTML — HTMLPurifier strips cid: schemes
     * because they are not in URI.AllowedSchemes, leaving <img> orphaned without
     * a src attribute. See audit CRIT-4 (F1+F2+G1).
     *
     * @param string $html Raw HTML body extracted from the email
     * @param array<string, string> $cidMap content_id => local URL
     */
    private function rewriteCidReferences(string $html, array $cidMap): string
    {
        if ($cidMap === [] || $html === '') {
            return $html;
        }

        return preg_replace_callback(
            '/(<img\b[^>]*\bsrc\s*=\s*["\'])cid:([^"\']+)(["\'])/i',
            function (array $m) use ($cidMap): string {
                $cid = trim($m[2]);

                return isset($cidMap[$cid]) ? $m[1] . $cidMap[$cid] . $m[3] : $m[0];
            },
            $html,
        ) ?? $html;
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

        // Open tickets remain reattachable indefinitely — fragmenting a long-running
        // open conversation after 90 days is worse than re-opening a stale thread.
        if (!$ticket->isResolved()) {
            return true;
        }

        // Resolved tickets only reattach if a reply arrives within the window. Beyond
        // it, the customer should open a fresh ticket rather than resurrect a closed one.
        $cutoff = DateTime::now()->subDays(TicketConstants::THREAD_REATTACH_WINDOW_DAYS);

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
     * Find a user by phone number; create a placeholder requester if absent.
     *
     * Placeholder email follows the convention "<digits>@whatsapp.local" so
     * the requirePresence('email') rule and unique-email constraint stay
     * satisfied without changing UsersTable validation.
     *
     * @param string $phone E.164 phone number
     * @param string|null $contactName Optional display name from WhatsApp
     */
    private function findOrCreateUserByPhone(string $phone, ?string $contactName): ?User
    {
        $usersTable = $this->fetchTable('Users');

        // Restrict to external requesters: ingesting a WhatsApp message must
        // never silently impersonate an internal employee whose profile
        // happens to carry the same phone number.
        $user = $usersTable->find()
            ->where([
                'phone' => $phone,
                'role' => RoleConstants::ROLE_EXTERNAL,
            ])
            ->first();
        if ($user) {
            return $user;
        }

        $placeholderEmail = ltrim($phone, '+') . '@whatsapp.local';

        // Defensive: a previous WhatsApp ingest may have created the placeholder
        // email under a different phone normalization. Reuse if it exists.
        $byEmail = $usersTable->find()
            ->where([
                'email' => $placeholderEmail,
                'role' => RoleConstants::ROLE_EXTERNAL,
            ])
            ->first();
        if ($byEmail) {
            return $byEmail;
        }

        $name = $contactName !== null && $contactName !== '' ? $contactName : $phone;
        $nameParts = explode(' ', $name, 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? $firstName;

        $user = $usersTable->newEntity([
            'email' => $placeholderEmail,
            'phone' => $phone,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'role' => RoleConstants::ROLE_EXTERNAL,
            'password' => null,
            'is_active' => true,
        ], ['accessibleFields' => ['role' => true, 'is_active' => true]]);
        assert($user instanceof User);

        if ($usersTable->save($user)) {
            Log::info('Auto-created user from WhatsApp phone', [
                'phone' => LogMasker::phone($phone),
                'email' => LogMasker::email($placeholderEmail),
            ]);

            return $user;
        }

        Log::error('Failed to create WhatsApp user', [
            'phone' => LogMasker::phone($phone),
            'errors' => $user->getErrors(),
        ]);

        return null;
    }

    /**
     * Persist a WhatsApp attachment, choosing source by payload shape:
     * - content_base64 → decode and save (Meta Cloud media path).
     * - url             → secure HTTPS download (generic external link).
     *
     * Failures are logged at warning and do NOT abort the ticket.
     */
    private function downloadAndStoreWhatsappAttachment(
        Ticket $ticket,
        WhatsappIngestPayloadAttachment $attachment,
        int $userId,
    ): void {
        try {
            $binary = $attachment->contentBase64 !== null
                ? $this->decodeAttachmentBase64($attachment, $ticket)
                : $this->fetchAttachmentFromUrl($attachment, $ticket);

            if ($binary === null) {
                return;
            }

            if (strlen($binary) !== $attachment->size) {
                Log::warning('WhatsApp attachment size mismatch', [
                    'declared' => $attachment->size,
                    'actual' => strlen($binary),
                    'ticket_id' => $ticket->id,
                ]);
            }

            $this->attachments->saveAttachmentFromBinary(
                entity: $ticket,
                filename: $attachment->filename,
                binaryContent: $binary,
                mimeType: $attachment->mime,
                commentId: null,
                userId: $userId,
            );
        } catch (Exception $e) {
            Log::warning('WhatsApp attachment processing failed', [
                'ticket_id' => $ticket->id,
                'filename' => $attachment->filename,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Decode an inline base64 attachment. Returns null on decode failure
     * (already validated in DTO, but defense-in-depth at IO boundary).
     */
    private function decodeAttachmentBase64(
        WhatsappIngestPayloadAttachment $attachment,
        Ticket $ticket,
    ): ?string {
        $binary = base64_decode((string)$attachment->contentBase64, true);
        if ($binary === false) {
            Log::warning('WhatsApp attachment base64 decode failed', [
                'ticket_id' => $ticket->id,
                'filename' => $attachment->filename,
            ]);

            return null;
        }

        return $binary;
    }

    /**
     * Download an attachment from an HTTPS URL via stream_context.
     * SecureHttpTrait exposes only secureCurlPost() today; for binary GET
     * we use file_get_contents restricted to https. Swap when a binary
     * helper exists.
     */
    private function fetchAttachmentFromUrl(
        WhatsappIngestPayloadAttachment $attachment,
        Ticket $ticket,
    ): ?string {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'follow_location' => 0,
                'header' => "User-Agent: MesaDeAyuda-WhatsAppIngest/1.0\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $binary = @file_get_contents((string)$attachment->url, false, $context);
        if ($binary === false) {
            Log::warning('WhatsApp attachment download failed', [
                'url' => $attachment->url,
                'ticket_id' => $ticket->id,
            ]);

            return null;
        }

        return $binary;
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
        $normalized = strtolower(trim($email));
        if ($normalized === '') {
            return false;
        }

        $authorized = $this->getAuthorizedEmailSet($ticket);

        return isset($authorized[$normalized]);
    }

    /**
     * Build the set of email addresses authorized to post comments on this ticket.
     *
     * Union of:
     *  - Ticket-level recipients (email_to, email_cc) captured at ingestion.
     *  - Requester email.
     *  - Recipients added by agents on any prior public comment
     *    (ticket_comments.email_to / email_cc). Without this branch, a CC added by
     *    an agent (e.g., escalation to an external expert) cannot reply: the agent's
     *    notification reaches them, but their reply is rejected as "unauthorized" and
     *    silently dropped — see audit CRIT-3 (K3+K4+K5).
     *
     * @param \App\Model\Entity\Ticket $ticket The ticket entity
     * @return array<string, true> Lowercased email => true (set-as-map)
     */
    private function getAuthorizedEmailSet(Ticket $ticket): array
    {
        $set = [];

        foreach (['email_to_array', 'email_cc_array'] as $field) {
            foreach ((array)($ticket->{$field} ?? []) as $recipient) {
                if (!empty($recipient['email'])) {
                    $set[strtolower(trim((string)$recipient['email']))] = true;
                }
            }
        }

        if (!isset($ticket->requester)) {
            $ticketsTable = $this->fetchTable('Tickets');
            $ticket = $ticketsTable->get($ticket->id, ['contain' => ['Requesters']]);
        }
        if (!empty($ticket->requester->email)) {
            $set[strtolower(trim((string)$ticket->requester->email))] = true;
        }

        // Expand with recipients added by agents on prior public comments. Only
        // public comments (comment_type != 'internal') publish to external parties;
        // internal notes' email_to/email_cc are by convention NULL but we filter
        // defensively to skip system comments anyway.
        $comments = $this->fetchTable('TicketComments')->find()
            ->select(['email_to', 'email_cc'])
            ->where([
                'ticket_id' => $ticket->id,
                'is_system_comment' => false,
                'comment_type' => TicketConstants::COMMENT_PUBLIC,
            ])
            ->all();

        foreach ($comments as $comment) {
            foreach (['email_to', 'email_cc'] as $field) {
                $raw = $comment->{$field} ?? null;
                if (empty($raw)) {
                    continue;
                }
                $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
                if (!is_array($decoded)) {
                    continue;
                }
                foreach ($decoded as $r) {
                    if (!empty($r['email'])) {
                        $set[strtolower(trim((string)$r['email']))] = true;
                    }
                }
            }
        }

        return $set;
    }
}
