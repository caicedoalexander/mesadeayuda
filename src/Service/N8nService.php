<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\SettingKeys;
use App\Model\Entity\Ticket;
use App\Service\Dto\SystemConfig;
use Cake\I18n\FrozenTime;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Exception;

/**
 * N8n Service
 *
 * Handles webhook integration with n8n for AI-powered tag assignment
 */
class N8nService
{
    use LocatorAwareTrait;
    use Traits\ConfigResolutionTrait;
    use Traits\SecureHttpTrait;

    /**
     * Resolved n8n settings.
     *  - null  = not yet resolved (lazy)
     *  - false = resolved but disabled / unconfigured
     *  - array = resolved and ready to use
     */
    private array|false|null $config = null;

    /**
     * Raw system configuration from constructor (passed-through DTO).
     */
    private ?array $systemConfig;

    /**
     * Constructor — does no I/O. Settings are resolved lazily on first use
     * via {@see getConfig()}. This keeps construction free of DB/cache reads
     * for callers that may never actually dispatch a webhook (e.g., a ticket
     * creation flow when n8n is disabled).
     *
     * @param \App\Service\Dto\SystemConfig|null $config Optional system configuration VO
     */
    public function __construct(?SystemConfig $config = null)
    {
        $this->systemConfig = $config?->toSettingsArray();
    }

    /**
     * Resolve n8n settings on demand. Caches the result for the lifetime of
     * this instance — three states: null (unresolved), false (disabled),
     * array (active). Mirrors {@see WhatsappService::getConfig()}.
     */
    private function getConfig(): ?array
    {
        if ($this->config !== null) {
            return $this->config === false ? null : $this->config;
        }

        $settings = $this->resolveSettingsBatch(SettingKeys::N8N_ENABLED, 'n8n_settings', [
            SettingKeys::N8N_ENABLED,
            SettingKeys::N8N_WEBHOOK_URL,
            SettingKeys::N8N_API_KEY,
            SettingKeys::N8N_SEND_TAGS_LIST,
            SettingKeys::N8N_TIMEOUT,
        ]);

        if (empty($settings[SettingKeys::N8N_ENABLED]) || $settings[SettingKeys::N8N_ENABLED] !== '1') {
            $this->config = false;

            return null;
        }

        $this->config = $settings;

        return $this->config;
    }

    /**
     * Send ticket created webhook to n8n
     *
     * @param \App\Model\Entity\Ticket $ticket Created ticket entity
     * @return bool Success status
     */
    public function sendTicketCreatedWebhook(Ticket $ticket): bool
    {
        $config = $this->getConfig();
        if ($config === null) {
            Log::debug('n8n integration is disabled or unconfigured');

            return false;
        }

        if (empty($config[SettingKeys::N8N_WEBHOOK_URL])) {
            Log::warning('n8n webhook URL is not configured');

            return false;
        }

        try {
            // Build webhook payload
            $payload = $this->buildTicketPayload($ticket, $config);

            // Send webhook
            $response = $this->sendWebhook($config[SettingKeys::N8N_WEBHOOK_URL], $payload, $config);

            if ($response['success']) {
                Log::info('n8n webhook sent successfully', [
                    'ticket_id' => $ticket->id,
                    'ticket_number' => $ticket->ticket_number,
                ]);

                return true;
            } else {
                Log::warning('n8n webhook failed', [
                    'ticket_id' => $ticket->id,
                    'error' => $response['error'] ?? 'Unknown error',
                ]);

                return false;
            }
        } catch (Exception $e) {
            Log::error('n8n webhook exception: ' . $e->getMessage(), [
                'ticket_id' => $ticket->id,
                'exception' => $e,
            ]);

            return false;
        }
    }

