<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Attachment;
use App\Model\Entity\Ticket;
use App\Model\Entity\TicketComment;
use App\Model\Entity\User;
use App\Service\Traits\GenericAttachmentTrait;
use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Exception;
use HTMLPurifier;
use HTMLPurifier_Config;
use Psr\Http\Message\UploadedFileInterface;

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
    use GenericAttachmentTrait;

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
     * @return \App\Service\N8nService
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
    public function createFromEmail(array $emailData): ?Ticket
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
        assert($ticket instanceof Ticket);

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
        $this->dispatchCreationNotifications($ticket);

        // Send n8n webhook for AI tag assignment (lazy loaded only when creating tickets)
        try {
            $this->getN8nService()->sendTicketCreatedWebhook($ticket);
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
     * Create comment from email response in existing thread
     *
     * @param \App\Model\Entity\Ticket $ticket The ticket to add comment to
     * @param array $emailData Parsed email data from GmailService
     * @return \App\Model\Entity\TicketComment|null Created comment or null
     */
    public function createCommentFromEmail(Ticket $ticket, array $emailData): ?TicketComment
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

        // Create new user with role 'requester' and null password
        $user = $usersTable->newEntity([
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'role' => 'requester',
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
     * Check if email address is in ticket's original To/CC recipients
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
            $ticket = $ticketsTable->get($ticket->id, contain: ['Requesters']);
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
    private function processEmailAttachments(EntityInterface $ticket, array $attachments, int $userId, ?int $commentId = null): void
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
     * Save uploaded file (using GenericAttachmentTrait for form uploads)
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
            $comment = $this->addComment($entityId, $userId, $commentBody, $commentType, false, $emailTo, $emailCc);

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

        $this->sendResponseNotifications($entity, $comment, $oldStatus, $newStatus, $hasComment, $commentType, $hasStatusChange, $emailTo, $emailCc);

        return $this->buildResponseResult($hasComment, $hasStatusChange, $uploadedCount, $entity);
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
     * Sanitize HTML content from emails to prevent stored XSS
     *
     * @param string $html Raw HTML content
     * @return string Sanitized HTML
     */
    private function sanitizeHtml(string $html): string
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', 'p,br,b,i,u,strong,em,a[href],ul,ol,li,blockquote,h1,h2,h3,h4,h5,h6,img[src|alt|width|height],table,thead,tbody,tr,td,th,span,div,pre,code,hr');
        $config->set('HTML.TargetBlank', true);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('Cache.DefinitionImpl', null);

        $purifier = new HTMLPurifier($config);

        return $purifier->purify($html);
    }

    // region: TicketSystem

    /**
     * Change ticket status.
     */
    public function changeStatus(
        EntityInterface $entity,
        string $newStatus,
        ?int $userId = null,
        ?string $comment = null,
        bool $sendNotifications = true,
    ): bool {
        $table = $this->fetchTable('Tickets');
        $oldStatus = $entity->status;

        if ($oldStatus === $newStatus) {
            return true;
        }

        $entity->status = $newStatus;

        $now = DateTime::now();
        if ($newStatus === 'resuelto' && !$entity->resolved_at) {
            $entity->resolved_at = $now;
        }
        if ($newStatus === 'cerrado' && isset($entity->closed_at) && !$entity->closed_at) {
            $entity->closed_at = $now;
        }

        if (!$table->save($entity)) {
            Log::error('Failed to change status', ['errors' => $entity->getErrors()]);

            return false;
        }

        $this->logHistory(
            'TicketHistory',
            'ticket_id',
            $entity->id,
            'status',
            $oldStatus,
            $newStatus,
            $userId,
            "Estado cambiado de '{$oldStatus}' a '{$newStatus}'",
        );

        $systemComment = $comment ?? "El estado cambió de '{$oldStatus}' a '{$newStatus}'";
        $this->addComment($entity->id, $userId, $systemComment, 'internal', true);

        if ($sendNotifications) {
            try {
                $this->emailService->sendEntityStatusChangeNotification($entity, $oldStatus, $newStatus);
            } catch (Exception $e) {
                Log::error('Failed to send status change email notification: ' . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * Add comment to a ticket.
     *
     * NOTE: This method does NOT send notifications. Notifications are handled
     * by the service's handleResponse() via sendResponseNotifications() for proper
     * coordination of comment + status change + file uploads.
     */
    public function addComment(
        int $entityId,
        ?int $userId,
        string $body,
        string $type = 'public',
        bool $isSystem = false,
        ?array $emailTo = null,
        ?array $emailCc = null,
    ): ?EntityInterface {
        $commentsTable = $this->fetchTable('TicketComments');

        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', 'p,br,b,i,u,strong,em,a[href],ul,ol,li,blockquote,h1,h2,h3,h4,h5,h6,img[src|alt|width|height],table,thead,tbody,tr,td,th,span,div,pre,code,hr');
        $config->set('HTML.TargetBlank', true);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('Cache.DefinitionImpl', null);
        $purifier = new HTMLPurifier($config);
        $sanitizedBody = $purifier->purify($body);

        $data = [
            'ticket_id' => $entityId,
            'user_id' => $userId,
            'comment_type' => $type,
            'body' => $sanitizedBody,
            'is_system_comment' => $isSystem,
        ];

        if ($type === 'public' && !$isSystem) {
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

    /**
     * Assign ticket to a user.
     */
    public function assign(
        EntityInterface $entity,
        ?int $assigneeId,
        ?int $userId = null,
    ): bool {
        $table = $this->fetchTable('Tickets');
        $usersTable = $this->fetchTable('Users');

        $oldAssigneeId = $entity->assignee_id;
        $entity->assignee_id = $assigneeId === 0 || $assigneeId === '0' ? null : $assigneeId;

        if (!$table->save($entity)) {
            $errors = $entity->getErrors();
            Log::error("Failed to assign ticket - ID: {$entity->id}");
            Log::error("Assignment details - New assignee: {$assigneeId}, Old assignee: {$oldAssigneeId}");
            Log::error('Validation errors: ' . print_r($errors, true));
            Log::error('Dirty fields: ' . print_r($entity->getDirty(), true));

            return false;
        }

        $oldAssigneeName = 'Sin asignar';
        if ($oldAssigneeId) {
            $oldUser = $usersTable->get($oldAssigneeId);
            $oldAssigneeName = $oldUser->first_name . ' ' . $oldUser->last_name;
        }

        $newAssigneeName = 'Sin asignar';
        if ($assigneeId) {
            $newUser = $usersTable->get($assigneeId);
            $newAssigneeName = $newUser->first_name . ' ' . $newUser->last_name;
        }

        $this->logHistory(
            'TicketHistory',
            'ticket_id',
            $entity->id,
            'assignee_id',
            $oldAssigneeName,
            $newAssigneeName,
            $userId,
            "Asignado a {$newAssigneeName}",
        );

        $this->addComment($entity->id, $userId, "Asignado a {$newAssigneeName}", 'internal', true);

        return true;
    }

    /**
     * Change ticket priority.
     */
    public function changePriority(
        EntityInterface $entity,
        string $newPriority,
        ?int $userId = null,
    ): bool {
        $table = $this->fetchTable('Tickets');
        $oldPriority = $entity->priority;

        if ($oldPriority === $newPriority) {
            return true;
        }

        $entity->priority = $newPriority;

        if (!$table->save($entity)) {
            Log::error('Failed to change priority', ['errors' => $entity->getErrors()]);

            return false;
        }

        $this->logHistory(
            'TicketHistory',
            'ticket_id',
            $entity->id,
            'priority',
            $oldPriority,
            $newPriority,
            $userId,
            "Prioridad cambiada de '{$oldPriority}' a '{$newPriority}'",
        );

        $this->addComment(
            $entity->id,
            $userId,
            "Prioridad cambiada de '{$oldPriority}' a '{$newPriority}'",
            'internal',
            true,
        );

        return true;
    }

    /**
     * Log change to ticket history.
     */
    private function logHistory(
        string $tableName,
        string $foreignKey,
        int $entityId,
        string $fieldName,
        ?string $oldValue,
        ?string $newValue,
        ?int $userId = null,
        ?string $description = null,
    ): void {
        $historyTable = $this->fetchTable($tableName);

        if (method_exists($historyTable, 'logChange')) {
            $historyTable->logChange($entityId, $fieldName, $oldValue, $newValue, $userId, $description);
        } else {
            $history = $historyTable->newEntity([
                $foreignKey => $entityId,
                'changed_by' => $userId,
                'field_name' => $fieldName,
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'description' => $description,
            ], ['accessibleFields' => ['changed_by' => true]]);
            $historyTable->save($history);
        }
    }

    /**
     * Send notifications based on response changes (comment + status + files).
     */
    protected function sendResponseNotifications(
        $entity,
        $comment,
        string $oldStatus,
        ?string $newStatus,
        bool $hasComment,
        string $commentType,
        bool $hasStatusChange,
        array $emailTo = [],
        array $emailCc = [],
    ): void {
        $hasPublicComment = $hasComment && $commentType === 'public';

        if ($hasPublicComment && $hasStatusChange && $comment) {
            $this->dispatchUpdateNotifications($entity, 'response', [
                'comment' => $comment,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'additional_to' => $emailTo,
                'additional_cc' => $emailCc,
            ]);
        } elseif ($hasPublicComment && $comment) {
            $this->dispatchUpdateNotifications($entity, 'comment', [
                'comment' => $comment,
                'additional_to' => $emailTo,
                'additional_cc' => $emailCc,
            ]);
        } elseif ($hasStatusChange) {
            $this->dispatchUpdateNotifications($entity, 'status_change', [
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]);
        }
    }

    /**
     * Build success message for response operations.
     */
    protected function buildResponseResult(bool $hasComment, bool $hasStatusChange, int $uploadedCount, $entity): array
    {
        $successMessage = '';
        if ($hasComment && $hasStatusChange) {
            $successMessage = 'Comentario agregado y estado actualizado exitosamente.';
        } elseif ($hasComment) {
            $successMessage = 'Comentario agregado exitosamente.';
        } elseif ($hasStatusChange) {
            $successMessage = 'Estado actualizado exitosamente.';
        }

        if ($uploadedCount > 0) {
            $successMessage .= " ({$uploadedCount} archivo(s) adjunto(s))";
        }

        return [
            'success' => true,
            'message' => $successMessage,
            'entity' => $entity,
        ];
    }

    /**
     * Decode email recipients from JSON string or array.
     */
    protected function decodeEmailRecipients($data): array
    {
        if (empty($data)) {
            return [];
        }

        if (is_string($data)) {
            $decoded = json_decode($data, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        if (is_array($data)) {
            return $data;
        }

        return [];
    }

    // endregion

    // region: NotificationDispatcher

    /**
     * Dispatch creation notifications (Email + WhatsApp).
     */
    public function dispatchCreationNotifications(
        EntityInterface $entity,
        bool $sendEmail = true,
        bool $sendWhatsapp = true,
    ): void {
        if ($sendEmail) {
            try {
                $this->emailService->sendNewEntityNotification($entity);
            } catch (Exception $e) {
                Log::error('Failed to send ticket creation email', [
                    'error' => $e->getMessage(),
                    'entity_id' => $entity->id,
                ]);
            }
        }

        if ($sendWhatsapp) {
            try {
                $this->whatsappService->sendNewEntityNotification($entity);
            } catch (Exception $e) {
                Log::error('Failed to send ticket creation WhatsApp', [
                    'error' => $e->getMessage(),
                    'entity_id' => $entity->id,
                ]);
            }
        }
    }

    /**
     * Dispatch update notifications (Email only).
     *
     * @param string $notificationType 'status_change', 'comment', 'response'
     * @param array $context Additional context (old_status, new_status, comment, etc.)
     */
    public function dispatchUpdateNotifications(
        EntityInterface $entity,
        string $notificationType,
        array $context = [],
    ): void {
        try {
            switch ($notificationType) {
                case 'status_change':
                    $this->emailService->sendEntityStatusChangeNotification(
                        $entity,
                        $context['old_status'] ?? '',
                        $context['new_status'] ?? '',
                    );
                    break;

                case 'comment':
                    $this->emailService->sendEntityCommentNotification(
                        $entity,
                        $context['comment'] ?? null,
                        $context['additional_to'] ?? [],
                        $context['additional_cc'] ?? [],
                    );
                    break;

                case 'response':
                    $this->emailService->sendEntityResponseNotification(
                        $entity,
                        $context['comment'] ?? null,
                        $context['old_status'] ?? '',
                        $context['new_status'] ?? '',
                        $context['additional_to'] ?? [],
                        $context['additional_cc'] ?? [],
                    );
                    break;

                default:
                    Log::warning("Unknown notification type: {$notificationType}");
            }
        } catch (Exception $e) {
            Log::error("Failed to send ticket {$notificationType} email", [
                'error' => $e->getMessage(),
                'entity_id' => $entity->id,
            ]);
        }
    }

    // endregion
}
