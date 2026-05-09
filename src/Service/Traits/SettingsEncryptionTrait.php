<?php
declare(strict_types=1);

namespace App\Service\Traits;

use App\Constants\SettingKeys;
use App\Service\Exception\SettingsEncryptionException;
use Cake\Log\Log;
use Cake\Utility\Security;
use Exception;

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
     * Marker prefix used to identify ciphertext payloads in storage.
     * Format on disk: ENCRYPTION_PREFIX . base64(Security::encrypt(plain, salt))
     */
    private const ENCRYPTION_PREFIX = '{encrypted}';

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
     * Encrypt a setting value.
     *
     * Idempotent: if the input is already a ciphertext payload (prefixed),
     * it is returned unchanged to prevent double-encryption when forms
     * round-trip an encrypted blob.
     *
     * @param string $value Plain text value (or already-encrypted payload)
     * @param string $key Setting key (for context)
     * @return string Encrypted value (with prefix)
     */
    protected function encryptSetting(string $value, string $key): string
    {
        unset($key); // reserved for future per-key context (e.g., AAD)

        if ($value === '') {
            return '';
        }

        // Idempotency guard: never double-encrypt a payload that is already encrypted.
        if (str_starts_with($value, self::ENCRYPTION_PREFIX)) {
            return $value;
        }

        $encrypted = Security::encrypt($value, $this->getEncryptionKey());

        return self::ENCRYPTION_PREFIX . base64_encode($encrypted);
    }

    /**
     * Decrypt a setting value.
     *
     * Fail-loud semantics: throws SettingsEncryptionException for any real
     * decryption failure (corrupt base64, OpenSSL failure, salt rotation,
     * tampering). Empty / null input legitimately means "no value yet" and
     * returns ''. Plaintext values without the encryption prefix are returned
     * as-is for backwards compatibility with non-encrypted keys.
     *
     * @param string|null $value Encrypted value (or null/'' when unset)
     * @param string $key Setting key (for context)
     * @return string Plain text value
     * @throws \App\Service\Exception\SettingsEncryptionException on decrypt failure
     */
    protected function decryptSetting(?string $value, string $key): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (!str_starts_with($value, self::ENCRYPTION_PREFIX)) {
            return $value;
        }

        $base64Value = substr($value, strlen(self::ENCRYPTION_PREFIX));
        $encryptedValue = base64_decode($base64Value, true);

        if ($encryptedValue === false) {
            throw new SettingsEncryptionException(
                'Failed to base64-decode encrypted setting: ' . $key,
            );
        }

        try {
            $decrypted = Security::decrypt($encryptedValue, $this->getEncryptionKey());
        } catch (Exception $e) {
            throw new SettingsEncryptionException(
                'Failed to decrypt setting: ' . $key . ' (' . $e->getMessage() . ')',
                0,
                $e,
            );
        }

        if ($decrypted === false || $decrypted === null) {
            // Most common cause: Security.salt was rotated or ciphertext was tampered.
            throw new SettingsEncryptionException(
                'Decryption returned empty for setting: ' . $key
                . ' (likely salt rotation or tampered ciphertext)',
            );
        }

        return (string)$decrypted;
    }

    /**
     * Get encryption key from app configuration
     *
     * @return string Encryption key
     * @throws \App\Service\Exception\SettingsEncryptionException If Security.salt is not configured
     */
    private function getEncryptionKey(): string
    {
        $salt = Security::getSalt();

        if (empty($salt)) {
            throw new SettingsEncryptionException(
                'Security.salt is not configured. Please set SECURITY_SALT environment variable.',
            );
        }

        return $salt;
    }

    /**
     * Process settings array - decrypt encrypted values.
     *
     * Resilience: a single corrupted encrypted setting must not bring down the
     * whole settings load. If decryption fails, the offending key is logged and
     * **excluded** from the result (callers see an absent key rather than an
     * empty string, which would be unsafe for tokens compared via hash_equals).
     *
     * @param array $settings Array of setting_key => setting_value
     * @return array Processed settings with decrypted values (failed keys absent)
     */
    protected function processSettings(array $settings): array
    {
        $processed = [];

        foreach ($settings as $key => $value) {
            if (!$this->shouldEncrypt($key)) {
                $processed[$key] = $value;
                continue;
            }

            try {
                $processed[$key] = $this->decryptSetting($value, $key);
            } catch (SettingsEncryptionException $e) {
                Log::error('Settings: cannot decrypt key, excluding from runtime settings', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);
                // Intentionally do NOT add the key. Absence is safer than '' for tokens.
            }
        }

        return $processed;
    }
}
