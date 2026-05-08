<?php
declare(strict_types=1);

namespace App\Service\Dto;

use App\Constants\SettingKeys;

final readonly class WhatsappConfig
{
    /**
     * @param bool $enabled Whether WhatsApp integration is enabled
     * @param string $apiUrl Evolution API URL
     * @param string $apiKey Evolution API key (decrypted)
     * @param string $instanceName Evolution instance name
     * @param string $ticketsNumber Default destination number
     */
    public function __construct(
        public bool $enabled,
        public string $apiUrl,
        public string $apiKey,
        public string $instanceName,
        public string $ticketsNumber,
    ) {
    }

    /**
     * @param array<string, mixed> $raw Raw settings array
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            enabled: ($raw[SettingKeys::WHATSAPP_ENABLED] ?? '') === '1',
            apiUrl: (string)($raw[SettingKeys::WHATSAPP_API_URL] ?? ''),
            apiKey: (string)($raw[SettingKeys::WHATSAPP_API_KEY] ?? ''),
            instanceName: (string)($raw[SettingKeys::WHATSAPP_INSTANCE_NAME] ?? ''),
            ticketsNumber: (string)($raw[SettingKeys::WHATSAPP_TICKETS_NUMBER] ?? ''),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            SettingKeys::WHATSAPP_ENABLED => $this->enabled ? '1' : '0',
            SettingKeys::WHATSAPP_API_URL => $this->apiUrl,
            SettingKeys::WHATSAPP_API_KEY => $this->apiKey,
            SettingKeys::WHATSAPP_INSTANCE_NAME => $this->instanceName,
            SettingKeys::WHATSAPP_TICKETS_NUMBER => $this->ticketsNumber,
        ];
    }
}
