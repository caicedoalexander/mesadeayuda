<?php
declare(strict_types=1);

namespace App\Service\Dto;

/**
 * SMTP configuration placeholder.
 *
 * Helpdesk uses Gmail API for outbound mail today. Kept here for future
 * SMTP fallback / alternate provider scenarios. Always empty in current
 * codebase.
 */
final readonly class SmtpConfig
{
    /**
     * @param string $host SMTP host
     * @param string $port SMTP port
     * @param string $username SMTP username
     * @param string $password SMTP password
     * @param string $fromAddress From email address
     * @param string $fromName From display name
     * @param bool $tls Whether to use TLS
     */
    public function __construct(
        public string $host = '',
        public string $port = '',
        public string $username = '',
        public string $password = '',
        public string $fromAddress = '',
        public string $fromName = '',
        public bool $tls = false,
    ) {
    }

    /**
     * @param array<string, mixed> $raw Raw settings array
     */
    public static function fromArray(array $raw): self
    {
        return new self();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [];
    }
}
