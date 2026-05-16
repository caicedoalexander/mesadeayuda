<?php
declare(strict_types=1);

namespace App\Notification\Strategy;

use App\Constants\SettingKeys;
use App\Notification\Email\TemplateRegistry;
use App\Service\Dto\SystemConfig;
use App\Service\Renderer\NotificationRenderer;
use Cake\Event\EventInterface;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Throwable;

/**
 * Shared plumbing for ticket-event strategies. Concrete subclasses focus
 * on the event → message mapping and let this class handle lazy
 * collaborators and recipient filtering.
 */
abstract class AbstractTicketStrategy implements TicketNotificationStrategy
{
    use LocatorAwareTrait;

    protected ?NotificationRenderer $renderer = null;
    protected ?TemplateRegistry $templates = null;

    public function __construct(protected readonly ?SystemConfig $config = null)
    {
    }

    abstract public function supports(EventInterface $event): bool;

    /**
     * @return iterable<\App\Notification\Channel\NotificationMessage>
     */
    abstract public function buildMessages(EventInterface $event): iterable;

    protected function renderer(): NotificationRenderer
    {
        return $this->renderer ??= new NotificationRenderer();
    }

    protected function templates(): TemplateRegistry
    {
        return $this->templates ??= new TemplateRegistry();
    }

    /**
     * Filter recipient list, removing duplicates against the requester and
     * the system Gmail user.
     *
     * @param array<int, array{email: string, name?: string}>|string|null $recipients
     * @param array<int, string> $excludeEmails lower-cased
     * @return array<int, array{email: string, name?: string}>
     */
    protected function filterRecipients(string|array|null $recipients, array $excludeEmails): array
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
                $email = strtolower((string)$recipient['email']);
                if (!in_array($email, $excludeEmails, true)) {
                    $filtered[] = $recipient;
                }
            }
        }

        return $filtered;
    }

    protected function gmailUserEmail(): string
    {
        $settings = $this->config?->toSettingsArray() ?? [];

        return strtolower((string)($settings[SettingKeys::GMAIL_USER_EMAIL] ?? ''));
    }

    /**
     * Defensive wrapper for the message-building closure. Logs any throwable
     * and returns an empty list so the dispatcher keeps going.
     *
     * @template T
     * @param callable(): iterable<T> $builder
     * @return iterable<T>
     */
    protected function safeBuild(callable $builder, EventInterface $event): iterable
    {
        try {
            return $builder();
        } catch (Throwable $e) {
            Log::error(static::class . ' failed to build messages', [
                'event' => $event->getName(),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
