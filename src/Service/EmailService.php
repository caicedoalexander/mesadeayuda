<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Traits\GenericAttachmentTrait;
use App\Utility\SettingKeys;
use App\Utility\SettingsEncryptionTrait;
use App\Utility\ValidationConstants;
use Cake\Core\Configure;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Log\Log;

/**
 * Email Service
 *
 * Handles all email notifications using templates from database:
 * - New ticket notifications
 * - Status change notifications
 * - New comment notifications
 */
class EmailService
{
    use LocatorAwareTrait;
    use SettingsEncryptionTrait;
    use GenericAttachmentTrait;
    use Traits\ConfigResolutionTrait;

    private \App\Service\Renderer\NotificationRenderer $renderer;
    private EmailTemplateRenderer $templateRenderer;
    private ?array $systemConfig = null;
    private ?GmailService $gmailService = null;

    /**
     * Constructor
     *
     * @param array|null $systemConfig Optional system configuration to avoid redundant DB queries
     */
    public function __construct(?array $systemConfig = null)
    {
        $this->renderer = new \App\Service\Renderer\NotificationRenderer();
        $this->templateRenderer = new EmailTemplateRenderer($systemConfig);
        $this->systemConfig = $systemConfig;
    }

    /**
     * Get a single setting value with cascading resolution
     *
     * Delegates to ConfigResolutionTrait::resolveSettingValue()
     *
     * @param string $key Setting key
     * @param string $default Default value
     * @return string Setting value
     */
    private function getSettingValue(string $key, string $default = ''): string
    {
        return $this->resolveSettingValue($key, $default);
    }

    /**
     * Get system-wide variables for email templates
     *
     * @return array System variables
     */
    private function getSystemVariables(): array
    {
        return $this->templateRenderer->getSystemVariables();
    }

    /**
     * Send new entity notification (generic for all entity types)
     *
     * @param string $entityType 'ticket', 'pqrs', 'compra'
     * @param \Cake\Datasource\EntityInterface $entity Entity instance
     * @return bool Success status
     */
    public function sendNewEntityNotification(string $entityType, \Cake\Datasource\EntityInterface $entity): bool
    {
        $templateKeys = [
            'ticket' => 'nuevo_ticket',
            'pqrs' => 'nuevo_pqrs',
            'compra' => 'nueva_compra',
        ];

        // Entity-specific pre-processing
        if ($entityType === 'ticket') {
            $ticketsTable = $this->fetchTable('Tickets');
            $entity = $ticketsTable->get($entity->id, contain: ['Requesters']);

            $excludeEmails = [
                strtolower($entity->requester->email),
                strtolower($this->getSettingValue(SettingKeys::GMAIL_USER_EMAIL)),
            ];
            $additionalTo = $this->filterEmailRecipients($entity->email_to, $excludeEmails);
            $additionalCc = $this->filterEmailRecipients($entity->email_cc, $excludeEmails);

            return $this->sendGenericTemplateEmail($entityType, $templateKeys[$entityType], $entity, [], [], $additionalTo, $additionalCc);
        }

        if ($entityType === 'compra') {
            $comprasTable = $this->fetchTable('Compras');
            $entity = $comprasTable->get($entity->id, contain: ['Assignees']);

            if (!$entity->assignee || !$entity->assignee->email) {
                Log::info('No assignee for compra, skipping email notification', ['compra_id' => $entity->id]);
                return true;
            }

            $slaDue = $entity->resolution_sla_due ?? $entity->sla_due_date ?? null;
            $extraVars = [
                'assignee_name' => $entity->assignee->name,
                'priority' => ucfirst($entity->priority),
                'sla_due_date' => $slaDue ? $this->renderer->formatDate($slaDue) : 'No definido',
            ];

            return $this->sendGenericTemplateEmail($entityType, $templateKeys[$entityType], $entity, $extraVars);
        }

        return $this->sendGenericTemplateEmail($entityType, $templateKeys[$entityType], $entity);
    }

