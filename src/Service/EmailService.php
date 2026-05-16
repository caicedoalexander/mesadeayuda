<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\CacheConstants;
use App\Constants\SettingKeys;
use App\Notification\Channel\NotificationMessage;
use App\Service\Dto\SystemConfig;
use App\Service\Traits\GenericAttachmentTrait;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Exception;

/**
 * Email transport service. Receives an already-rendered NotificationMessage
 * and delivers it via the Gmail API. Strategies under
 * App\Notification\Strategy\* are responsible for template selection and
 * recipient resolution; this class is the thin transport layer.
 */
class EmailService
{
    use LocatorAwareTrait;
    use GenericAttachmentTrait;
    use Traits\ConfigResolutionTrait;

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
    }

    private function getSettingValue(string $key, string $default = ''): string
    {
        return $this->resolveSettingValue($key, $default);
    }

    private function getGmailService(): GmailService
    {
        if ($this->gmailService === null) {
            $this->gmailService = new GmailService(GmailService::loadConfigFromDatabase());
        }

        return $this->gmailService;
    }

    /**
     * Transport entry-point used by EmailChannel. Accepts an already-rendered
     * NotificationMessage and delivers it through the Gmail API.
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
}
