<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Ticket;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Log\Log;
use App\Service\Traits\EntityConversionTrait;

/**
 * Ticket Service
 *
 * Handles core ticket business logic:
 * - Creating tickets from email
 * - Managing ticket lifecycle
 * - Status changes
 * - Comments
 */
class TicketService
{
    use LocatorAwareTrait;
    use \App\Service\Traits\TicketSystemTrait;
    use \App\Service\Traits\NotificationDispatcherTrait;
    use \App\Service\Traits\GenericAttachmentTrait;
    use EntityConversionTrait;

    private EmailService $emailService;
    private WhatsappService $whatsappService;
    private ?N8nService $n8nService = null;
    private ?array $systemConfig = null;

    /**
     * Constructor
     *
     * @param array|null $systemConfig Optional system configuration to avoid redundant DB queries
     */
    public function __construct(?array $systemConfig = null)
    {
        $this->emailService = new EmailService($systemConfig);
        $this->whatsappService = new WhatsappService($systemConfig);
        $this->systemConfig = $systemConfig;
        // N8nService NOT initialized here - loaded lazily only when needed
    }

    /**
     * Get N8nService instance (lazy loading)
     *
     * @return N8nService
     */
    private function getN8nService(): N8nService
    {
        if ($this->n8nService === null) {
            $this->n8nService = new N8nService($this->systemConfig);
        }
        return $this->n8nService;
    }

