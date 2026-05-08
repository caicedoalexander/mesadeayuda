<?php
declare(strict_types=1);

namespace App\Service\Dto;

use App\Constants\SettingKeys;

final readonly class N8nConfig
{
    /**
     * @param bool $enabled Whether n8n integration is enabled
     * @param string $webhookUrl n8n webhook URL
     * @param string $apiKey n8n API key (decrypted)
     * @param string $sendTagsList Comma-separated list of tags to forward
     * @param string $timeout HTTP timeout (seconds)
     */
    public function __construct(
        public bool $enabled,
        public string $webhookUrl,
        public string $apiKey,
        public string $sendTagsList,
        public string $timeout,
    ) {
    }

    /**
     * @param array<string, mixed> $raw Raw settings array
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            enabled: ($raw[SettingKeys::N8N_ENABLED] ?? '') === '1',
            webhookUrl: (string)($raw[SettingKeys::N8N_WEBHOOK_URL] ?? ''),
            apiKey: (string)($raw[SettingKeys::N8N_API_KEY] ?? ''),
            sendTagsList: (string)($raw[SettingKeys::N8N_SEND_TAGS_LIST] ?? ''),
            timeout: (string)($raw[SettingKeys::N8N_TIMEOUT] ?? ''),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            SettingKeys::N8N_ENABLED => $this->enabled ? '1' : '0',
            SettingKeys::N8N_WEBHOOK_URL => $this->webhookUrl,
            SettingKeys::N8N_API_KEY => $this->apiKey,
            SettingKeys::N8N_SEND_TAGS_LIST => $this->sendTagsList,
            SettingKeys::N8N_TIMEOUT => $this->timeout,
        ];
    }
}
