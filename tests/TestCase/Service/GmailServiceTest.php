<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\GmailService;
use Cake\Core\Configure;
use Google\Client as GoogleClient;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use ReflectionClass;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

final class GmailServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (Configure::read('Security.salt') === null) {
            Configure::write('Security.salt', str_repeat('a', 64));
        }
    }

    private function buildService(): GmailService
    {
        return new GmailService([
            'client_secret' => [
                'web' => [
                    'client_id' => 'fake-client-id.apps.googleusercontent.com',
                    'client_secret' => 'fake-secret',
                    'redirect_uris' => ['https://example.test/oauth/callback'],
                    'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
                    'token_uri' => 'https://oauth2.googleapis.com/token',
                ],
            ],
            'redirect_uri' => 'https://example.test/oauth/callback',
            'refresh_token' => '',
        ]);
    }

    private function getClient(GmailService $service): GoogleClient
    {
        $ref = new ReflectionClass($service);

        return $ref->getProperty('client')->getValue($service);
    }

    public function testPsr6CacheIsConfiguredOnInitialize(): void
    {
        $service = $this->buildService();
        $cache = $this->getClient($service)->getCache();

        $this->assertInstanceOf(CacheItemPoolInterface::class, $cache);
        // Stronger assertion: must be the file-backed adapter, not the SDK
        // default MemoryCacheItemPool (which would not survive across requests).
        $this->assertInstanceOf(FilesystemAdapter::class, $cache);
    }
}
