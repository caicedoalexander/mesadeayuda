<?php
declare(strict_types=1);

namespace App\Service\Dto;

/**
 * System-wide configuration value object.
 *
 * Composes per-domain sub-configs built from the `system_settings` snapshot.
 * Constructed once per request in TicketServiceInitializerTrait::initializeServices
 * and passed by-reference to all ticket services.
 *
 * Use SystemConfig::empty() in tests or when no settings cache is available.
 */
final readonly class SystemConfig
{
    /**
     * @param \App\Service\Dto\GmailConfig $gmail Gmail sub-config
     * @param \App\Service\Dto\SmtpConfig $smtp SMTP sub-config (placeholder)
     * @param \App\Service\Dto\N8nConfig $n8n n8n sub-config
     * @param \App\Service\Dto\WhatsappConfig $whatsapp WhatsApp sub-config
     * @param \App\Service\Dto\AppConfig $app App-level sub-config
     */
    public function __construct(
        public GmailConfig $gmail,
        public SmtpConfig $smtp,
        public N8nConfig $n8n,
        public WhatsappConfig $whatsapp,
        public AppConfig $app,
    ) {
    }

    /**
     * Build a SystemConfig from a system_settings snapshot array.
     *
     * Tolerates missing keys: each sub-config sets safe defaults when
     * a key is absent.
     *
     * @param array<string, mixed>|null $raw Raw settings (key => value)
     */
    public static function fromSettingsArray(?array $raw): self
    {
        $raw ??= [];

        return new self(
            gmail: GmailConfig::fromArray($raw),
            smtp: SmtpConfig::fromArray($raw),
            n8n: N8nConfig::fromArray($raw),
            whatsapp: WhatsappConfig::fromArray($raw),
            app: AppConfig::fromArray($raw),
        );
    }

    /**
     * Empty instance with safe defaults — useful for tests and CLI bootstrap.
     */
    public static function empty(): self
    {
        return self::fromSettingsArray([]);
    }

    /**
     * Flat array of all setting key => value pairs.
     *
     * Used to bridge the VO into legacy code paths that still consume
     * the raw settings array (ConfigResolutionTrait, EmailTemplateRenderer).
     *
     * @return array<string, mixed>
     */
    public function toSettingsArray(): array
    {
        return array_merge(
            $this->gmail->toArray(),
            $this->smtp->toArray(),
            $this->n8n->toArray(),
            $this->whatsapp->toArray(),
            $this->app->toArray(),
        );
    }
}
