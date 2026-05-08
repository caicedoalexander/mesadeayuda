<?php
declare(strict_types=1);

namespace App\Service\Traits;

use App\Constants\CacheConstants;
use Cake\Cache\Cache;
use Cake\Log\Log;
use Exception;

/**
 * ConfigResolutionTrait
 *
 * Provides 3-tier configuration resolution for services:
 * 1. Constructor-provided systemConfig (fastest, no I/O)
 * 2. Main 'system_settings' cache (populated by AppController)
 * 3. Service-specific DB query with its own cache
 *
 * Requirements:
 * - Using class must use LocatorAwareTrait (for fetchTable())
 * - Using class must have a $systemConfig property (?array)
 */
trait ConfigResolutionTrait
{
    /**
     * Resolve a batch of settings from the best available source
     *
     * @param string $presenceKey Key to check for presence in config (e.g., 'whatsapp_enabled')
     * @param string $serviceCacheKey Service-specific cache key (e.g., 'whatsapp_settings')
     * @param array $requiredKeys Setting keys to fetch from DB as fallback
     * @return array Settings array with key => value pairs
     */
    protected function resolveSettingsBatch(
        string $presenceKey,
        string $serviceCacheKey,
        array $requiredKeys,
    ): array {
        // 1. From constructor systemConfig
        if ($this->systemConfig !== null && isset($this->systemConfig[$presenceKey])) {
            return $this->systemConfig;
        }

        // 2. From main settings cache (populated by AppController::beforeFilter)
        $cachedConfig = Cache::read(CacheConstants::CACHE_SETTINGS, CacheConstants::CACHE_CONFIG);
        if ($cachedConfig && isset($cachedConfig[$presenceKey])) {
            return $cachedConfig;
        }

        // 3. Service-specific DB query with its own cache
        return Cache::remember($serviceCacheKey, function () use ($requiredKeys) {
            $settingsTable = $this->fetchTable('SystemSettings');

            return $settingsTable->find()
                ->where(['setting_key IN' => $requiredKeys])
                ->all()
                ->combine('setting_key', 'setting_value')
                ->toArray();
        }, CacheConstants::CACHE_CONFIG);
    }

    /**
     * Resolve a single setting value from the best available source
     *
     * @param string $key Setting key
     * @param string $default Default value if not found
     * @return string Setting value
     */
    protected function resolveSettingValue(string $key, string $default = ''): string
    {
        // 1. From constructor config
        if ($this->systemConfig !== null && isset($this->systemConfig[$key])) {
            return $this->systemConfig[$key];
        }

        // 2. From main settings cache
        try {
            $cachedConfig = Cache::read(CacheConstants::CACHE_SETTINGS, CacheConstants::CACHE_CONFIG);
            if ($cachedConfig && isset($cachedConfig[$key])) {
                return $cachedConfig[$key];
            }
        } catch (Exception $e) {
            // Cache not available, fall through to DB
        }

        // 3. Direct DB query
        try {
            $settingsTable = $this->fetchTable('SystemSettings');
            $setting = $settingsTable->find()
                ->where(['setting_key' => $key])
                ->first();

            return $setting ? $setting->setting_value : $default;
        } catch (Exception $e) {
            Log::error("Failed to load setting '{$key}': " . $e->getMessage());

            return $default;
        }
    }
}
