<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\SettingKeys;
use App\Service\Dto\GmailImportResult;
use App\Service\Dto\SystemConfig;
use App\Service\Exception\GmailApiException;
use App\Service\Exception\GmailNotConfiguredException;
use App\Service\Gmail\GmailErrorCategory;
use App\Service\Gmail\HistoryMode;
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
     * @param string|null $queryOverride Si se especifica, ignora el checkpoint y usa esta query
     *                                   (modo MANUAL_OVERRIDE). null = state machine de checkpoint (M-2)
     * @param int $delayMs Delay entre mensajes en milisegundos (rate limit Gmail)
     */
    public function run(int $max = 50, ?string $queryOverride = null, int $delayMs = 0): GmailImportResult
    {
        $startedAt = microtime(true);
        $max = max(1, min($max, 200));

        // M-5: drain prior failed markAsRead attempts BEFORE this run's new
        // ingestion. Failures during this run will be enqueued (see wrappers
        // around the per-message markAsRead calls below).
        $markReadCounters = $this->markReadQueue->processPending($this->gmail);

        // M-2: history.list checkpoint state machine. The checkpoint lives in
        // system_settings.gmail_last_history_id and is advanced after each
        // successful run. Operators can bypass it with $queryOverride (CLI).
        $historyMode = HistoryMode::BOOTSTRAP;
        $historyFallbacks = 0;
        $touchCheckpoint = false;
        $messageIds = [];

        $lastHistoryId = $this->readHistoryCheckpoint();

        if ($queryOverride !== null) {
            $historyMode = HistoryMode::MANUAL_OVERRIDE;
            $messageIds = $this->gmail->getMessages($queryOverride, $max);
        } elseif ($lastHistoryId === null) {
            $historyMode = HistoryMode::BOOTSTRAP;
            try {
                $bootstrapHistoryId = $this->gmail->getProfileHistoryId();
                $this->writeHistoryCheckpoint($bootstrapHistoryId);
            } catch (Throwable $e) {
                Log::warning('Gmail bootstrap historyId unavailable; falling back to unread polling', [
                    'error' => $e->getMessage(),
                ]);
            }
            $messageIds = $this->gmail->getMessages('in:inbox newer_than:7d', $max);
        } else {
            $delta = $this->gmail->getHistoryDelta($lastHistoryId);
            if ($delta === null) {
                $historyMode = HistoryMode::FULL_SYNC_FALLBACK;
                $historyFallbacks = 1;
                Log::warning('Gmail history.list returned 404, falling back to full sync', [
                    'checkpoint' => $lastHistoryId,
                ]);
                try {
                    $freshHistoryId = $this->gmail->getProfileHistoryId();
                    $this->writeHistoryCheckpoint($freshHistoryId);
                } catch (Throwable $e) {
                    Log::warning('Gmail history fallback could not refresh checkpoint', [
                        'error' => $e->getMessage(),
                    ]);
                }
                $messageIds = $this->gmail->getMessages('in:inbox newer_than:7d', $max);
            } else {
                $historyMode = HistoryMode::DELTA;
                $messageIds = array_slice($delta, 0, $max);
                $touchCheckpoint = true;
            }
        }

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
                historyMode: $historyMode,
                historyFallbacks: $historyFallbacks,
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

        $maxHistoryIdSeen = $lastHistoryId ?? '0';

        foreach ($messageIds as $messageId) {
            try {
                $emailData = $this->gmail->parseMessage($messageId);

                $thisHistoryId = (string)($emailData['gmail_history_id'] ?? '0');
                if ($thisHistoryId !== '' && $this->compareHistoryIds($thisHistoryId, $maxHistoryIdSeen) > 0) {
                    $maxHistoryIdSeen = $thisHistoryId;
                }

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

        if ($touchCheckpoint && $maxHistoryIdSeen !== ($lastHistoryId ?? '0')) {
            $this->writeHistoryCheckpoint($maxHistoryIdSeen);
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
            historyMode: $historyMode,
            historyFallbacks: $historyFallbacks,
        );

        Log::info('Gmail import completed', $result->toArray());

        return $result;
    }

    /**
     * Read the persisted Gmail historyId checkpoint, or null if unset.
     */
    private function readHistoryCheckpoint(): ?string
    {
        $row = $this->fetchTable('SystemSettings')->find()
            ->where(['setting_key' => SettingKeys::GMAIL_LAST_HISTORY_ID])
            ->first();
        if ($row === null) {
            return null;
        }
        $value = (string)($row->setting_value ?? '');

        return $value === '' ? null : $value;
    }

    /**
     * Persist the Gmail historyId checkpoint via SettingsService so the
     * usual cache-invalidation path runs (but does NOT purge the OAuth
     * cache — see SettingsService::keyRequiresOAuthCachePurge).
     */
    private function writeHistoryCheckpoint(string $historyId): void
    {
        (new SettingsService())->saveSetting(SettingKeys::GMAIL_LAST_HISTORY_ID, $historyId);
    }

    /**
     * Compare two unsigned-integer-as-string historyIds. Returns -1/0/1.
     * String compare with length-first ordering avoids 32-bit int overflow
     * on the unsigned 64-bit historyId space Gmail uses.
     */
    private function compareHistoryIds(string $a, string $b): int
    {
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');
        if (strlen($a) !== strlen($b)) {
            return strlen($a) <=> strlen($b);
        }

        return strcmp($a, $b);
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
