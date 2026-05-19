<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Dto\GmailImportResult;
use App\Service\Dto\SystemConfig;
use App\Service\Exception\GmailApiException;
use App\Service\Exception\GmailNotConfiguredException;
use App\Service\Gmail\GmailErrorCategory;
use App\Service\Gmail\MarkReadQueueService;
use App\Service\Traits\SettingsEncryptionTrait;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Locator\TableLocator;
use Throwable;

/**
 * Orquesta el import de Gmail invocable desde CLI o HTTP.
 *
 * Equivalente al cuerpo de ImportGmailCommand::execute() pero sin ConsoleIo:
 * retorna un GmailImportResult con conteos en lugar de imprimir.
 */
final class GmailImportService
{
    use LocatorAwareTrait;
    use SettingsEncryptionTrait;

    /**
     * @param \App\Service\GmailService $gmail Cliente Gmail ya configurado
     * @param \App\Service\TicketIngestionService $tickets Servicio de tickets con settings cargados
     * @param \App\Service\Gmail\MarkReadQueueService $markReadQueue Cola de reintentos para markAsRead (M-5)
     */
    public function __construct(
        private readonly GmailService $gmail,
        private readonly TicketIngestionService $tickets,
        private readonly MarkReadQueueService $markReadQueue,
    ) {
    }

    /**
     * Construye el servicio leyendo configuración cifrada desde system_settings.
     *
     * @throws \App\Service\Exception\GmailNotConfiguredException si no hay refresh_token
     */
    public static function fromSettings(): self
    {
        $config = GmailService::loadConfigFromDatabase();
        if (empty($config['refresh_token'])) {
            throw GmailNotConfiguredException::missingRefreshToken();
        }

        $locator = new TableLocator();

        return new self(
            new GmailService($config),
            new TicketIngestionService(SystemConfig::fromSettingsArray(self::loadSystemSettings())),
            new MarkReadQueueService($locator->get('GmailMarkReadPending')),
        );
    }

    /**
     * Carga todos los settings del sistema con desencriptado automático.
     *
     * @return array<string, string>
     */
    private static function loadSystemSettings(): array
    {
        return (new SettingsService())->loadAll();
    }

    /**
     * Ejecuta el import.
     *
     * @param int $max Máximo de mensajes a procesar (cap superior 200)
     * @param string $query Query de búsqueda Gmail (e.g. 'is:unread')
     * @param int $delayMs Delay entre mensajes en milisegundos (rate limit Gmail)
     */
    public function run(int $max = 50, string $query = 'is:unread', int $delayMs = 0): GmailImportResult
    {
        $startedAt = microtime(true);
        $max = max(1, min($max, 200));

        // M-5: drain prior failed markAsRead attempts BEFORE this run's new
        // ingestion. Failures during this run will be enqueued (see wrappers
        // around the per-message markAsRead calls below).
        $markReadCounters = $this->markReadQueue->processPending($this->gmail);

        $messageIds = $this->gmail->getMessages($query, $max);
        $fetched = count($messageIds);

        if ($fetched === 0) {
            return new GmailImportResult(
                fetched: 0,
                created: 0,
                comments: 0,
                skipped: 0,
                errors: 0,
                durationSeconds: microtime(true) - $startedAt,
                markReadRetried: $markReadCounters['retried'],
                markReadDropped: $markReadCounters['dropped'],
                markReadEnqueued: 0,
            );
        }

        $ticketsTable = $this->fetchTable('Tickets');
        $existingMessageIds = $ticketsTable->find()
            ->select(['gmail_message_id'])
            ->where(['gmail_message_id IN' => $messageIds])
            ->all()
            ->extract('gmail_message_id')
            ->toArray();

        $created = 0;
        $comments = 0;
        $skipped = 0;
        $errors = 0;
        $errorMessages = [];
        $categoryCounters = [
            GmailErrorCategory::AUTH => 0,
            GmailErrorCategory::RATE => 0,
            GmailErrorCategory::TRANSIENT => 0,
            GmailErrorCategory::PERMANENT => 0,
            GmailErrorCategory::UNKNOWN => 0,
        ];

        foreach ($messageIds as $messageId) {
            try {
                $emailData = $this->gmail->parseMessage($messageId);

                if (!empty($emailData['is_auto_reply'])) {
                    $this->safeMarkAsRead($messageId, $markReadCounters);
                    $skipped++;
                    continue;
                }

                if (!empty($emailData['is_system_notification'])) {
                    $this->safeMarkAsRead($messageId, $markReadCounters);
                    $skipped++;
                    continue;
                }

                if (in_array($messageId, $existingMessageIds, true)) {
                    $skipped++;
                    continue;
                }

                $existingTicket = $this->tickets->findExistingTicketByThreading($emailData);

                if ($existingTicket) {
                    $comment = $this->tickets->createCommentFromEmail($existingTicket, $emailData);
                    if ($comment) {
                        $comments++;
                    } else {
                        $skipped++;
                    }
                    $this->safeMarkAsRead($messageId, $markReadCounters);
                } else {
                    $ticket = $this->tickets->createFromEmail($emailData);
                    if ($ticket) {
                        $created++;
                        $this->safeMarkAsRead($messageId, $markReadCounters);
                    } else {
                        $errors++;
                        $errorMessages[] = "Failed to create ticket from {$messageId}";
                    }
                }
            } catch (Throwable $e) {
                $category = GmailErrorCategory::categorize($e);
                Log::error('Gmail import per-message error', [
                    'message_id' => $messageId,
                    'category' => $category,
                    'error' => $e->getMessage(),
                    'class' => $e::class,
                ]);
                $errors++;
                $errorMessages[] = "{$messageId}: {$e->getMessage()}";
                $categoryCounters[$category]++;
            }

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $result = new GmailImportResult(
            fetched: $fetched,
            created: $created,
            comments: $comments,
            skipped: $skipped,
            errors: $errors,
            durationSeconds: microtime(true) - $startedAt,
            errorMessages: $errorMessages,
            authErrors: $categoryCounters[GmailErrorCategory::AUTH],
            rateErrors: $categoryCounters[GmailErrorCategory::RATE],
            transientErrors: $categoryCounters[GmailErrorCategory::TRANSIENT],
            permanentErrors: $categoryCounters[GmailErrorCategory::PERMANENT],
            unknownErrors: $categoryCounters[GmailErrorCategory::UNKNOWN],
            markReadRetried: $markReadCounters['retried'],
            markReadDropped: $markReadCounters['dropped'],
            markReadEnqueued: $markReadCounters['enqueued'] ?? 0,
        );

        Log::info('Gmail import completed', $result->toArray());

        return $result;
    }

    /**
     * Wrap a markAsRead call so a GmailApiException enqueues the message ID
     * for a future drain instead of leaking out of the per-message loop and
     * counting as a ticket-level error.
     *
     * @param array{processed:int, retried:int, failed:int, dropped:int, enqueued?:int} $markReadCounters
     */
    private function safeMarkAsRead(string $messageId, array &$markReadCounters): void
    {
        try {
            $this->gmail->markAsRead($messageId);
        } catch (GmailApiException $e) {
            $this->markReadQueue->enqueue($messageId, $e->getMessage(), $e->getCategory());
            $markReadCounters['enqueued'] = ($markReadCounters['enqueued'] ?? 0) + 1;
        }
    }
}
