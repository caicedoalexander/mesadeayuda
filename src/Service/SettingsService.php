<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\CacheConstants;
use App\Constants\SettingKeys;
use App\Service\Traits\SettingsEncryptionTrait;
use Cake\Cache\Cache;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Settings Service
 *
 * Centralizes system settings management:
 * - Save settings with automatic encryption for sensitive values
 * - Load all settings with caching and automatic decryption
 * - Cache invalidation across all service-specific caches
 */
class SettingsService
{
    use LocatorAwareTrait;
    use SettingsEncryptionTrait;

    /**
     * Cache keys that must be invalidated when any setting changes
     */
    private const CACHE_KEYS = [
        CacheConstants::CACHE_SETTINGS,
        'whatsapp_settings',
        'n8n_settings',
        'gmail_settings',
    ];

    private const CACHE_CONFIG = CacheConstants::CACHE_CONFIG;

    /**
     * Save or update a system setting (with automatic encryption)
     *
     * @param string $key Setting key
     * @param string $value Setting value (plain text)
     * @return bool Success status
     */
    public function saveSetting(string $key, string $value): bool
    {
        $settingsTable = $this->fetchTable('SystemSettings');
        $setting = $settingsTable->find()->where(['setting_key' => $key])->first();

        // Encrypt sensitive values automatically
        $valueToStore = $this->shouldEncrypt($key)
            ? $this->encryptSetting($value, $key)
            : $value;

        if ($setting) {
            $setting->setting_value = $valueToStore;
            $setting->modified = new DateTime();
        } else {
            $setting = $settingsTable->newEntity([
                'setting_key' => $key,
                'setting_value' => $valueToStore,
                'setting_type' => 'string',
            ], ['accessibleFields' => ['setting_key' => true, 'setting_type' => true]]);
        }

        $result = (bool)$settingsTable->save($setting);

        if ($result) {
            $this->clearAllCaches();
            if (self::keyRequiresOAuthCachePurge($key)) {
                $this->clearGmailOAuthCache();
            }
        }

        return $result;
    }

    /**
     * Returns true when writing the given setting key must purge the Gmail
     * OAuth PSR-6 cache (because the credentials those tokens were bound to
     * have rotated). Pure predicate — no I/O, no state — exposed for testing
     * and to keep the policy explicit.
     *
     * Notably excluded: GMAIL_LAST_HISTORY_ID (M-2 checkpoint, persisted every
     * webhook run — purging the OAuth cache here would burn a token refresh
     * every minute) and GMAIL_USER_EMAIL (B-4, auto-populated on OAuth
     * callback — the existing token is still valid for the same mailbox).
     */
    public static function keyRequiresOAuthCachePurge(string $key): bool
    {
        return in_array(
            $key,
            [SettingKeys::GMAIL_CLIENT_SECRET_JSON, SettingKeys::GMAIL_REFRESH_TOKEN],
            true,
        );
    }

    /**
     * Load all settings as associative array (with automatic decryption)
     *
     * @return array Settings array with key => value pairs (decrypted)
     */
    public function loadAll(): array
    {
        $settings = Cache::read(CacheConstants::CACHE_SETTINGS, self::CACHE_CONFIG);

        if ($settings === null) {
            $settingsTable = $this->fetchTable('SystemSettings');
            $settings = $settingsTable->find()
                ->select(['setting_key', 'setting_value'])
                ->all()
                ->combine('setting_key', 'setting_value')
                ->toArray();

            // Decrypt and cache
            $settings = $this->processSettings($settings);
            Cache::write(CacheConstants::CACHE_SETTINGS, $settings, self::CACHE_CONFIG);
        }

        return $settings;
    }

    /**
     * Clear all settings-related caches
     *
     * @return void
     */
    public function clearAllCaches(): void
    {
        foreach (self::CACHE_KEYS as $cacheKey) {
            Cache::delete($cacheKey, self::CACHE_CONFIG);
        }
    }

    /**
     * Purge the Gmail OAuth PSR-6 cache directory used by GmailService so the
     * next instance fetches a fresh access_token bound to the new credentials.
     *
     * @return void
     */
    private function clearGmailOAuthCache(): void
    {
        $cacheDir = TMP . 'gmail_oauth_cache';
        if (!is_dir($cacheDir)) {
            return;
        }
        foreach (glob($cacheDir . '/*') ?: [] as $entry) {
            if (is_file($entry)) {
                unlink($entry);
            } elseif (is_dir($entry)) {
                foreach (glob($entry . '/*') ?: [] as $nested) {
                    if (is_file($nested)) {
                        unlink($nested);
                    }
                }
            }
        }
    }
}
