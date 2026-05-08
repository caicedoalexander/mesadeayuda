<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\SettingKeys;
use App\Service\Exception\GmailNotConfiguredException;
use App\Service\GmailImportService;
use App\Service\SettingsService;
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

        if (!$this->verifyToken()) {
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
            $query = (string)($this->request->getData('query') ?? 'is:unread');

            $result = $service->run($max, $query, 0);

            Cache::write(self::RATE_LIMIT_KEY, time(), self::RATE_LIMIT_CACHE);

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
            $this->releaseFileLock($lock);
        }
    }

    /**
     * Compara el header X-Webhook-Token contra el setting cifrado.
     */
    private function verifyToken(): bool
    {
        $provided = (string)$this->request->getHeaderLine('X-Webhook-Token');
        if ($provided === '') {
            return false;
        }

        $settings = (new SettingsService())->loadAll();
        $expected = $settings[SettingKeys::WEBHOOK_GMAIL_IMPORT_TOKEN] ?? null;

        return is_string($expected) && $expected !== '' && hash_equals($expected, $provided);
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
