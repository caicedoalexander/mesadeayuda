<?php
declare(strict_types=1);

namespace App\Service\Traits;

use Cake\Log\Log;

/**
 * Secure HTTP Trait
 *
 * Provides URL validation and secure cURL execution to prevent SSRF attacks.
 * Used by services that make outbound HTTP requests (N8nService, WhatsappService).
 */
trait SecureHttpTrait
{
    /**
     * Validate that a URL is safe AND resolve its hostname to an IP we control.
     *
     * Returns the resolved IP so the caller can pin curl to that exact address
     * via CURLOPT_RESOLVE — closing the DNS-rebinding window where a hostname
     * resolves to a public IP at validation time but to 127.0.0.1 at connect time.
     *
     * @param string $url URL to validate
     * @return array{ok: bool, error: ?string, host: ?string, port: ?int, ip: ?string}
     */
    private function resolveAndValidateUrl(string $url): array
    {
        $parsed = parse_url($url);

        if ($parsed === false || empty($parsed['scheme']) || empty($parsed['host'])) {
            return ['ok' => false, 'error' => 'URL inválida.', 'host' => null, 'port' => null, 'ip' => null];
        }

        $scheme = strtolower($parsed['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return [
                'ok' => false,
                'error' => 'Solo se permiten URLs con esquema http o https.',
                'host' => null, 'port' => null, 'ip' => null,
            ];
        }

        $host = strtolower($parsed['host']);
        $blockedHosts = ['localhost', '127.0.0.1', '::1', '0.0.0.0', '[::1]'];
        if (in_array($host, $blockedHosts, true)) {
            return [
                'ok' => false,
                'error' => 'No se permiten URLs apuntando a localhost.',
                'host' => $host, 'port' => null, 'ip' => null,
            ];
        }

        $port = isset($parsed['port']) ? (int)$parsed['port'] : ($scheme === 'https' ? 443 : 80);

        $ip = gethostbyname($host);
        if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            // gethostbyname returns the input on resolution failure. Fail closed:
            // we cannot enforce the private-IP guard without a real address.
            return [
                'ok' => false,
                'error' => 'No se pudo resolver el hostname.',
                'host' => $host, 'port' => $port, 'ip' => null,
            ];
        }

        if ($this->isPrivateIp($ip)) {
            return [
                'ok' => false,
                'error' => 'No se permiten URLs que resuelvan a direcciones IP privadas o internas.',
                'host' => $host, 'port' => $port, 'ip' => $ip,
            ];
        }

        return ['ok' => true, 'error' => null, 'host' => $host, 'port' => $port, 'ip' => $ip];
    }

    /**
     * Backwards-compatible wrapper that returns only the error message.
     *
     * @param string $url URL to validate
     * @return string|null Error message if invalid, null if valid
     */
    private function validateExternalUrl(string $url): ?string
    {
        return $this->resolveAndValidateUrl($url)['error'];
    }

    /**
     * Check if an IP address is in a private/internal range
     *
     * @param string $ip IP address to check
     * @return bool True if private/internal
     */
    private function isPrivateIp(string $ip): bool
    {
        // FILTER_FLAG_NO_PRIV_RANGE blocks: 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16
        // FILTER_FLAG_NO_RES_RANGE blocks: 0.0.0.0/8, 169.254.0.0/16, 127.0.0.0/8, 240.0.0.0/4
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }

    /**
     * Execute a secure cURL POST request with SSRF protections.
     *
     * Resolves the hostname once, validates the resulting IP, then pins curl
     * to that exact IP via CURLOPT_RESOLVE so a second DNS lookup at connect
     * time cannot redirect the request to a different (e.g. internal) host.
     *
     * @param string $url Target URL
     * @param string $jsonPayload JSON-encoded payload
     * @param array $headers HTTP headers
     * @param int $timeout Request timeout in seconds
     * @return array{success: bool, http_code: int, response: string|null, error: string|null}
     */
    private function secureCurlPost(string $url, string $jsonPayload, array $headers = [], int $timeout = 10): array
    {
        $resolution = $this->resolveAndValidateUrl($url);
        if (!$resolution['ok']) {
            Log::warning('SSRF protection blocked request', ['url' => $url, 'reason' => $resolution['error']]);

            return [
                'success' => false,
                'http_code' => 0,
                'response' => null,
                'error' => $resolution['error'],
                'curl_errno' => 0,
            ];
        }

        return $this->executeRawCurlPost($url, $jsonPayload, $headers, $timeout, $resolution);
    }

    /**
     * Raw cURL POST. Caller must pass a pre-validated $resolution from
     * resolveAndValidateUrl(). Returns the standard response shape plus
     * curl_errno (used by ResilientHttpClient to decide retries).
     *
     * @param array{ok: bool, error: ?string, host: ?string, port: ?int, ip: ?string} $resolution
     * @return array{success: bool, http_code: int, response: string|null, error: string|null, curl_errno: int}
     */
    private function executeRawCurlPost(string $url, string $jsonPayload, array $headers, int $timeout, array $resolution): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_TIMEOUT, min($timeout, 30));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);

        // Pin DNS to the IP we already validated. Format: "host:port:ip".
        if ($resolution['ip'] !== null && $resolution['host'] !== null && $resolution['port'] !== null) {
            curl_setopt($ch, CURLOPT_RESOLVE, [
                sprintf('%s:%d:%s', $resolution['host'], $resolution['port'], $resolution['ip']),
            ]);
        }

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);

        if ($error) {
            return [
                'success' => false,
                'http_code' => 0,
                'response' => null,
                'error' => $error,
                'curl_errno' => $errno,
            ];
        }

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'response' => $response ?: null,
            'error' => $httpCode >= 300 ? 'HTTP ' . $httpCode : null,
            'curl_errno' => $errno,
        ];
    }
}
