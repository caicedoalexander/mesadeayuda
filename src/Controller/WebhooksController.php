<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\CacheConstants;
use App\Constants\SettingKeys;
use App\Service\Dto\SystemConfig;
use App\Service\Dto\WhatsappIngestPayload;
use App\Service\Exception\GmailNotConfiguredException;
use App\Service\Exception\InvalidWhatsappPayloadException;
use App\Service\GmailImportService;
use App\Service\SettingsService;
use App\Service\TicketIngestionService;
use App\Service\TicketPipelineService;
use Cake\Cache\Cache;
use Cake\Controller\Controller;
use Cake\Http\Response;
use Cake\Log\Log;
use Throwable;

/**
 * Endpoints HTTP disparados por sistemas externos (n8n principalmente).
 *
 * Hereda de Controller (no AppController) para evitar Authentication
 * Component, FormProtection, Flash y carga de settings en beforeFilter.
 * La autenticación se hace por shared secret en header X-Webhook-Token.
 */
final class WebhooksController extends Controller
{
    private const LOCK_FILENAME = 'gmail_import.lock';
    private const RATE_LIMIT_KEY = 'gmail_import_last_run';
    private const RATE_LIMIT_CACHE = 'default';
    private const MIN_INTERVAL_SECONDS = 60;
    private const REQUEST_TIME_LIMIT = 300;

