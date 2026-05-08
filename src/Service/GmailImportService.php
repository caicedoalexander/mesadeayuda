<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Dto\GmailImportResult;
use App\Service\Dto\SystemConfig;
use App\Service\Exception\GmailNotConfiguredException;
use App\Service\Traits\SettingsEncryptionTrait;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
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
     */
    public function __construct(
        private readonly GmailService $gmail,
        private readonly TicketIngestionService $tickets,
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

        return new self(
            new GmailService($config),
            new TicketIngestionService(SystemConfig::fromSettingsArray(self::loadSystemSettings())),
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

        foreach ($messageIds as $messageId) {
            try {
                $emailData = $this->gmail->parseMessage($messageId);

                if (!empty($emailData['is_auto_reply'])) {
                    $this->gmail->markAsRead($messageId);
                    $skipped++;
                    continue;
                }

                if (!empty($emailData['is_system_notification'])) {
                    $this->gmail->markAsRead($messageId);
                    $skipped++;
                    continue;
                }

                if (in_array($messageId, $existingMessageIds, true)) {
                    $skipped++;
                    continue;
                }

                $existingTicket = null;
                if (!empty($emailData['gmail_thread_id'])) {
                    $existingTicket = $ticketsTable->find()
                        ->where(['gmail_thread_id' => $emailData['gmail_thread_id']])
                        ->first();
                }

                if ($existingTicket) {
                    $comment = $this->tickets->createCommentFromEmail($existingTicket, $emailData);
                    if ($comment) {
                        $comments++;
                    } else {
                        $skipped++;
                    }
                    $this->gmail->markAsRead($messageId);
                } else {
                    $ticket = $this->tickets->createFromEmail($emailData);
                    if ($ticket) {
                        $created++;
                        $this->gmail->markAsRead($messageId);
                    } else {
                        $errors++;
                        $errorMessages[] = "Failed to create ticket from {$messageId}";
                    }
                }
            } catch (Throwable $e) {
                Log::error('Gmail import per-message error', [
                    'message_id' => $messageId,
                    'error' => $e->getMessage(),
                    'class' => $e::class,
                ]);
                $errors++;
                $errorMessages[] = "{$messageId}: {$e->getMessage()}";
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
        );

        Log::info('Gmail import completed', $result->toArray());

        return $result;
    }
}