    /**
     * Build webhook payload for ticket
     *
     * @param \App\Model\Entity\Ticket $ticket Ticket entity
     * @param array $config Resolved n8n configuration
     * @return array Webhook payload
     */
    private function buildTicketPayload(Ticket $ticket, array $config): array
    {
        // Strip HTML for plain text version
        $descriptionPlain = strip_tags($ticket->description ?? '');

        // Build base payload
        $payload = [
            'event' => 'ticket.created',
            'timestamp' => FrozenTime::now()->toIso8601String(),
            'ticket' => [
                'id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'subject' => $ticket->subject,
                'description' => $ticket->description,
                'description_plain' => $descriptionPlain,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'created' => $ticket->created?->toIso8601String(),
                'gmail_message_id' => $ticket->gmail_message_id,
            ],
        ];

        // Add requester info if available
        if ($ticket->requester) {
            $payload['ticket']['requester'] = [
                'id' => $ticket->requester->id,
                'name' => $ticket->requester->name,
                'email' => $ticket->requester->email,
            ];
        }

        // Add attachments info if requested
        if (!empty($ticket->attachments)) {
            $payload['ticket']['attachments'] = [];
            foreach ($ticket->attachments as $attachment) {
                $payload['ticket']['attachments'][] = [
                    'id' => $attachment->id,
                    'filename' => $attachment->filename,
                    'size' => $attachment->file_size,
                    'mime_type' => $attachment->mime_type,
                ];
            }
        }

        // Add available tags if enabled
        if (!empty($config[SettingKeys::N8N_SEND_TAGS_LIST]) && $config[SettingKeys::N8N_SEND_TAGS_LIST] === '1') {
            $tagsTable = $this->fetchTable('Tags');
            $tags = $tagsTable->find()
                ->select(['id', 'name', 'color', 'description'])
                ->where(['is_active' => true])
                ->orderBy(['name' => 'ASC'])
                ->toArray();

            $payload['ticket']['available_tags'] = [];
            foreach ($tags as $tag) {
                $payload['ticket']['available_tags'][] = [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color ?? '#999999',
                    'description' => $tag->description ?? '',
                ];
            }
        }

        // Add callback URL for n8n to update tags
        $payload['callback_url'] = $this->getCallbackUrl();

        // Add app info
        $payload['app_info'] = [
            'version' => '1.0',
            'environment' => env('APP_ENV', 'production'),
        ];

        return $payload;
    }

    /**
     * Send webhook via cURL
     *
     * @param string $url Webhook URL
     * @param array $payload Payload data
     * @param array $config Resolved n8n configuration
     * @return array Response with success status
     */
    private function sendWebhook(string $url, array $payload, array $config): array
    {
        $timeout = (int)($config[SettingKeys::N8N_TIMEOUT] ?? 10);

        $headers = [
            'Content-Type: application/json',
            'User-Agent: TicketSystem/1.0',
        ];

        if (!empty($config[SettingKeys::N8N_API_KEY])) {
            $headers[] = 'X-API-Key: ' . $config[SettingKeys::N8N_API_KEY];
        }

        return $this->secureCurlPost($url, json_encode($payload), $headers, $timeout);
    }

    /**
     * Get callback URL for n8n to update tags
     *
     * @return string Callback URL
     */
    private function getCallbackUrl(): string
    {
        // You can implement this later when you need n8n to send tags back
        // For now, return a placeholder
        return env('APP_URL', 'http://localhost') . '/api/webhooks/n8n/tags';
    }

    /**
     * Test n8n connection
     *
     * @return array Result with success and message
     */
    public function testConnection(): array
    {
        $config = $this->getConfig();
        if ($config === null || empty($config[SettingKeys::N8N_WEBHOOK_URL])) {
            return [
                'success' => false,
                'message' => 'URL del webhook de n8n no configurada',
            ];
        }

        try {
            $testPayload = [
                'event' => 'connection.test',
                'timestamp' => FrozenTime::now()->toIso8601String(),
                'test' => true,
            ];

            $response = $this->sendWebhook($config[SettingKeys::N8N_WEBHOOK_URL], $testPayload, $config);

            if ($response['success']) {
                return [
                    'success' => true,
                    'message' => 'Conexión exitosa con n8n (HTTP ' . $response['http_code'] . ')',
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Error al conectar con n8n: ' . ($response['error'] ?? 'HTTP ' . ($response['http_code'] ?? 'unknown')),
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ];
        }
    }
}