    /**
     * Send status change notification (generic for all entity types)
     *
     * @param string $entityType 'ticket', 'pqrs', 'compra'
     * @param \Cake\Datasource\EntityInterface $entity Entity instance
     * @param string $oldStatus Old status
     * @param string $newStatus New status
     * @return bool Success status
     */
    public function sendEntityStatusChangeNotification(string $entityType, \Cake\Datasource\EntityInterface $entity, string $oldStatus, string $newStatus): bool
    {
        $templateKeys = [
            'ticket' => 'ticket_estado',
            'pqrs' => 'pqrs_estado',
            'compra' => 'compra_estado',
        ];

        $config = $this->getCommentNotificationConfig($entityType);
        $entityTable = $this->fetchTable($config['entityTable']);
        $entity = $entityTable->get($entity->id, contain: $config['entityContain']);

        $assigneeName = (isset($entity->assignee) && $entity->assignee) ? $entity->assignee->name : 'No asignado';

        return $this->sendGenericTemplateEmail($entityType, $templateKeys[$entityType], $entity, [
            'status_change_section' => $this->renderer->renderStatusChangeHtml($oldStatus, $newStatus, $assigneeName),
        ]);
    }

    /**
     * Send comment notification (generic for all entity types)
     *
     * @param string $entityType 'ticket', 'pqrs', 'compra'
     * @param \Cake\Datasource\EntityInterface $entity Entity instance
     * @param \Cake\Datasource\EntityInterface $comment Comment entity
     * @param array $additionalTo Additional To recipients
     * @param array $additionalCc Additional CC recipients
     * @return bool Success status
     */
    public function sendEntityCommentNotification(string $entityType, \Cake\Datasource\EntityInterface $entity, \Cake\Datasource\EntityInterface $comment, array $additionalTo = [], array $additionalCc = []): bool
    {
        $templateKeys = [
            'ticket' => 'nuevo_comentario',
            'pqrs' => 'pqrs_comentario',
            'compra' => 'compra_comentario',
        ];

        // For PQRS and Compra, only send for public comments
        if ($entityType !== 'ticket' && $comment->comment_type !== 'public') {
            return true;
        }

        return $this->sendCommentBasedNotification($entityType, $templateKeys[$entityType], $entity, $comment, null, null, $additionalTo, $additionalCc);
    }

    /**
     * Send response notification with comment + status change (generic)
     *
     * @param string $entityType 'ticket', 'pqrs', 'compra'
     * @param \Cake\Datasource\EntityInterface $entity Entity instance
     * @param \Cake\Datasource\EntityInterface $comment Comment entity
     * @param string $oldStatus Old status
     * @param string $newStatus New status
     * @param array $additionalTo Additional To recipients
     * @param array $additionalCc Additional CC recipients
     * @return bool Success status
     */
    public function sendEntityResponseNotification(string $entityType, \Cake\Datasource\EntityInterface $entity, \Cake\Datasource\EntityInterface $comment, string $oldStatus, string $newStatus, array $additionalTo = [], array $additionalCc = []): bool
    {
        $templateKeys = [
            'ticket' => 'ticket_respuesta',
            'pqrs' => 'pqrs_respuesta',
            'compra' => 'compra_respuesta',
        ];

        return $this->sendCommentBasedNotification($entityType, $templateKeys[$entityType], $entity, $comment, $oldStatus, $newStatus, $additionalTo, $additionalCc);
    }


    /**
     * Get email template from database (delegates to EmailTemplateRenderer)
     *
     * @param string $templateKey Template key
     * @return \App\Model\Entity\EmailTemplate|null
     */
    private function getTemplate(string $templateKey): ?\App\Model\Entity\EmailTemplate
    {
        return $this->templateRenderer->getTemplate($templateKey);
    }

    /**
     * Replace variables in template string (delegates to EmailTemplateRenderer)
     *
     * @param string $template Template string with {{variables}}
     * @param array $variables Associative array of variable name => value
     * @return string Template with replaced variables
     */
    private function replaceVariables(string $template, array $variables): string
    {
        return $this->templateRenderer->render($template, $variables);
    }

    /**
     * Get or create GmailService instance
     *
     * @return GmailService
     */
    private function getGmailService(): GmailService
    {
        if ($this->gmailService === null) {
            // Resolve Gmail settings using centralized config resolution
            $refreshToken = $this->getSettingValue(SettingKeys::GMAIL_REFRESH_TOKEN);
            if (!empty($refreshToken)) {
                $refreshToken = $this->decryptSetting($refreshToken, SettingKeys::GMAIL_REFRESH_TOKEN);
            }

            $clientSecretPath = $this->getSettingValue(
                SettingKeys::GMAIL_CLIENT_SECRET_PATH,
                CONFIG . 'google' . DS . 'client_secret.json'
            );

            $this->gmailService = new GmailService([
                'refresh_token' => $refreshToken,
                'client_secret_path' => $clientSecretPath,
            ]);
        }

        return $this->gmailService;
    }

