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
     * Validate that a URL is safe for external requests (anti-SSRF)
     *
     * @param string $url URL to validate
     * @return string|null Error message if invalid, null if valid
     */
    private function validateExternalUrl(string $url): ?string
    {
        $parsed = parse_url($url);

        if ($parsed === false || empty($parsed['scheme']) || empty($parsed['host'])) {
            return 'URL inválida.';
        }

        // Only allow http/https schemes
        if (!in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
            return 'Solo se permiten URLs con esquema http o https.';
        }

        // Block localhost and loopback variations
        $host = strtolower($parsed['host']);
        $blockedHosts = ['localhost', '127.0.0.1', '::1', '0.0.0.0', '[::1]'];
        if (in_array($host, $blockedHosts, true)) {
            return 'No se permiten URLs apuntando a localhost.';
        }

        // Resolve hostname to IP and check for private ranges
        $ip = gethostbyname($host);
        if ($ip === $host) {
            // gethostbyname returns the hostname if resolution fails
            // Allow it through - DNS might resolve at curl time
            return null;
        }

        if ($this->isPrivateIp($ip)) {
            return 'No se permiten URLs que resuelvan a direcciones IP privadas o internas.';
        }

        return null;
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
     * Execute a secure cURL POST request with SSRF protections
     *
     * @param string $url Target URL (must pass validateExternalUrl)
     * @param string $jsonPayload JSON-encoded payload
     * @param array $headers HTTP headers
     * @param int $timeout Request timeout in seconds
     * @return array{success: bool, http_code: int, response: string|null, error: string|null}
     */
    private function secureCurlPost(string $url, string $jsonPayload, array $headers = [], int $timeout = 10): array
    {
        // Validate URL before making request
        $urlError = $this->validateExternalUrl($url);
        if ($urlError !== null) {
            Log::warning('SSRF protection blocked request', ['url' => $url, 'reason' => $urlError]);

            return [
                'success' => false,
                'http_code' => 0,
                'response' => null,
                'error' => $urlError,
            ];
        }

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

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($error) {
            return [
                'success' => false,
                'http_code' => 0,
                'response' => null,
                'error' => $error,
            ];
        }

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'response' => $response ?: null,
            'error' => $httpCode >= 300 ? 'HTTP ' . $httpCode : null,
        ];
    }
}
