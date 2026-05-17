<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\Exception\GmailApiException;
use App\Service\Gmail\GmailErrorCategory;
use App\Service\GmailService;
use App\Service\Util\NotificationStamp;
use Cake\Core\Configure;
use Google\Client as GoogleClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
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

    /** Build a fake Gmail header object that quacks like Google\Service\Gmail\MessagePartHeader. */
    private function header(string $name, string $value): object
    {
        return new class ($name, $value) {
            public function __construct(private string $name, private string $value)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getValue(): string
            {
                return $this->value;
            }
        };
    }

    /**
     * GmailService::getSystemEmail() honors $this->config['user_email'] (added
     * for testability), so injecting via the constructor is enough.
     */
    private function buildServiceWithSystemEmail(string $email): GmailService
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
            'user_email' => $email,
        ]);
    }

    public function testIsSystemNotificationAcceptsStampedSubject(): void
    {
        $service = $this->buildService();
        $stamped = NotificationStamp::append('Tu ticket #42 fue creado', '42');
        $headers = [$this->header('Subject', $stamped)];

        $this->assertTrue($service->isSystemNotification($headers));
    }

    public function testIsSystemNotificationRejectsUnstampedReplyWithoutLegacyHeader(): void
    {
        $service = $this->buildService();
        $headers = [
            $this->header('Subject', 'Re: Tu ticket #42 fue creado'),
            $this->header('From', 'cliente@externo.tld'),
        ];

        $this->assertFalse($service->isSystemNotification($headers));
    }

    public function testIsSystemNotificationRejectsLegacyHeaderWithoutDkimPass(): void
    {
        $service = $this->buildService();
        $headers = [
            $this->header('Subject', 'Re: Tu ticket #42 fue creado'),
            $this->header('From', 'cliente@externo.tld'),
            $this->header('X-Mesa-Ayuda-Notification', 'true'),
            // No Authentication-Results header at all.
        ];

        $this->assertFalse($service->isSystemNotification($headers));
    }

    public function testIsSystemNotificationRejectsLegacyHeaderWithDkimPassForAttackerDomain(): void
    {
        $service = $this->buildServiceWithSystemEmail('soporte@mesa.test');
        $headers = [
            $this->header('Subject', 'Re: Tu ticket #42 fue creado'),
            $this->header('From', 'cliente@externo.tld'),
            $this->header('X-Mesa-Ayuda-Notification', 'true'),
            $this->header(
                'Authentication-Results',
                'mx.google.com; dkim=pass header.i=@attacker.tld header.d=attacker.tld',
            ),
        ];

        $this->assertFalse($service->isSystemNotification($headers));
    }

    public function testIsSystemNotificationAcceptsLegacyHeaderWithDkimPassForOwnDomain(): void
    {
        $service = $this->buildServiceWithSystemEmail('soporte@mesa.test');
        $headers = [
            $this->header('Subject', 'Re: Tu ticket #42 fue creado'),
            $this->header('From', 'cliente@externo.tld'),
            $this->header('X-Mesa-Ayuda-Notification', 'true'),
            $this->header(
                'Authentication-Results',
                'mx.google.com; spf=pass; dkim=pass header.i=@mesa.test header.d=mesa.test; dmarc=pass',
            ),
        ];

        $this->assertTrue($service->isSystemNotification($headers));
    }

    public function testIsSystemNotificationAcceptsWhenFromMatchesSystemEmail(): void
    {
        $service = $this->buildServiceWithSystemEmail('soporte@mesa.test');
        $headers = [
            $this->header('Subject', 'Re: Tu ticket #42 fue creado'),
            $this->header('From', 'Soporte <soporte@mesa.test>'),
        ];

        $this->assertTrue($service->isSystemNotification($headers));
    }

    public function testOnlyGmailModifyScopeIsRequested(): void
    {
        $service = $this->buildService();
        $scopes = $this->getClient($service)->getScopes();

        $this->assertSame(
            ['https://www.googleapis.com/auth/gmail.modify'],
            $scopes,
            'Scope set must be exactly gmail.modify (subsumes readonly + send).',
        );
    }

    /**
     * Replace the underlying Guzzle client on the Google SDK client with a
     * MockHandler queue. The SDK then thinks it talked to Gmail and throws
     * Google\Service\Exception with the right code on non-2xx responses.
     *
     * @param list<\GuzzleHttp\Psr7\Response> $responses
     */
    private function stubHttp(GmailService $service, array $responses): void
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $http = new GuzzleClient(['handler' => $stack]);
        $this->getClient($service)->setHttpClient($http);
    }

    public function testParseMessageWrapsGoogleServiceExceptionWithRateCategory(): void
    {
        $service = $this->buildService();
        $this->stubHttp($service, [new Response(
            429,
            [],
            '{"error":{"code":429,"message":"quota"}}',
        )]);

        try {
            $service->parseMessage('msg-id');
            $this->fail('Expected GmailApiException');
        } catch (GmailApiException $e) {
            $this->assertSame(GmailErrorCategory::RATE, $e->getCategory());
            $this->assertSame(429, $e->getCode());
        }
    }

    public function testMarkAsReadReturnsFalseOnAuthError(): void
    {
        $service = $this->buildService();
        $this->stubHttp($service, [new Response(
            401,
            [],
            '{"error":{"code":401,"message":"unauth"}}',
        )]);

        $this->assertFalse($service->markAsRead('msg-id'));
    }

    public function testGetMessagesReturnsEmptyOnTransient5xx(): void
    {
        $service = $this->buildService();
        $this->stubHttp($service, [new Response(
            503,
            [],
            '{"error":{"code":503,"message":"unavailable"}}',
        )]);

        $this->assertSame([], $service->getMessages('is:unread', 5));
    }
}
