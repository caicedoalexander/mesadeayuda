<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Traits\GenericAttachmentTrait;
use App\Utility\SettingKeys;
use App\Utility\ValidationConstants;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Log\Log;

/**
 * Email Service
 *
 * Sends ticket notifications via Gmail API using templates from database:
 * - New ticket
 * - Status change
 * - New comment
 * - Response (comment + status change)
 */
class EmailService
{
    use LocatorAwareTrait;
    use GenericAttachmentTrait;
    use Traits\ConfigResolutionTrait;

    private const ENTITY_TABLE = 'Tickets';
    private const COMMENTS_TABLE = 'TicketComments';
    private const ENTITY_CONTAIN = ['Requesters', 'Assignees', 'Attachments'];
    private const ATTACHMENTS_PROPERTY = 'attachments';
    private const COMMENT_FOREIGN_KEY = 'comment_id';

    private \App\Service\Renderer\NotificationRenderer $renderer;
    private EmailTemplateRenderer $templateRenderer;
    private ?array $systemConfig = null;
    private ?GmailService $gmailService = null;

    public function __construct(?array $systemConfig = null)
    {
        $this->renderer = new \App\Service\Renderer\NotificationRenderer();
        $this->templateRenderer = new EmailTemplateRenderer($systemConfig);
        $this->systemConfig = $systemConfig;
    }

    private function getSettingValue(string $key, string $default = ''): string
    {
        return $this->resolveSettingValue($key, $default);
    }

    private function getSystemVariables(): array
    {
        return $this->templateRenderer->getSystemVariables();
    }

    public function sendNewEntityNotification(\Cake\Datasource\EntityInterface $entity): bool
    {
        $ticketsTable = $this->fetchTable(self::ENTITY_TABLE);
        $entity = $ticketsTable->get($entity->id, contain: ['Requesters']);

        $excludeEmails = [
            strtolower($entity->requester->email),
            strtolower($this->getSettingValue(SettingKeys::GMAIL_USER_EMAIL)),
        ];
        $additionalTo = $this->filterEmailRecipients($entity->email_to, $excludeEmails);
        $additionalCc = $this->filterEmailRecipients($entity->email_cc, $excludeEmails);

        return $this->sendGenericTemplateEmail('nuevo_ticket', $entity, [], [], $additionalTo, $additionalCc);
    }

    public function sendEntityStatusChangeNotification(\Cake\Datasource\EntityInterface $entity, string $oldStatus, string $newStatus): bool
    {
        $entityTable = $this->fetchTable(self::ENTITY_TABLE);
        $entity = $entityTable->get($entity->id, contain: self::ENTITY_CONTAIN);

        $assigneeName = (isset($entity->assignee) && $entity->assignee) ? $entity->assignee->name : 'No asignado';

        return $this->sendGenericTemplateEmail('ticket_estado', $entity, [
            'status_change_section' => $this->renderer->renderStatusChangeHtml($oldStatus, $newStatus, $assigneeName),
        ]);
    }

    public function sendEntityCommentNotification(\Cake\Datasource\EntityInterface $entity, \Cake\Datasource\EntityInterface $comment, array $additionalTo = [], array $additionalCc = []): bool
    {
        return $this->sendCommentBasedNotification('nuevo_comentario', $entity, $comment, null, null, $additionalTo, $additionalCc);
    }

    public function sendEntityResponseNotification(\Cake\Datasource\EntityInterface $entity, \Cake\Datasource\EntityInterface $comment, string $oldStatus, string $newStatus, array $additionalTo = [], array $additionalCc = []): bool
    {
        return $this->sendCommentBasedNotification('ticket_respuesta', $entity, $comment, $oldStatus, $newStatus, $additionalTo, $additionalCc);
    }

    private function getTemplate(string $templateKey): ?\App\Model\Entity\EmailTemplate
    {
        return $this->templateRenderer->getTemplate($templateKey);
    }

    private function replaceVariables(string $template, array $variables): string
    {
        return $this->templateRenderer->render($template, $variables);
    }

    private function getGmailService(): GmailService
    {
        if ($this->gmailService === null) {
            $this->gmailService = new GmailService(GmailService::loadConfigFromDatabase());
        }

        return $this->gmailService;
    }