    /**
     * @return \Cake\Http\Response
     */
    public function gmailImport(): Response
    {
        $this->request->allowMethod(['POST']);

        if (
            !$this->verifyToken(
                SettingKeys::WEBHOOK_GMAIL_IMPORT_TOKEN,
                CacheConstants::WEBHOOK_GMAIL_PREVIOUS_TOKEN,
            )
        ) {
            return $this->jsonError(401, 'invalid_token');
        }

        if ($this->ranRecently()) {
            return $this->jsonError(429, 'too_soon', [
                'retry_after_seconds' => self::MIN_INTERVAL_SECONDS,
            ]);
        }

        $lock = $this->acquireFileLock();
        if ($lock === null) {
            return $this->jsonError(409, 'already_running');
        }

        @set_time_limit(self::REQUEST_TIME_LIMIT);
        ignore_user_abort(true);

        try {
            $service = GmailImportService::fromSettings();
            $max = (int)($this->request->getData('max') ?? 50);
            // M-2: by default, let GmailImportService use the history.list
            // checkpoint state machine. Explicit `query` POST data bypasses
            // the checkpoint (MANUAL_OVERRIDE mode) for operator debugging.
            $queryRaw = $this->request->getData('query');
            $queryOverride = is_string($queryRaw) && $queryRaw !== '' ? $queryRaw : null;

            $result = $service->run($max, $queryOverride, 0);

            return $this->jsonOk($result->toArray());
        } catch (GmailNotConfiguredException $e) {
            unset($e);

            return $this->jsonError(503, 'not_configured');
        } catch (Throwable $e) {
            Log::error('Gmail webhook import failed', [
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return $this->jsonError(500, 'import_failed');
        } finally {
            // Throttle every attempt that reached this point — including failed
            // ones — so a hammering caller cannot bypass the 60s rate limit by
            // triggering errors. Token/lock/recency rejections happen earlier
            // and are intentionally exempt.
            Cache::write(self::RATE_LIMIT_KEY, time(), self::RATE_LIMIT_CACHE);
            $this->releaseFileLock($lock);
        }
    }

    /**
     * Crea un ticket a partir del payload del bot WhatsApp (n8n).
     *
     * Idempotente por `message_id`: dos POSTs con el mismo id retornan el
     * mismo ticket (el segundo con created:false).
     *
     * @return \Cake\Http\Response
     */
    public function whatsappImport(): Response
    {
        $this->request->allowMethod(['POST']);

        if (
            !$this->verifyToken(
                SettingKeys::WEBHOOK_WHATSAPP_IMPORT_TOKEN,
                CacheConstants::WEBHOOK_WHATSAPP_PREVIOUS_TOKEN,
            )
        ) {
            return $this->jsonError(401, 'invalid_token');
        }

        // Parse + validate body BEFORE locking — invalid requests don't consume locks.
        try {
            $payload = WhatsappIngestPayload::fromArray((array)$this->request->getData());
        } catch (InvalidWhatsappPayloadException $e) {
            return $this->jsonError(400, 'invalid_payload', ['detail' => $e->getMessage()]);
        }

        // Cross-request idempotency lock by message_id.
        //
        // Cache::add is atomic create-if-absent: only one of N concurrent
        // requests for the same message_id wins the lock; the rest get 409.
        // The lock is released in the finally block on every code path;
        // if the FPM worker dies hard between here and the finally, the
        // lock entry will linger until the cache profile's TTL expires
        // (the 'default' profile's duration — see config/app.php).
        $lockKey = 'whatsapp_import:' . $payload->messageId;
        if (!Cache::add($lockKey, time(), self::RATE_LIMIT_CACHE)) {
            return $this->jsonError(409, 'already_running');
        }

        @set_time_limit(self::REQUEST_TIME_LIMIT);
        ignore_user_abort(true);

        try {
            $config = SystemConfig::fromSettingsArray((new SettingsService())->loadAll());
            if (!$config->whatsapp->enabled) {
                return $this->jsonError(503, 'not_configured');
            }

            $service = new TicketIngestionService($config);
            $result = $service->createFromWhatsapp($payload);

            if ($result['ticket'] === null) {
                return $this->jsonError(500, 'ingest_failed');
            }

            return $this->jsonOk([
                'ticket_id' => (int)$result['ticket']->id,
                'ticket_number' => $result['ticket']->ticket_number,
                'created' => $result['created'],
            ]);
        } catch (Throwable $e) {
            Log::error('WhatsApp webhook import failed', [
                'error' => $e->getMessage(),
                'class' => $e::class,
                'message_id' => $payload->messageId,
            ]);

            return $this->jsonError(500, 'ingest_failed');
        } finally {
            Cache::delete($lockKey, self::RATE_LIMIT_CACHE);
        }
    }

    /**
     * Asigna tags a un ticket (idempotente, sin SQL crudo). Diseñado para
     * el sub-flujo de Auto Tagging del workflow n8n.
     *
     * @param string $id Ticket id from the route (validated as \d+)
     * @return \Cake\Http\Response
     */
    public function ticketTagsAdd(string $id): Response
    {
        $this->request->allowMethod(['POST']);

        if (
            !$this->verifyToken(
                SettingKeys::WEBHOOK_TICKETS_TAGS_TOKEN,
                CacheConstants::WEBHOOK_TICKETS_TAGS_PREVIOUS_TOKEN,
            )
        ) {
            return $this->jsonError(401, 'invalid_token');
        }

        $ticketId = (int)$id;
        if ($ticketId <= 0) {
            return $this->jsonError(400, 'invalid_payload', ['detail' => 'ticket id must be positive']);
        }

        $body = (array)$this->request->getData();
        $tagIdsRaw = $body['tag_ids'] ?? null;
        if (!is_array($tagIdsRaw) || $tagIdsRaw === []) {
            return $this->jsonError(400, 'invalid_payload', [
                'detail' => "field 'tag_ids': non-empty array required",
            ]);
        }
        if (count($tagIdsRaw) > 20) {
            return $this->jsonError(400, 'invalid_payload', [
                'detail' => "field 'tag_ids': exceeds 20 items",
            ]);
        }
        $tagIds = [];
        foreach ($tagIdsRaw as $candidate) {
            if (!is_int($candidate) || $candidate <= 0) {
                return $this->jsonError(400, 'invalid_payload', [
                    'detail' => "field 'tag_ids': each item must be a positive int",
                ]);
            }
            $tagIds[] = $candidate;
        }
        $tagIds = array_values(array_unique($tagIds));

        $source = $body['source'] ?? 'auto';
        if (!in_array($source, ['auto', 'manual'], true)) {
            return $this->jsonError(400, 'invalid_payload', [
                'detail' => "field 'source': must be 'auto' or 'manual'",
            ]);
        }

        $ticketsTable = $this->fetchTable('Tickets');
        $ticket = $ticketsTable->find()->where(['id' => $ticketId])->first();
        if ($ticket === null) {
            return $this->jsonError(404, 'ticket_not_found');
        }

        $tagsTable = $this->fetchTable('Tags');
        $knownIds = $tagsTable->find()
            ->where(['id IN' => $tagIds])
            ->all()
            ->extract('id')
            ->toList();
        $knownIds = array_map('intval', $knownIds);

        $unknown = array_values(array_diff($tagIds, $knownIds));

        $pipeline = new TicketPipelineService();
        $added = [];
        $skippedExisting = [];

        foreach ($knownIds as $tagId) {
            $result = $pipeline->addTag($ticketId, $tagId);
            if ($result['success']) {
                $added[] = $tagId;
            } elseif ($result['message'] === TicketPipelineService::MESSAGE_TAG_ALREADY_ADDED) {
                $skippedExisting[] = $tagId;
            } else {
                Log::error('Tags webhook addTag failed', [
                    'ticket_id' => $ticketId,
                    'tag_id' => $tagId,
                    'message' => $result['message'],
                ]);
            }
        }

        if ($unknown !== []) {
            Log::warning('Tags webhook unknown tag_ids (LLM hallucination?)', [
                'ticket_id' => $ticketId,
                'unknown' => $unknown,
                'source' => $source,
            ]);
        } else {
            Log::info('Tags webhook applied', [
                'ticket_id' => $ticketId,
                'added' => $added,
                'skipped_existing' => $skippedExisting,
                'source' => $source,
            ]);
        }

        return $this->jsonOk([
            'added' => $added,
            'skipped_existing' => $skippedExisting,
            'skipped_unknown' => $unknown,
        ]);
    }

    /**
     * Compara el header X-Webhook-Token contra el setting cifrado, aceptando
     * también el token anterior si todavía está dentro de la ventana de gracia
     * tras una rotación.
     */
    private function verifyToken(string $settingKey, string $previousTokenCacheKey): bool
    {
        $provided = (string)$this->request->getHeaderLine('X-Webhook-Token');
        if ($provided === '') {
            return false;
        }

        $settings = (new SettingsService())->loadAll();
        $expected = $settings[$settingKey] ?? null;

        if (is_string($expected) && $expected !== '' && hash_equals($expected, $provided)) {
            return true;
        }

        return $this->matchesPreviousToken($provided, $previousTokenCacheKey);
    }

    /**
     * Acepta el token anterior durante la ventana de gracia tras una rotación.
     */
    private function matchesPreviousToken(string $provided, string $cacheKey): bool
    {
        $previous = Cache::read($cacheKey, 'default');
        if (!is_array($previous)) {
            return false;
        }

        $token = $previous['token'] ?? null;
        $expiresAt = (int)($previous['expires_at'] ?? 0);

        if (!is_string($token) || $token === '' || $expiresAt <= time()) {
            Cache::delete($cacheKey, 'default');

            return false;
        }

        return hash_equals($token, $provided);
    }

    /**
     * Rechaza si la última corrida fue dentro de la ventana mínima.
     */
    private function ranRecently(): bool
    {
        $last = (int)(Cache::read(self::RATE_LIMIT_KEY, self::RATE_LIMIT_CACHE) ?? 0);

        return $last > 0 && time() - $last < self::MIN_INTERVAL_SECONDS;
    }

    /**
     * Adquiere lock exclusivo no bloqueante sobre tmp/gmail_import.lock.
     *
     * @return resource|null Handle abierto si se obtuvo el lock, null si está ocupado
     */
    private function acquireFileLock()
    {
        $path = TMP . self::LOCK_FILENAME;
        $fp = @fopen($path, 'c');
        if ($fp === false) {
            Log::error('Gmail webhook: cannot open lock file', ['path' => $path]);

            return null;
        }

        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);

            return null;
        }

        return $fp;
    }

    /**
     * @param resource|null $fp Handle del lock
     * @return void
     */
    private function releaseFileLock($fp): void
    {
        if (is_resource($fp)) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return \Cake\Http\Response
     */
    private function jsonOk(array $body): Response
    {
        return $this->response
            ->withType('application/json')
            ->withStringBody(json_encode(['ok' => true] + $body, JSON_THROW_ON_ERROR));
    }

    /**
     * @param int $code HTTP status code
     * @param string $error Error code (machine-readable)
     * @param array<string, mixed> $extra Additional fields to merge in body
     * @return \Cake\Http\Response
     */
    private function jsonError(int $code, string $error, array $extra = []): Response
    {
        return $this->response
            ->withStatus($code)
            ->withType('application/json')
            ->withStringBody(json_encode(['ok' => false, 'error' => $error] + $extra, JSON_THROW_ON_ERROR));
    }
}
