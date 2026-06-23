<?php
declare(strict_types=1);

namespace App\Utility;

use Cake\Log\Log;
use Cake\Utility\Security;
use Exception;
use RuntimeException;

/**
 * Settings Encryption Trait
 *
 * Provides automatic encryption/decryption for sensitive system settings.
 * Similar to password hashing for users, but reversible (two-way encryption).
 *
 * Usage:
 * - Use encryptSetting() when saving to database
 * - Use decryptSetting() when reading from database
 */
trait SettingsEncryptionTrait
{
    /**
     * List of setting keys that should be encrypted
     *
     * @var array
     */
    private array $encryptedSettings = [
        SettingKeys::GMAIL_REFRESH_TOKEN,
        SettingKeys::GMAIL_CLIENT_SECRET_JSON,
        SettingKeys::WHATSAPP_API_KEY,
        SettingKeys::N8N_API_KEY,
        SettingKeys::WEBHOOK_GMAIL_IMPORT_TOKEN,
    ];

    /**
     * Check if a setting key should be encrypted
     *
     * @param string $key Setting key
     * @return bool
     */
    protected function shouldEncrypt(string $key): bool
    {
        return in_array($key, $this->encryptedSettings, true);
    }

    /**
     * Encrypt a setting value
     *
     * @param string $value Plain text value
     * @param string $key Setting key (for context)
     * @return string Encrypted value
     */
    protected function encryptSetting(string $value, string $key): string
    {
        if (empty($value)) {
            return '';
        }

        // Use CakePHP's Security::encrypt() with app's salt
        // Security::encrypt() returns binary data, so we encode in base64 for storage
        $encrypted = Security::encrypt($value, $this->getEncryptionKey());

        // Convert to base64 to safely store in TEXT column (UTF-8 compatible)
        $base64 = base64_encode($encrypted);

        // Prefix to identify encrypted values
        return '{encrypted}' . $base64;
    }

    /**
     * Decrypt a setting value
     *
     * @param string|null $value Encrypted value
     * @param string $key Setting key (for context)
     * @return string Plain text value
     */
    protected function decryptSetting(?string $value, string $key): string
    {
        if (empty($value)) {
            return '';
        }

        // Check if value is encrypted
        if (!str_starts_with($value, '{encrypted}')) {
            // Not encrypted, return as-is
            return $value;
        }

        // Remove prefix and decode from base64
        $base64Value = substr($value, 11); // Remove '{encrypted}'
        $encryptedValue = base64_decode($base64Value, true);

        if ($encryptedValue === false) {
            Log::error('Failed to base64 decode setting: ' . $key);

            return '';
        }

        try {
            $decrypted = Security::decrypt($encryptedValue, $this->getEncryptionKey());

            // Security::decrypt() can return false, null, or string
            if ($decrypted === false || $decrypted === null) {
                return '';
            }

            return (string)$decrypted;
        } catch (Exception $e) {
            Log::error('Failed to decrypt setting: ' . $key, [
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Get encryption key from app configuration
     *
     * @return string Encryption key
     * @throws \RuntimeException If Security.salt is not configured
     */
    private function getEncryptionKey(): string
    {
        // Use app's security salt as encryption key
        // Security::getSalt() is the correct way after bootstrap consumes it
        $salt = Security::getSalt();

        if (empty($salt)) {
            throw new RuntimeException(
                'Security.salt is not configured. Please set SECURITY_SALT environment variable.',
            );
        }

        return $salt;
    }

    /**
     * Process settings array - decrypt encrypted values
     *
     * @param array $settings Array of setting_key => setting_value
     * @return array Processed settings with decrypted values
     */
    protected function processSettings(array $settings): array
    {
        $processed = [];

        foreach ($settings as $key => $value) {
            if ($this->shouldEncrypt($key)) {
                $processed[$key] = $this->decryptSetting($value, $key);
            } else {
                $processed[$key] = $value;
            }
        }

        return $processed;
    }
}