    /**
     * Create ticket from email data
     *
     * @param array $emailData Parsed email data from GmailService
     * @return \App\Model\Entity\Ticket|null Created ticket or null on failure
     */
    public function createFromEmail(array $emailData): ?\App\Model\Entity\Ticket
    {
        $ticketsTable = $this->fetchTable('Tickets');
        $usersTable = $this->fetchTable('Users');

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

        // Extract email address from From header (no config needed for parsing)
        $parser = new GmailService();
        $fromEmail = $parser->extractEmailAddress($emailData['from']);
        $fromName = $parser->extractName($emailData['from']);

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

        // Ensure subject is not empty
        $subject = trim($emailData['subject'] ?? '');
        if (empty($subject)) {
            $subject = '(Sin asunto)';
        }

        // Determine channel: if email comes from WhatsApp bot email, set channel as 'whatsapp'
        $channel = 'email';
        $whatsappBotEmail = 'mesadeayuda.whatsapp@gmail.com';
        if (strtolower($fromEmail) === strtolower($whatsappBotEmail)) {
            $channel = 'whatsapp';
        }

        // Create ticket
        $ticket = $ticketsTable->newEntity([
            'ticket_number' => $ticketNumber,
            'gmail_message_id' => $emailData['gmail_message_id'] ?? null,
            'gmail_thread_id' => $emailData['gmail_thread_id'] ?? null,
            'subject' => $subject,
            'description' => $description,
            'status' => 'nuevo',
            'priority' => 'media',
            'requester_id' => $user->id,
            'channel' => $channel,
            'source_email' => $fromEmail,
        ], ['accessibleFields' => [
            'ticket_number' => true, 'gmail_message_id' => true, 'gmail_thread_id' => true,
            'status' => true, 'requester_id' => true, 'channel' => true, 'source_email' => true,
        ]]);
        assert($ticket instanceof \App\Model\Entity\Ticket);

        // Set email recipients directly (bypass marshalling to avoid validation issues)
        $ticket->email_to = !empty($emailData['email_to']) ? $emailData['email_to'] : null;
        $ticket->email_cc = !empty($emailData['email_cc']) ? $emailData['email_cc'] : null;

        if (!$ticketsTable->save($ticket)) {
            Log::error('Failed to save ticket', ['errors' => $ticket->getErrors()]);
            return null;
        }

        // Process attachments
        if (!empty($emailData['attachments'])) {
            $this->processEmailAttachments($ticket, $emailData['attachments'], $user->id);
        }

        // Send creation notifications (Email + WhatsApp)
        $this->dispatchCreationNotifications('ticket', $ticket);

        // Send n8n webhook for AI tag assignment (lazy loaded only when creating tickets)
        try {
            $this->getN8nService()->sendTicketCreatedWebhook($ticket);
        } catch (\Exception $e) {
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
     * Create comment from email response in existing thread
     *
     * @param \App\Model\Entity\Ticket $ticket The ticket to add comment to
     * @param array $emailData Parsed email data from GmailService
     * @return \App\Model\Entity\TicketComment|null Created comment or null
     */
    public function createCommentFromEmail(\App\Model\Entity\Ticket $ticket, array $emailData): ?\App\Model\Entity\TicketComment
    {
        $ticketCommentsTable = $this->fetchTable('TicketComments');

        // Extract sender email and name from emailData (no config needed for parsing)
        $parser = new GmailService();
        $fromEmail = $parser->extractEmailAddress($emailData['from']);
        $fromName = $parser->extractName($emailData['from']);

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

        // Truncate body if > 65,000 chars (prevent DB overflow)
        $maxLength = 65000;
        if (strlen($body) > $maxLength) {
            Log::warning('Email body truncated to prevent DB overflow', [
                'ticket_id' => $ticket->id,
                'original_length' => strlen($body),
                'truncated_length' => $maxLength,
            ]);
            $body = substr($body, 0, $maxLength);
        }

        // Create TicketComment entity with comment_type='public'
        $comment = $ticketCommentsTable->newEntity([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => $body,
            'comment_type' => 'public',
            'is_system_comment' => false,
            'gmail_message_id' => $emailData['gmail_message_id'] ?? null,
            'sent_as_email' => false,
            'email_to' => !empty($emailData['email_to']) ? json_encode($emailData['email_to']) : null,
            'email_cc' => !empty($emailData['email_cc']) ? json_encode($emailData['email_cc']) : null,
        ], ['accessibleFields' => [
            'user_id' => true, 'is_system_comment' => true, 'gmail_message_id' => true, 'sent_as_email' => true,
        ]]);
        assert($comment instanceof \App\Model\Entity\TicketComment);

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
            $this->processEmailAttachments($ticket, $emailData['attachments'], $user->id, $comment->id);
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
     * Find existing user or create new one
     *
     * @param string $email User email
     * @param string $name User name
     * @return \App\Model\Entity\User|null
     */
    private function findOrCreateUser(string $email, string $name): ?\App\Model\Entity\User
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

        // Create new user with role 'requester' and null password
        $user = $usersTable->newEntity([
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'role' => 'requester',
            'password' => null,
            'is_active' => true,
        ], ['accessibleFields' => ['role' => true, 'is_active' => true]]);
        assert($user instanceof \App\Model\Entity\User);

        if ($usersTable->save($user)) {
            Log::info('Auto-created user from email', ['email' => $email, 'name' => $name]);
            return $user;
        }

        Log::error('Failed to create user', ['email' => $email, 'errors' => $user->getErrors()]);
        return null;
    }

    /**
     * Check if email address is in ticket's original To/CC recipients
     *
     * @param \App\Model\Entity\Ticket $ticket The ticket entity
     * @param string $email Email address to check
     * @return bool True if email is authorized
     */
    private function isEmailInTicketRecipients(\App\Model\Entity\Ticket $ticket, string $email): bool
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
                'contain' => ['Requesters']
            ]);
        }

        if (isset($ticket->requester->email) && strtolower(trim($ticket->requester->email)) === $normalizedEmail) {
            return true;
        }

        return false;
    }

    /**
     * Process email attachments (now using GenericAttachmentTrait)
     *
     * @param \Cake\Datasource\EntityInterface $ticket Ticket entity
     * @param array $attachments Array of attachment data
     * @param int $userId User ID who uploaded
     * @param int|null $commentId Optional comment ID to associate attachments with
     * @return void
     */
    private function processEmailAttachments(\Cake\Datasource\EntityInterface $ticket, array $attachments, int $userId, ?int $commentId = null): void
    {
        assert($ticket instanceof \App\Model\Entity\Ticket);
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
                    $attachmentData['attachment_id']
                );

                // Save attachment using GenericAttachmentTrait
                $this->saveAttachmentFromBinary(
                    'ticket',
                    $ticket,
                    $attachmentData['filename'],
                    $content,
                    $attachmentData['mime_type'],
                    $commentId,
                    $userId
                );
            } catch (\Exception $e) {
                Log::error('Failed to process attachment', [
                    'ticket_id' => $ticket->id,
                    'filename' => $attachmentData['filename'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Save uploaded file (using GenericAttachmentTrait for form uploads)
     *
     * @param \App\Model\Entity\Ticket|int $ticket Ticket entity or ID
     * @param \Psr\Http\Message\UploadedFileInterface $file Uploaded file
     * @param int|null $commentId Comment ID
     * @param int|null $userId User ID
     * @return \App\Model\Entity\Attachment|null
     */
    public function saveUploadedFile(
        \App\Model\Entity\Ticket|int $ticket,
        \Psr\Http\Message\UploadedFileInterface $file,
        ?int $commentId = null,
        ?int $userId = null
    ): ?\App\Model\Entity\Attachment {
        if (is_int($ticket)) {
            $ticketsTable = $this->fetchTable('Tickets');
            $ticket = $ticketsTable->get($ticket);
        }
        assert($ticket instanceof \App\Model\Entity\Ticket);

        $result = $this->saveGenericUploadedFile('ticket', $ticket, $file, $commentId, $userId);
        assert($result instanceof \App\Model\Entity\Attachment || $result === null);
        return $result;
    }

    /**
     * Handle a complete response (comment + status change + files + notifications)
     *
     * @param int $entityId The ticket ID
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

        Log::debug('Response email recipients', [
            'raw_email_to' => $data['email_to'] ?? null,
            'raw_email_cc' => $data['email_cc'] ?? null,
            'decoded_email_to' => $emailTo,
            'decoded_email_cc' => $emailCc,
        ]);

        $hasComment = !empty(trim($commentBody));

        $entity = $this->fetchTable('Tickets')->get($entityId);
        assert($entity instanceof Ticket);

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
            $comment = $this->addComment($entityId, $userId, $commentBody, 'ticket', $commentType, false, $emailTo, $emailCc);

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

        $this->sendResponseNotifications('ticket', $entity, $comment, $oldStatus, $newStatus, $hasComment, $commentType, $hasStatusChange, $emailTo, $emailCc);

        return $this->buildResponseResult($hasComment, $hasStatusChange, $uploadedCount, $entity);
    }

    /**
     * Convert ticket to compra (full workflow)
     *
     * This method handles the complete conversion workflow:
     * 1. Creates the compra from ticket
     * 2. Marks ticket as converted
     * 3. Copies all data (comments, attachments)
     *
     * @param \App\Model\Entity\Ticket $ticket Source ticket
     * @param int $userId User performing the conversion
     * @param \App\Service\ComprasService $comprasService Injected ComprasService
     * @return \App\Model\Entity\Compra|null Created compra or null on failure
     */
    public function convertToCompra(
        \App\Model\Entity\Ticket $ticket,
        int $userId,
        \App\Service\ComprasService $comprasService
    ): ?\App\Model\Entity\Compra {
        try {
            // Create compra from ticket
            $compra = $comprasService->createFromTicket($ticket, ['user_id' => $userId]);

            if (!$compra) {
                Log::error('Failed to create compra from ticket', ['ticket_id' => $ticket->id]);
                return null;
            }

            // Mark ticket as converted using trait method
            $this->markAsConverted('ticket', $ticket, 'compra', $compra, $userId);

            // Copy all data (comments and attachments)
            $comprasService->copyTicketData($ticket, $compra);

            Log::info('Ticket converted to Compra successfully', [
                'ticket_id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'compra_id' => $compra->id,
                'compra_number' => $compra->compra_number,
            ]);

            return $compra;

        } catch (\Exception $e) {
            Log::error('Error in convertToCompra: ' . $e->getMessage(), [
                'ticket_id' => $ticket->id,
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * Create ticket from compra
     *
     * Workflow: Compra → Convert → Ticket
     *
     * @param \App\Model\Entity\Compra $compra Source compra
     * @param array $data Additional data (assignee_id, user_id)
     * @return \App\Model\Entity\Ticket|null Created ticket or null on failure
     */
    public function createFromCompra(\App\Model\Entity\Compra $compra, array $data = []): ?\App\Model\Entity\Ticket
    {
        $ticketsTable = $this->fetchTable('Tickets');

        try {
            // Generate ticket number using Table method (avoids duplication)
            $ticketNumber = $ticketsTable->generateTicketNumber();

            // Create ticket from compra data
            $ticket = $ticketsTable->newEntity([
                'ticket_number' => $ticketNumber,
                'subject' => "{$compra->subject}",
                'description' => $compra->description,
                'status' => 'nuevo',
                'priority' => $compra->priority,
                'requester_id' => $compra->requester_id,
                'assignee_id' => $data['assignee_id'] ?? null,
                'channel' => $compra->channel ?? 'email',
                'email_to' => $compra->email_to,
                'email_cc' => $compra->email_cc,
                'source_email' => $compra->requester->email ?? null,
            ], ['accessibleFields' => [
                'ticket_number' => true, 'status' => true, 'requester_id' => true,
                'channel' => true, 'source_email' => true,
            ]]);

            if ($ticketsTable->save($ticket)) {
                // Log creation in ticket history
                $this->logHistory(
                    'TicketHistory',
                    'ticket_id',
                    $ticket->id,
                    'created_from_compra',
                    null,
                    $compra->compra_number,
                    $data['user_id'] ?? null,
                    "Creado desde Compra #{$compra->compra_number}"
                );

                return $ticket;
            }

            Log::error('Error al guardar ticket desde compra', ['compra_id' => $compra->id]);
            return null;

        } catch (\Exception $e) {
            Log::error('Error en createFromCompra: ' . $e->getMessage(), [
                'compra_id' => $compra->id,
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * Add tag to ticket
     *
     * @param int $ticketId Ticket ID
     * @param int $tagId Tag ID
     * @return array{success: bool, message: string}
     */
    public function addTag(int $ticketId, int $tagId): array
    {
        $ticketsTable = $this->fetchTable('Tickets');
        $ticketsTable->get($ticketId);

        $ticketTagsTable = $this->fetchTable('TicketTags');

        $exists = $ticketTagsTable->find()
            ->where(['ticket_id' => $ticketId, 'tag_id' => $tagId])
            ->count();

        if ($exists) {
            return ['success' => false, 'message' => 'Esta etiqueta ya está agregada.'];
        }

        $ticketTag = $ticketTagsTable->newEntity([
            'ticket_id' => $ticketId,
            'tag_id' => $tagId,
        ]);

        if ($ticketTagsTable->save($ticketTag)) {
            return ['success' => true, 'message' => 'Etiqueta agregada.'];
        }

        return ['success' => false, 'message' => 'Error al agregar la etiqueta.'];
    }

    /**
     * Remove tag from ticket
     *
     * @param int $ticketId Ticket ID
     * @param int $tagId Tag ID
     * @return array{success: bool, message: string}
     */
    public function removeTag(int $ticketId, int $tagId): array
    {
        $ticketTagsTable = $this->fetchTable('TicketTags');

        $ticketTag = $ticketTagsTable->find()
            ->where(['ticket_id' => $ticketId, 'tag_id' => $tagId])
            ->first();

        if ($ticketTag && $ticketTagsTable->delete($ticketTag)) {
            return ['success' => true, 'message' => 'Etiqueta eliminada.'];
        }

        return ['success' => false, 'message' => 'Error al eliminar la etiqueta.'];
    }

    /**
     * Add follower to ticket
     *
     * @param int $ticketId Ticket ID
     * @param int $userId User ID
     * @return array{success: bool, message: string}
     */
    public function addFollower(int $ticketId, int $userId): array
    {
        $followersTable = $this->fetchTable('TicketFollowers');

        $exists = $followersTable->find()
            ->where(['ticket_id' => $ticketId, 'user_id' => $userId])
            ->count();

        if ($exists) {
            return ['success' => false, 'message' => 'Este usuario ya está siguiendo el ticket.'];
        }

        $follower = $followersTable->newEntity([
            'ticket_id' => $ticketId,
            'user_id' => $userId,
        ]);

        if ($followersTable->save($follower)) {
            return ['success' => true, 'message' => 'Seguidor agregado.'];
        }

        return ['success' => false, 'message' => 'Error al agregar seguidor.'];
    }

    /**
     * Copy compra data (comments and attachments) to ticket
     *
     * REFACTORED: Now uses EntityConversionTrait for generic copying logic
     *
     * @param \App\Model\Entity\Compra $compra Source compra
     * @param \App\Model\Entity\Ticket $ticket Destination ticket
     * @return bool Success status
     */
    public function copyCompraData(\App\Model\Entity\Compra $compra, \App\Model\Entity\Ticket $ticket): bool
    {
        try {
            // Load compra with associations
            $comprasTable = $this->fetchTable('Compras');
            $compra = $comprasTable->get($compra->id, [
                'contain' => ['ComprasComments', 'ComprasAttachments']
            ]);

            // Copy comments using trait
            $commentsCopied = $this->copyComments('compra', $compra, 'ticket', $ticket);

            // Copy attachments using trait
            $attachmentsCopied = $this->copyAttachments(
                'compra',
                $compra,
                'ticket',
                $ticket,
                $ticket->ticket_number
            );

            Log::info('Copied compra data to ticket', [
                'compra_id' => $compra->id,
                'ticket_id' => $ticket->id,
                'comments_copied' => $commentsCopied,
                'attachments_copied' => $attachmentsCopied,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Error copiando datos de compra a ticket: ' . $e->getMessage(), [
                'compra_id' => $compra->id,
                'ticket_id' => $ticket->id,
                'exception' => $e,
            ]);
            return false;
        }
    }

    /**
     * Sanitize HTML content from emails to prevent stored XSS
     *
     * @param string $html Raw HTML content
     * @return string Sanitized HTML
     */
    private function sanitizeHtml(string $html): string
    {
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', 'p,br,b,i,u,strong,em,a[href],ul,ol,li,blockquote,h1,h2,h3,h4,h5,h6,img[src|alt|width|height],table,thead,tbody,tr,td,th,span,div,pre,code,hr');
        $config->set('HTML.TargetBlank', true);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('Cache.DefinitionImpl', null);

        $purifier = new \HTMLPurifier($config);

        return $purifier->purify($html);
    }
}
