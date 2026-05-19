<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Constants\SettingKeys;
use App\Service\SettingsService;
use PHPUnit\Framework\TestCase;

final class SettingsServiceTest extends TestCase
{
    public function testKeyRequiresOAuthCachePurgeForClientSecret(): void
    {
        $this->assertTrue(SettingsService::keyRequiresOAuthCachePurge(SettingKeys::GMAIL_CLIENT_SECRET_JSON));
    }

    public function testKeyRequiresOAuthCachePurgeForRefreshToken(): void
    {
        $this->assertTrue(SettingsService::keyRequiresOAuthCachePurge(SettingKeys::GMAIL_REFRESH_TOKEN));
    }

    public function testKeyDoesNotRequirePurgeForHistoryCheckpoint(): void
    {
        $this->assertFalse(SettingsService::keyRequiresOAuthCachePurge(SettingKeys::GMAIL_LAST_HISTORY_ID));
    }

    public function testKeyDoesNotRequirePurgeForUserEmail(): void
    {
        // B-4 (P1) auto-populates this on OAuth callback; rewriting it should
        // NOT purge the OAuth cache because the cache entry is still valid.
        $this->assertFalse(SettingsService::keyRequiresOAuthCachePurge(SettingKeys::GMAIL_USER_EMAIL));
    }

    public function testKeyDoesNotRequirePurgeForUnrelatedSettings(): void
    {
        $this->assertFalse(SettingsService::keyRequiresOAuthCachePurge('system_title'));
        $this->assertFalse(SettingsService::keyRequiresOAuthCachePurge('whatsapp_api_url'));
    }
}
