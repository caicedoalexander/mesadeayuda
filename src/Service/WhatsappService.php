<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\CacheConstants;
use App\Constants\SettingKeys;
use App\Service\Dto\SystemConfig;
use App\Service\Renderer\NotificationRenderer;
use Cake\Datasource\EntityInterface;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Exception;

/**
 * WhatsApp Service
 *
 * Handles WhatsApp notifications via Evolution API:
 * - New ticket notifications (to tickets team)
 */
class WhatsappService
{
    use LocatorAwareTrait;
    use Traits\ConfigResolutionTrait;
    use Traits\SecureHttpTrait;

    private NotificationRenderer $renderer;

    /**
     * Resolved WhatsApp configuration (null = not yet resolved, false = disabled/invalid)
     */
    private array|false|null $config = null;

    /**
     * Raw system configuration from constructor
     */
    private ?array $systemConfig;

    /**
     * Constructor
     *
     * @param \App\Service\Dto\SystemConfig|null $config Optional system configuration VO to avoid redundant DB queries
     */
    public function __construct(?SystemConfig $config = null)
    {
        $this->renderer = new NotificationRenderer();
        $this->systemConfig = $config?->toSettingsArray();
    }

    /**
     * Get WhatsApp configuration with 3-tier resolution:
     * 1. Constructor-provided systemConfig (fastest, no I/O)
     * 2. Main 'system_settings' cache (populated by AppController)
     * 3. Service-specific DB query with cache
     *
     * @return array|null Configuration array or null if not configured/disabled
     */
    private function getConfig(): ?array
    {
        // Already resolved
        if ($this->config !== null) {
            return $this->config === false ? null : $this->config;
        }

        try {
            // Resolve settings from best available source
            $settings = $this->resolveSettings();

            if ($settings === null) {
                $this->config = false;

                return null;
            }

            // Validate: enabled check
            if (empty($settings[SettingKeys::WHATSAPP_ENABLED]) || $settings[SettingKeys::WHATSAPP_ENABLED] !== '1') {
                $this->config = false;

                return null;
            }

            // Validate: required fields
            if (
                empty($settings[SettingKeys::WHATSAPP_API_URL]) ||
                empty($settings[SettingKeys::WHATSAPP_API_KEY]) ||
                empty($settings[SettingKeys::WHATSAPP_INSTANCE_NAME])
            ) {
                Log::warning('WhatsApp configuration incomplete');
                $this->config = false;

                return null;
            }

            $this->config = $settings;

            return $this->config;
        } catch (Exception $e) {
            Log::error('Failed to load WhatsApp configuration', [
                'error' => $e->getMessage(),
            ]);
            $this->config = false;

            return null;
        }
    }

    /**
     * Resolve WhatsApp settings from the best available source
     *
     * @return array Settings array with whatsapp_* keys
     */
    private function resolveSettings(): array
    {
        return $this->resolveSettingsBatch(SettingKeys::WHATSAPP_ENABLED, 'whatsapp_settings', [
            SettingKeys::WHATSAPP_ENABLED, SettingKeys::WHATSAPP_API_URL, SettingKeys::WHATSAPP_API_KEY,
            SettingKeys::WHATSAPP_INSTANCE_NAME, SettingKeys::WHATSAPP_TICKETS_NUMBER,
        ]);
    }

    /**
     * Send WhatsApp message via Evolution API
     *
     * @param string $number WhatsApp number (can be individual or group ID)
     * @param string $text Message text
     * @return bool Success status
     */
    public function sendMessage(string $number, string $text): bool
    {
        $config = $this->getConfig();

        if (!$config) {
            Log::info('WhatsApp is disabled or not configured, skipping notification');

            return false;
        }

        try {
            $url = rtrim($config[SettingKeys::WHATSAPP_API_URL], '/') .
                '/message/sendText/' .
                urlencode($config[SettingKeys::WHATSAPP_INSTANCE_NAME]);

            $data = [
                'number' => $number,
                'text' => $text,
            ];

            $headers = [
                'Content-Type: application/json',
                'apikey: ' . $config[SettingKeys::WHATSAPP_API_KEY],
            ];

            $result = $this->secureCurlPost($url, json_encode($data), $headers, 10);

            if ($result['success']) {
                Log::info('WhatsApp message sent successfully', [
                    'number' => $number,
                    'http_code' => $result['http_code'],
                ]);

                return true;
            } else {
                Log::error(
                    'WhatsApp API error: HTTP {http_code} calling {url} (number {number}) — response: {response}',
                    [
                        'http_code' => $result['http_code'],
                        'url' => $url,
                        'number' => $number,
                        'response' => $result['response'] ?? $result['error'] ?? '(sin cuerpo)',
                    ],
                );

                return false;
            }
        } catch (Exception $e) {
            Log::error('Failed to send WhatsApp message to {number}: ' . $e->getMessage(), [
                'number' => $number,
            ]);

            return false;
        }
    }

    /**
     * Send new ticket notification via WhatsApp.
     *
     * @param \Cake\Datasource\EntityInterface $entity Ticket entity
     * @return bool Success status
     */
    public function sendNewEntityNotification(EntityInterface $entity): bool
    {
        try {
            $config = $this->getConfig();

            if (!$config || empty($config[SettingKeys::WHATSAPP_TICKETS_NUMBER])) {
                Log::info('WhatsApp tickets number not configured, skipping notification');

                return false;
            }

            $entity = $this->fetchTable('Tickets')->get($entity->id, contain: ['Requesters']);
            $message = $this->renderer->renderWhatsappNewTicket($entity);

            return $this->sendMessage($config[SettingKeys::WHATSAPP_TICKETS_NUMBER], $message);
        } catch (Exception $e) {
            Log::error('Failed to send WhatsApp ticket notification', [
                'entity_id' => $entity->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Test WhatsApp connection by sending a test message to the tickets number.
     *
     * @return array Test result with status and message
     */
    public function testConnection(): array
    {
        $config = $this->getConfig();

        if (!$config) {
            return [
                'success' => false,
                'message' => 'WhatsApp está deshabilitado o no configurado',
            ];
        }

        if (empty($config[SettingKeys::WHATSAPP_TICKETS_NUMBER])) {
            return [
                'success' => false,
                'message' => 'No se ha configurado un número de WhatsApp para Tickets',
            ];
        }

        $testMessage = "✅ Prueba de conexión - Evolution API\n\n" .
            "Este es un mensaje de prueba del módulo de Tickets.\n" .
            "Si recibes este mensaje, la integración está funcionando correctamente.\n\n" .
            '_' . CacheConstants::DEFAULT_SYSTEM_TITLE . ' - Tickets_';

        $result = $this->sendMessage($config[SettingKeys::WHATSAPP_TICKETS_NUMBER], $testMessage);

        return [
            'success' => $result,
            'message' => $result
                ? 'Mensaje de prueba enviado exitosamente'
                : 'Error al enviar mensaje de prueba. Revisa los logs para más detalles.',
        ];
    }
}