    private function sendEmail(string $to, string $subject, string $body, array $attachments = [], array $additionalTo = [], array $additionalCc = []): bool
    {
        try {
            $systemTitle = $this->getSettingValue(SettingKeys::SYSTEM_TITLE, ValidationConstants::DEFAULT_SYSTEM_TITLE);
            $fromEmail = $this->getSettingValue(SettingKeys::GMAIL_USER_EMAIL, 'noreply@localhost');

            $toRecipients = [$to => $to];

            if (!empty($additionalTo)) {
                foreach ($additionalTo as $recipient) {
                    if (!empty($recipient['email'])) {
                        $toRecipients[$recipient['email']] = $recipient['name'] ?? $recipient['email'];
                    }
                }
            }

            $ccRecipients = [];
            if (!empty($additionalCc)) {
                foreach ($additionalCc as $recipient) {
                    if (!empty($recipient['email'])) {
                        $ccRecipients[$recipient['email']] = $recipient['name'] ?? $recipient['email'];
                    }
                }
            }

            $attachmentPaths = [];
            if (!empty($attachments)) {
                foreach ($attachments as $attachment) {
                    $filePath = $this->getFullPath($attachment);
                    if (file_exists($filePath)) {
                        $attachmentPaths[] = $filePath;
                    }
                }
            }

            $options = [
                'from' => [$fromEmail => $systemTitle],
                'headers' => ['X-Mesa-Ayuda-Notification' => 'true'],
            ];

            if (!empty($ccRecipients)) {
                $options['cc'] = $ccRecipients;
            }

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

    private function sendCommentBasedNotification(
        string $templateKey,
        \Cake\Datasource\EntityInterface $entity,
        \Cake\Datasource\EntityInterface $comment,
        ?string $oldStatus,
        ?string $newStatus,
        array $additionalTo = [],
        array $additionalCc = []
    ): bool {
        try {
            $entityTable = $this->fetchTable(self::ENTITY_TABLE);
            $entity = $entityTable->get($entity->id, contain: self::ENTITY_CONTAIN);

            $commentsTable = $this->fetchTable(self::COMMENTS_TABLE);
            $comment = $commentsTable->get($comment->id, contain: ['Users']);

            $commentAttachments = [];
            if (!empty($entity->{self::ATTACHMENTS_PROPERTY})) {
                foreach ($entity->{self::ATTACHMENTS_PROPERTY} as $attachment) {
                    if ($attachment->{self::COMMENT_FOREIGN_KEY} === $comment->id && !$attachment->is_inline) {
                        $commentAttachments[] = $attachment;
                    }
                }
            }

            $template = $this->getTemplate($templateKey);
            if (!$template) {
                Log::error("Email template not found: {$templateKey}");
                return false;
            }

            $author = $comment->user ? $comment->user->name : 'Sistema';
            $agentProfileImageUrl = $this->getAgentProfileImageUrl($comment->user);

            $variables = array_merge(
                $this->getSystemVariables(),
                $this->buildCommentVariables($entity, $comment, $author, $agentProfileImageUrl, $commentAttachments)
            );

            if ($oldStatus !== null && $newStatus !== null) {
                $hasStatusChange = ($oldStatus !== $newStatus);
                $assigneeName = (isset($entity->assignee) && $entity->assignee) ? $entity->assignee->name : 'No asignado';
                $variables['status_change_section'] = $hasStatusChange
                    ? $this->renderer->renderStatusChangeHtml($oldStatus, $newStatus, $assigneeName)
                    : '';
            }

            $subject = $this->replaceVariables($template->subject, $variables);
            $body = $this->replaceVariables($template->body_html, $variables);
            $recipientEmail = $entity->requester->email ?? '';

            return $this->sendEmail($recipientEmail, $subject, $body, $commentAttachments, $additionalTo, $additionalCc);
        } catch (\Exception $e) {
            Log::error('Failed to send ticket comment notification', [
                'entity_id' => $entity->id,
                'comment_id' => $comment->id,
                'template' => $templateKey,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function buildCommentVariables(
        \Cake\Datasource\EntityInterface $entity,
        \Cake\Datasource\EntityInterface $comment,
        string $author,
        string $agentProfileImageUrl,
        array $commentAttachments
    ): array {
        return [
            'subject' => $entity->subject,
            'comment_author' => $author,
            'comment_body' => $comment->body,
            'attachments_list' => $this->renderer->renderAttachmentsHtml($commentAttachments),
            'agent_profile_image_url' => $agentProfileImageUrl,
            'agent_name' => $author,
            'ticket_number' => $entity->ticket_number,
            'requester_name' => $entity->requester->name ?? 'N/A',
            'ticket_url' => $this->renderer->getTicketUrl($entity->id),
        ];
    }

    private function getAgentProfileImageUrl(?\App\Model\Entity\User $user): string
    {
        $userHelper = new \App\View\Helper\UserHelper($this->getView());
        $url = ($user && $user->profile_image)
            ? $userHelper->profileImage($user->profile_image)
            : $userHelper->defaultAvatar();

        return $this->getAbsoluteUrl($url);
    }

    private function getView(): \Cake\View\View
    {
        return new \Cake\View\View();
    }

    private function getAbsoluteUrl(string $relativeUrl): string
    {
        if (
            str_starts_with($relativeUrl, 'http://') ||
            str_starts_with($relativeUrl, 'https://') ||
            str_starts_with($relativeUrl, 'data:')
        ) {
            return $relativeUrl;
        }

        $baseUrl = rtrim(\Cake\Routing\Router::url('/', true), '/');

        if (!str_starts_with($relativeUrl, '/')) {
            $relativeUrl = '/' . $relativeUrl;
        }

        return $baseUrl . $relativeUrl;
    }

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

    private function sendGenericTemplateEmail(
        string $templateKey,
        \Cake\Datasource\EntityInterface $entity,
        array $extraVariables = [],
        array $attachments = [],
        array $additionalTo = [],
        array $additionalCc = []
    ): bool {
        try {
            $table = $this->fetchTable(self::ENTITY_TABLE);
            $entity = $table->get($entity->id, contain: self::ENTITY_CONTAIN);

            $template = $this->getTemplate($templateKey);
            if (!$template) {
                Log::error("Email template not found: {$templateKey}");
                return false;
            }

            $variables = array_merge(
                $this->getSystemVariables(),
                [
                    'ticket_number' => $entity->ticket_number,
                    'subject' => $entity->subject,
                    'requester_name' => $entity->requester->name ?? 'N/A',
                    'created_date' => $this->renderer->formatDate($entity->created),
                    'ticket_url' => $this->renderer->getTicketUrl($entity->id),
                ],
                $extraVariables
            );

            $subject = $this->replaceVariables($template->subject, $variables);
            $body = $this->replaceVariables($template->body_html, $variables);
            $recipientEmail = $entity->requester->email ?? '';

            return $this->sendEmail($recipientEmail, $subject, $body, $attachments, $additionalTo, $additionalCc);

        } catch (\Exception $e) {
            Log::error('Failed to send ticket email', [
                'template' => $templateKey,
                'entity_id' => $entity->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