    /**
     * Send email using Gmail API
     *
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $body HTML body
     * @param array $attachments Array of Attachment entities (optional)
     * @param array $additionalTo Additional To recipients [['name' => '', 'email' => ''], ...]
     * @param array $additionalCc Additional CC recipients [['name' => '', 'email' => ''], ...]
     * @return bool Success status
     */
    private function sendEmail(string $to, string $subject, string $body, array $attachments = [], array $additionalTo = [], array $additionalCc = []): bool
    {
        try {
            // Get system title and Gmail email using centralized config resolution
            $systemTitle = $this->getSettingValue(SettingKeys::SYSTEM_TITLE, ValidationConstants::DEFAULT_SYSTEM_TITLE);
            $fromEmail = $this->getSettingValue(SettingKeys::GMAIL_USER_EMAIL, 'noreply@localhost');

            // Build recipients array for Gmail API
            $toRecipients = [$to => $to]; // Primary recipient

            // Add additional To recipients
            if (!empty($additionalTo)) {
                foreach ($additionalTo as $recipient) {
                    if (!empty($recipient['email'])) {
                        $toRecipients[$recipient['email']] = $recipient['name'] ?? $recipient['email'];
                    }
                }
            }

            // Build CC recipients
            $ccRecipients = [];
            if (!empty($additionalCc)) {
                foreach ($additionalCc as $recipient) {
                    if (!empty($recipient['email'])) {
                        $ccRecipients[$recipient['email']] = $recipient['name'] ?? $recipient['email'];
                    }
                }
            }

            // Build attachment file paths using GenericAttachmentTrait
            $attachmentPaths = [];
            if (!empty($attachments)) {
                foreach ($attachments as $attachment) {
                    // Use unified getFullPath() from GenericAttachmentTrait
                    // (works for Ticket, PQRS, and Compra attachments)
                    $filePath = $this->getFullPath($attachment);

                    if (file_exists($filePath)) {
                        $attachmentPaths[] = $filePath;
                    }
                }
            }

            // Build options for Gmail API
            $options = [
                'from' => [$fromEmail => $systemTitle],
                'headers' => ['X-Mesa-Ayuda-Notification' => 'true'],
            ];

            if (!empty($ccRecipients)) {
                $options['cc'] = $ccRecipients;
            }

            // Send via Gmail API
            $gmailService = $this->getGmailService();
            $result = $gmailService->sendEmail($toRecipients, $subject, $body, $attachmentPaths, $options);

            if ($result) {
                Log::info('Email sent successfully via Gmail API', ['to' => $to, 'subject' => $subject]);
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('Failed to send email via Gmail API', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }



    /**
     * Send comment-based notification (comment or response) for any entity type
     *
     * Consolidates the shared logic of all 6 comment/response notification methods:
     * - Loading entity with attachments and comment with users
     * - Filtering comment attachments (non-inline)
     * - Building agent profile image URL
     * - Building template variables
     * - Sending email with attachments
     *
     * @param string $entityType 'ticket', 'pqrs', 'compra'
     * @param string $templateKey Template key from database
     * @param \Cake\Datasource\EntityInterface $entity Entity instance
     * @param \Cake\Datasource\EntityInterface $comment Comment entity
     * @param string|null $oldStatus Old status (null for comment-only notifications)
     * @param string|null $newStatus New status (null for comment-only notifications)
     * @param array $additionalTo Additional To recipients
     * @param array $additionalCc Additional CC recipients
     * @return bool Success status
     */
    private function sendCommentBasedNotification(
        string $entityType,
        string $templateKey,
        \Cake\Datasource\EntityInterface $entity,
        \Cake\Datasource\EntityInterface $comment,
        ?string $oldStatus,
        ?string $newStatus,
        array $additionalTo = [],
        array $additionalCc = []
    ): bool {
        try {
            $config = $this->getCommentNotificationConfig($entityType);

            // Load entity with associations (including attachments)
            $entityTable = $this->fetchTable($config['entityTable']);
            $entity = $entityTable->get($entity->id, contain: $config['entityContain']);

            // Load comment with user association
            $commentsTable = $this->fetchTable($config['commentsTable']);
            $comment = $commentsTable->get($comment->id, contain: ['Users']);

            // Filter comment attachments (non-inline only)
            $commentAttachments = [];
            $attachmentsProperty = $config['attachmentsProperty'];
            if (!empty($entity->{$attachmentsProperty})) {
                $commentFk = $config['commentForeignKey'];
                foreach ($entity->{$attachmentsProperty} as $attachment) {
                    if ($attachment->{$commentFk} === $comment->id && !$attachment->is_inline) {
                        $commentAttachments[] = $attachment;
                    }
                }
            }

            // Get template
            $template = $this->getTemplate($templateKey);
            if (!$template) {
                Log::error("Email template not found: {$templateKey}");
                return false;
            }

            // Get agent profile image
            $author = $comment->user ? $comment->user->name : 'Sistema';
            $agentProfileImageUrl = $this->getAgentProfileImageUrl($comment->user);

            // Build variables
            $variables = array_merge($this->getSystemVariables(), $this->buildCommentVariables($entityType, $entity, $comment, $author, $agentProfileImageUrl, $commentAttachments));

            // Add status change section if this is a response notification
            if ($oldStatus !== null && $newStatus !== null) {
                $hasStatusChange = ($oldStatus !== $newStatus);
                $assigneeName = (isset($entity->assignee) && $entity->assignee) ? $entity->assignee->name : 'No asignado';
                $variables['status_change_section'] = $hasStatusChange
                    ? $this->renderer->renderStatusChangeHtml($oldStatus, $newStatus, $assigneeName)
                    : '';
            }

            // Replace variables and send
            $subject = $this->replaceVariables($template->subject, $variables);
            $body = $this->replaceVariables($template->body_html, $variables);
            $recipientEmail = $this->getRecipientEmail($entityType, $entity);

            return $this->sendEmail($recipientEmail, $subject, $body, $commentAttachments, $additionalTo, $additionalCc);
        } catch (\Exception $e) {
            Log::error("Failed to send {$entityType} comment notification", [
                'entity_id' => $entity->id,
                'comment_id' => $comment->id,
                'template' => $templateKey,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get configuration for comment-based notifications per entity type
     *
     * @param string $entityType 'ticket', 'pqrs', 'compra'
     * @return array Configuration array
     */
    private function getCommentNotificationConfig(string $entityType): array
    {
        return match ($entityType) {
            'ticket' => [
                'entityTable' => 'Tickets',
                'commentsTable' => 'TicketComments',
                'entityContain' => ['Requesters', 'Assignees', 'Attachments'],
                'attachmentsProperty' => 'attachments',
                'commentForeignKey' => 'comment_id',
            ],
            'pqrs' => [
                'entityTable' => 'Pqrs',
                'commentsTable' => 'PqrsComments',
                'entityContain' => ['Assignees', 'PqrsAttachments'],
                'attachmentsProperty' => 'pqrs_attachments',
                'commentForeignKey' => 'pqrs_comment_id',
            ],
            'compra' => [
                'entityTable' => 'Compras',
                'commentsTable' => 'ComprasComments',
                'entityContain' => ['Requesters', 'Assignees', 'ComprasAttachments'],
                'attachmentsProperty' => 'compras_attachments',
                'commentForeignKey' => 'compras_comment_id',
            ],
        };
    }

    /**
     * Build comment-specific template variables for entity type
     *
     * @param string $entityType 'ticket', 'pqrs', 'compra'
     * @param \Cake\Datasource\EntityInterface $entity Entity instance
     * @param \Cake\Datasource\EntityInterface $comment Comment entity
     * @param string $author Author name
     * @param string $agentProfileImageUrl Agent profile image URL
     * @param array $commentAttachments Comment attachments
     * @return array Template variables
     */
    private function buildCommentVariables(
        string $entityType,
        \Cake\Datasource\EntityInterface $entity,
        \Cake\Datasource\EntityInterface $comment,
        string $author,
        string $agentProfileImageUrl,
        array $commentAttachments
    ): array {
        $config = $this->getCommentNotificationConfig($entityType);

        // Common variables for all entity types
        $common = [
            'subject' => $entity->subject,
            'comment_author' => $author,
            'comment_body' => $comment->body,
            'attachments_list' => $this->renderer->renderAttachmentsHtml($commentAttachments),
            'agent_profile_image_url' => $agentProfileImageUrl,
            'agent_name' => $author,
        ];

        // Entity-specific variables
        $entityVars = match ($entityType) {
            'ticket' => [
                'ticket_number' => $entity->ticket_number,
                'requester_name' => $entity->requester->name ?? 'N/A',
                'ticket_url' => $this->renderer->getTicketUrl($entity->id),
            ],
            'pqrs' => [
                'pqrs_number' => $entity->pqrs_number,
                'pqrs_type' => $this->renderer->getTypeLabel($entity->type),
                'requester_name' => $entity->requester_name ?? 'N/A',
            ],
            'compra' => [
                'compra_number' => $entity->compra_number,
                'requester_name' => $entity->requester->name ?? 'N/A',
                'compra_url' => $this->getCompraUrl($entity->id),
            ],
        };

        return array_merge($common, $entityVars);
    }

    /**
     * Get agent profile image URL from comment user
     *
     * @param \App\Model\Entity\User|null $user User entity
     * @return string Absolute URL for profile image
     */
    private function getAgentProfileImageUrl(?\App\Model\Entity\User $user): string
    {
        $userHelper = new \App\View\Helper\UserHelper($this->getView());
        $url = ($user && $user->profile_image)
            ? $userHelper->profileImage($user->profile_image)
            : $userHelper->defaultAvatar();

        return $this->getAbsoluteUrl($url);
    }

    /**
     * Get Compra URL
     *
     * @param int $id Compra ID
     * @return string Full URL
     */
    private function getCompraUrl(int $id): string
    {
        $baseUrl = Configure::read('App.fullBaseUrl', 'http://localhost:8765');
        return $baseUrl . '/compras/view/' . $id;
    }

    /**
     * Get a View instance for helpers
     *
     * @return \Cake\View\View
     */
    private function getView(): \Cake\View\View
    {
        return new \Cake\View\View();
    }

    /**
     * Convert relative URL to absolute URL for email
     *
     * @param string $relativeUrl Relative URL (e.g., /uploads/profile_images/user_1.jpg)
     * @return string Absolute URL (e.g., http://localhost:8765/uploads/profile_images/user_1.jpg)
     */
    private function getAbsoluteUrl(string $relativeUrl): string
    {
        // If it's already an absolute URL or data URI, return as-is
        if (
            str_starts_with($relativeUrl, 'http://') ||
            str_starts_with($relativeUrl, 'https://') ||
            str_starts_with($relativeUrl, 'data:')
        ) {
            return $relativeUrl;
        }

        // Get base URL from request or configuration
        $baseUrl = \Cake\Routing\Router::url('/', true);

        // Remove trailing slash from base URL
        $baseUrl = rtrim($baseUrl, '/');

        // Ensure relative URL starts with /
        if (!str_starts_with($relativeUrl, '/')) {
            $relativeUrl = '/' . $relativeUrl;
        }

        return $baseUrl . $relativeUrl;
    }

    /**
     * Filter email recipients, excluding specified email addresses
     *
     * Parses JSON-encoded or array recipients and removes excluded emails.
     *
     * @param string|array|null $recipients JSON string or array of ['name' => '', 'email' => '']
     * @param array $excludeEmails Lowercase email addresses to exclude
     * @return array Filtered recipients
     */
    private function filterEmailRecipients(string|array|null $recipients, array $excludeEmails): array
    {
        if (empty($recipients)) {
            return [];
        }

        $decoded = is_string($recipients) ? json_decode($recipients, true) : $recipients;
        if (!is_array($decoded)) {
            return [];
        }

        $filtered = [];
        foreach ($decoded as $recipient) {
            if (!empty($recipient['email'])) {
                $email = strtolower($recipient['email']);
                if (!in_array($email, $excludeEmails, true)) {
                    $filtered[] = $recipient;
                }
            }
        }

        return $filtered;
    }

    /**
     * Send email using template (generic for all entity types)
     *
     * INTERNAL METHOD: Consolidates duplicate notification logic
     *
     * @param string $entityType 'ticket', 'pqrs', 'compra'
     * @param string $templateKey Template key from database
     * @param \Cake\Datasource\EntityInterface $entity Entity instance
     * @param array $extraVariables Additional template variables
     * @param array $attachments Attachment entities
     * @param array $additionalTo Additional To recipients
     * @param array $additionalCc Additional CC recipients
     * @return bool Success status
     */
    private function sendGenericTemplateEmail(
        string $entityType,
        string $templateKey,
        \Cake\Datasource\EntityInterface $entity,
        array $extraVariables = [],
        array $attachments = [],
        array $additionalTo = [],
        array $additionalCc = []
    ): bool {
        try {
            // Load entity with associations
            $entity = $this->loadEntityWithAssociations($entityType, $entity);

            // Get template
            $template = $this->getTemplate($templateKey);
            if (!$template) {
                Log::error("Email template not found: {$templateKey}");
                return false;
            }

            // Build variables
            $variables = $this->buildTemplateVariables($entityType, $entity, $extraVariables);

            // Replace variables
            $subject = $this->replaceVariables($template->subject, $variables);
            $body = $this->replaceVariables($template->body_html, $variables);

            // Get recipient
            $recipientEmail = $this->getRecipientEmail($entityType, $entity);

            // Send email
            return $this->sendEmail($recipientEmail, $subject, $body, $attachments, $additionalTo, $additionalCc);

        } catch (\Exception $e) {
            Log::error("Failed to send {$entityType} email", [
                'template' => $templateKey,
                'entity_id' => $entity->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Load entity with proper associations based on entity type
     *
     * @param string $entityType 'ticket', 'pqrs', 'compra'
     * @param \Cake\Datasource\EntityInterface $entity Entity instance
     * @return \Cake\Datasource\EntityInterface Loaded entity
     */
    private function loadEntityWithAssociations(
        string $entityType,
        \Cake\Datasource\EntityInterface $entity
    ): \Cake\Datasource\EntityInterface {
        $config = match ($entityType) {
            'ticket' => [
                'table' => 'Tickets',
                'contain' => ['Requesters', 'Assignees', 'Attachments'],
            ],
            'pqrs' => [
                'table' => 'Pqrs',
                'contain' => ['Assignees', 'PqrsAttachments'],
            ],
            'compra' => [
                'table' => 'Compras',
                'contain' => ['Requesters', 'Assignees', 'ComprasAttachments'],
            ],
        };

        $table = $this->fetchTable($config['table']);
        return $table->get($entity->id, contain: $config['contain']);
    }

    /**
     * Build template variables for entity type
     *
     * @param string $entityType 'ticket', 'pqrs', 'compra'
     * @param \Cake\Datasource\EntityInterface $entity Entity instance
     * @param array $extraVariables Additional variables to merge
     * @return array Template variables
     */
    private function buildTemplateVariables(
        string $entityType,
        \Cake\Datasource\EntityInterface $entity,
        array $extraVariables
    ): array {
        $systemVars = $this->getSystemVariables();

        $entityVars = match ($entityType) {
            'ticket' => [
                'ticket_number' => $entity->ticket_number,
                'subject' => $entity->subject,
                'requester_name' => $entity->requester->name ?? 'N/A',
                'created_date' => $this->renderer->formatDate($entity->created),
                'ticket_url' => $this->renderer->getTicketUrl($entity->id),
            ],
            'pqrs' => [
                'pqrs_number' => $entity->pqrs_number,
                'pqrs_type' => $this->renderer->getTypeLabel($entity->type),
                'subject' => $entity->subject,
                'requester_name' => $entity->requester_name,
                'created_date' => $this->renderer->formatDate($entity->created),
            ],
            'compra' => [
                'compra_number' => $entity->compra_number,
                'subject' => $entity->subject,
                'requester_name' => $entity->requester->name ?? 'N/A',
                'created_date' => $this->renderer->formatDate($entity->created),
                'compra_url' => $this->getCompraUrl($entity->id),
            ],
        };

        return array_merge($systemVars, $entityVars, $extraVariables);
    }

    /**
     * Get recipient email for entity type
     *
     * @param string $entityType 'ticket', 'pqrs', 'compra'
     * @param \Cake\Datasource\EntityInterface $entity Entity instance
     * @return string Recipient email address
     */
    private function getRecipientEmail(
        string $entityType,
        \Cake\Datasource\EntityInterface $entity
    ): string {
        return match ($entityType) {
            'ticket' => $entity->requester->email ?? '',
            'pqrs' => $entity->requester_email ?? '',
            'compra' => $entity->requester->email ?? '',
        };
    }
}
