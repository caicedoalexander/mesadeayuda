<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\CacheConstants;
use App\Constants\SettingKeys;
use App\Notification\Channel\NotificationMessage;
use App\Notification\Email\TemplateContext;
use App\Notification\Email\TemplateRegistry;
use App\Service\Dto\SystemConfig;
use App\Service\Renderer\NotificationRenderer;
use App\Service\Traits\GenericAttachmentTrait;
use Cake\Datasource\EntityInterface;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Exception;

/**
 * Email Service.
 *
 * Builds a TemplateContext from a ticket-side mutation and dispatches the
 * rendered email through Gmail. Templates and components live in
 * App\Notification\Email\* — this class is the thin orchestrator.
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

    private NotificationRenderer $renderer;
    private TemplateRegistry $templates;
    private ?SystemConfig $config;
    /**
     * Lazy view of {@see $config} as a flat settings array. Required by
     * {@see ConfigResolutionTrait} (uses $this->systemConfig['key'] lookups).
     *
     * @var array<string, mixed>|null
     */
    private ?array $systemConfig = null;
    private ?GmailService $gmailService = null;

    public function __construct(?SystemConfig $config = null)
    {
        $this->config = $config;
        $this->systemConfig = $config?->toSettingsArray();
        $this->renderer = new NotificationRenderer();
        $this->templates = new TemplateRegistry();
    }

    private function getSettingValue(string $key, string $default = ''): string
    {
        return $this->resolveSettingValue($key, $default);
    }

    public function sendNewEntityNotification(EntityInterface $entity): bool
    {
        try {
            $entity = $this->fetchTable(self::ENTITY_TABLE)->get($entity->id, contain: ['Requesters']);

            $excludeEmails = [
                strtolower($entity->requester->email),
                strtolower($this->getSettingValue(SettingKeys::GMAIL_USER_EMAIL)),
            ];
            $additionalTo = $this->filterEmailRecipients($entity->email_to, $excludeEmails);
            $additionalCc = $this->filterEmailRecipients($entity->email_cc, $excludeEmails);

            $ctx = new TemplateContext(
                ticket: $entity,
                ticketUrl: $this->renderer->getTicketUrl($entity->id),
                recipientName: (string)($entity->requester->name ?? ''),
            );

            $rendered = $this->templates->get('ticket_created')->render($ctx);

            return $this->sendEmail(
                to: (string)($entity->requester->email ?? ''),
                subject: $rendered->subject,
                body: $rendered->bodyHtml,
                attachments: [],
                additionalTo: $additionalTo,
                additionalCc: $additionalCc,
            );
        } catch (Exception $e) {
            Log::error('Failed to send ticket created email', [
                'entity_id' => $entity->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function sendEntityStatusChangeNotification(EntityInterface $entity, string $oldStatus, string $newStatus): bool
    {
        try {
            $entity = $this->fetchTable(self::ENTITY_TABLE)->get($entity->id, contain: self::ENTITY_CONTAIN);

            $ctx = new TemplateContext(
                ticket: $entity,
                ticketUrl: $this->renderer->getTicketUrl($entity->id),
                recipientName: (string)($entity->requester->name ?? ''),
                oldStatus: $oldStatus,
                newStatus: $newStatus,
                actor: $entity->assignee ?? null,
            );

            $rendered = $this->templates->get('ticket_status_changed')->render($ctx);

            return $this->sendEmail(
                to: (string)($entity->requester->email ?? ''),
                subject: $rendered->subject,
                body: $rendered->bodyHtml,
            );
        } catch (Exception $e) {
            Log::error('Failed to send ticket status email', [
                'entity_id' => $entity->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function sendEntityCommentNotification(EntityInterface $entity, EntityInterface $comment, array $additionalTo = [], array $additionalCc = []): bool
    {
        return $this->sendCommentBasedNotification('ticket_comment_added', $entity, $comment, null, null, $additionalTo, $additionalCc);
    }

    public function sendEntityResponseNotification(EntityInterface $entity, EntityInterface $comment, string $oldStatus, string $newStatus, array $additionalTo = [], array $additionalCc = []): bool
    {
        return $this->sendCommentBasedNotification('ticket_updated', $entity, $comment, $oldStatus, $newStatus, $additionalTo, $additionalCc);
    }

    private function sendCommentBasedNotification(
        string $templateKey,
        EntityInterface $entity,
        EntityInterface $comment,
        ?string $oldStatus,
        ?string $newStatus,
        array $additionalTo = [],
        array $additionalCc = [],
    ): bool {
        try {
            $entity = $this->fetchTable(self::ENTITY_TABLE)->get($entity->id, contain: self::ENTITY_CONTAIN);
            $comment = $this->fetchTable(self::COMMENTS_TABLE)->get($comment->id, contain: ['Users']);

            $commentAttachments = [];
            if (!empty($entity->{self::ATTACHMENTS_PROPERTY})) {
                foreach ($entity->{self::ATTACHMENTS_PROPERTY} as $attachment) {
                    if ($attachment->{self::COMMENT_FOREIGN_KEY} === $comment->id && !$attachment->is_inline) {
                        $commentAttachments[] = $attachment;
                    }
                }
            }

            $ctx = new TemplateContext(
                ticket: $entity,
                ticketUrl: $this->renderer->getTicketUrl($entity->id),
                recipientName: (string)($entity->requester->name ?? ''),
                comment: $comment,
                oldStatus: $oldStatus,
                newStatus: $newStatus,
                actor: $comment->user ?? null,
                commentAttachments: $commentAttachments,
            );

            $rendered = $this->templates->get($templateKey)->render($ctx);

            return $this->sendEmail(
                to: (string)($entity->requester->email ?? ''),
                subject: $rendered->subject,
                body: $rendered->bodyHtml,
                attachments: $commentAttachments,
                additionalTo: $additionalTo,
                additionalCc: $additionalCc,
            );
        } catch (Exception $e) {
            Log::error('Failed to send ticket comment notification', [
                'entity_id' => $entity->id,
                'comment_id' => $comment->id,
                'template' => $templateKey,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function getGmailService(): GmailService
    {
        if ($this->gmailService === null) {
            $this->gmailService = new GmailService(GmailService::loadConfigFromDatabase());
        }

        return $this->gmailService;
    }

    /**
     * Transport entry-point used by EmailChannel. Delegates to the same
     * Gmail-backed implementation as the legacy methods but accepts an
     * already-rendered NotificationMessage instead of per-event arguments.
     */
    public function dispatch(NotificationMessage $message): bool
    {
        if ($message->channel !== 'email') {
            return false;
        }

        return $this->sendEmail(
            to: $message->recipient,
            subject: (string)$message->subject,
            body: (string)$message->bodyHtml,
            attachments: $message->attachments,
            additionalTo: $message->additionalTo,
            additionalCc: $message->additionalCc,
        );
    }

    private function sendEmail(string $to, string $subject, string $body, array $attachments = [], array $additionalTo = [], array $additionalCc = []): bool
    {
        try {
            $systemTitle = $this->getSettingValue(SettingKeys::SYSTEM_TITLE, CacheConstants::DEFAULT_SYSTEM_TITLE);
            $fromEmail = $this->getSettingValue(SettingKeys::GMAIL_USER_EMAIL, 'noreply@localhost');

            $toRecipients = [$to => $to];

            foreach ($additionalTo as $recipient) {
                if (!empty($recipient['email'])) {
                    $toRecipients[$recipient['email']] = $recipient['name'] ?? $recipient['email'];
                }
            }

            $ccRecipients = [];
            foreach ($additionalCc as $recipient) {
                if (!empty($recipient['email'])) {
                    $ccRecipients[$recipient['email']] = $recipient['name'] ?? $recipient['email'];
                }
            }

            $attachmentPaths = [];
            foreach ($attachments as $attachment) {
                $filePath = $this->getFullPath($attachment);
                if (file_exists($filePath)) {
                    $attachmentPaths[] = $filePath;
                }
            }

            $options = [
                'from' => [$fromEmail => $systemTitle],
                'headers' => ['X-Mesa-Ayuda-Notification' => 'true'],
            ];

            if (!empty($ccRecipients)) {
                $options['cc'] = $ccRecipients;
            }

            $result = $this->getGmailService()->sendEmail($toRecipients, $subject, $body, $attachmentPaths, $options);

            if ($result) {
                Log::info('Email sent successfully via Gmail API', ['to' => $to, 'subject' => $subject]);
            }

            return $result;
        } catch (Exception $e) {
            Log::error('Failed to send email via Gmail API', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
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
}
