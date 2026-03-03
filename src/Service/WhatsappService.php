<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Log\Log;

/**
 * WhatsApp Service
 *
 * Handles WhatsApp notifications via Evolution API:
 * - New ticket notifications (to tickets team)
 * - New PQRS notifications (to customer service team)
 * - New compra notifications (to purchasing team)
 */
class WhatsappService
{
    use LocatorAwareTrait;
    use Traits\ConfigResolutionTrait;
    use Traits\SecureHttpTrait;

    private \App\Service\Renderer\NotificationRenderer $renderer;

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
     * @param array|null $systemConfig Optional system configuration to avoid redundant DB queries
     */
    public function __construct(?array $systemConfig = null)
    {
        $this->renderer = new \App\Service\Renderer\NotificationRenderer();
        $this->systemConfig = $systemConfig;
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
            if (empty($settings['whatsapp_enabled']) || $settings['whatsapp_enabled'] !== '1') {
                $this->config = false;
                return null;
            }

            // Validate: required fields
            if (
                empty($settings['whatsapp_api_url']) ||
                empty($settings['whatsapp_api_key']) ||
                empty($settings['whatsapp_instance_name'])
            ) {
                Log::warning('WhatsApp configuration incomplete');
                $this->config = false;
                return null;
            }

            $this->config = $settings;
            return $this->config;
        } catch (\Exception $e) {
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
        return $this->resolveSettingsBatch('whatsapp_enabled', 'whatsapp_settings', [
            'whatsapp_enabled', 'whatsapp_api_url', 'whatsapp_api_key',
            'whatsapp_instance_name', 'whatsapp_tickets_number',
            'whatsapp_pqrs_number', 'whatsapp_compras_number',
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
            $url = rtrim($config['whatsapp_api_url'], '/') .
                '/message/sendText/' .
                urlencode($config['whatsapp_instance_name']);

            $data = [
                'number' => $number,
                'text' => $text,
            ];

            $headers = [
                'Content-Type: application/json',
                'apikey: ' . $config['whatsapp_api_key'],
            ];

            $result = $this->secureCurlPost($url, json_encode($data), $headers, 10);

            if ($result['success']) {
                Log::info('WhatsApp message sent successfully', [
                    'number' => $number,
                    'http_code' => $result['http_code'],
                ]);
                return true;
            } else {
                Log::error('WhatsApp API error', [
                    'http_code' => $result['http_code'],
                    'error' => $result['error'],
                    'number' => $number,
                ]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error('Failed to send WhatsApp message', [
                'error' => $e->getMessage(),
                'number' => $number,
            ]);
            return false;
        }
    }

    /**
     * Send new entity notification via WhatsApp (generic)
     *
     * Loads entity with required associations and sends WhatsApp message
     * to the configured number for the entity type.
     *
     * @param string $entityType 'ticket', 'pqrs', 'compra'
     * @param \Cake\Datasource\EntityInterface $entity Entity instance
     * @return bool Success status
     */
    public function sendNewEntityNotification(string $entityType, \Cake\Datasource\EntityInterface $entity): bool
    {
        try {
            $configMap = [
                'ticket' => ['numberKey' => 'whatsapp_tickets_number', 'renderer' => 'renderWhatsappNewTicket', 'table' => 'Tickets', 'contain' => ['Requesters']],
                'pqrs' => ['numberKey' => 'whatsapp_pqrs_number', 'renderer' => 'renderWhatsappNewPqrs', 'table' => null, 'contain' => []],
                'compra' => ['numberKey' => 'whatsapp_compras_number', 'renderer' => 'renderWhatsappNewCompra', 'table' => 'Compras', 'contain' => ['Requesters', 'Assignees']],
            ];

            $map = $configMap[$entityType];
            $config = $this->getConfig();

            if (!$config || empty($config[$map['numberKey']])) {
                Log::info("WhatsApp {$entityType} number not configured, skipping notification");
                return false;
            }

            // Load entity with required associations if needed
            if ($map['table'] !== null) {
                $table = $this->fetchTable($map['table']);
                $entity = $table->get($entity->id, contain: $map['contain']);
            }

            $message = $this->renderer->{$map['renderer']}($entity);

            return $this->sendMessage($config[$map['numberKey']], $message);
        } catch (\Exception $e) {
            Log::error("Failed to send WhatsApp {$entityType} notification", [
                'entity_id' => $entity->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Test WhatsApp connection
     *
     * @param string $module Module to test ('tickets', 'pqrs', or 'compras')
     * @return array Test result with status and message
     */
    public function testConnection(string $module = 'tickets'): array
    {
        $config = $this->getConfig();

        if (!$config) {
            return [
                'success' => false,
                'message' => 'WhatsApp está deshabilitado o no configurado',
            ];
        }

        // Get the appropriate number based on module
        $numberKey = "whatsapp_{$module}_number";
        if (empty($config[$numberKey])) {
            return [
                'success' => false,
                'message' => "No se ha configurado un número de WhatsApp para {$module}",
            ];
        }

        $moduleLabels = [
            'tickets' => 'Tickets',
            'pqrs' => 'PQRS',
            'compras' => 'Compras',
        ];
        $moduleLabel = $moduleLabels[$module] ?? $module;

        $testMessage = "✅ Prueba de conexión - Evolution API\n\n" .
            "Este es un mensaje de prueba del módulo de {$moduleLabel}.\n" .
            "Si recibes este mensaje, la integración está funcionando correctamente.\n\n" .
            "_Sistema de Soporte - {$moduleLabel}_";

        $result = $this->sendMessage($config[$numberKey], $testMessage);

        return [
            'success' => $result,
            'message' => $result
                ? 'Mensaje de prueba enviado exitosamente'
                : 'Error al enviar mensaje de prueba. Revisa los logs para más detalles.',
        ];
    }
}
