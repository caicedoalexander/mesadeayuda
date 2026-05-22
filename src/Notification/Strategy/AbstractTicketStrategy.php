<?php
declare(strict_types=1);

namespace App\Notification\Strategy;

use App\Constants\SettingKeys;
use App\Model\Entity\Ticket;
use App\Notification\Email\TemplateRegistry;
use App\Service\Dto\SystemConfig;
use App\Service\Renderer\NotificationRenderer;
use Cake\Event\EventInterface;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Throwable;
use Traversable;

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
     * Resolve the RFC 5322 threading anchors (In-Reply-To + References chain)
     * for an outbound notification on this ticket (CRIT-2 / J2).
     *
     * inReplyTo:
     *   - Last persisted RFC anchor we can reach — the most recent comment with
     *     a non-null rfc_message_id (inbound from client, OR previous outbound
     *     whose Message-ID we already persisted via attachOutboundMessageId).
     *   - Falls back to ticket.rfc_message_id (the customer's original email)
     *     when no comment has an RFC id yet.
     *
     * references:
     *   - Newest LAST per RFC 5322 §3.6.4. Includes the ticket's original RFC id
     *     (if any) followed by each comment's id in id-ASC order.
     *   - Each id is wrapped in angle brackets `<id>` and separated by spaces.
     *   - null when no RFC ids exist anywhere in the thread.
     *
     * @return array{inReplyTo: ?string, references: ?string}
     */
    protected function resolveThreading(Ticket $ticket): array
    {
        $ticketComments = $this->fetchTable('TicketComments')->find()
            ->select(['id', 'rfc_message_id'])
            ->where(['ticket_id' => $ticket->id, 'rfc_message_id IS NOT' => null])
            ->order(['id' => 'ASC'])
            ->all();

        $chain = [];
        if (!empty($ticket->rfc_message_id)) {
            $chain[] = '<' . $ticket->rfc_message_id . '>';
        }
        foreach ($ticketComments as $tc) {
            $chain[] = '<' . $tc->rfc_message_id . '>';
        }

        $inReplyTo = null;
        if ($ticketComments->count() > 0) {
            $last = $ticketComments->last();
            $inReplyTo = $last->rfc_message_id;
        } elseif (!empty($ticket->rfc_message_id)) {
            $inReplyTo = $ticket->rfc_message_id;
        }

        $referencesHeader = empty($chain) ? null : implode(' ', $chain);

        return ['inReplyTo' => $inReplyTo, 'references' => $referencesHeader];
    }

    /**
     * Defensive wrapper for the message-building closure. Logs any throwable
     * and returns an empty list so the dispatcher keeps going.
     *
     * Generators are eagerly iterated inside the try so exceptions thrown
     * during yield bodies are caught (Generator bodies execute lazily, so
     * returning the Generator directly would let exceptions escape to the
     * caller).
     *
     * @template T
     * @param callable(): iterable<T> $builder
     * @return array<int, T>
     */
    protected function safeBuild(callable $builder, EventInterface $event): array
    {
        try {
            $result = $builder();
            if ($result instanceof Traversable) {
                return iterator_to_array($result, false);
            }

            return is_array($result) ? array_values($result) : [];
        } catch (Throwable $e) {
            Log::error(static::class . ' failed to build messages', [
                'event' => $event->getName(),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
