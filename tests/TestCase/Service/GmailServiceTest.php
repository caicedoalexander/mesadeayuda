<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\Exception\GmailApiException;
use App\Service\Gmail\GmailErrorCategory;
use App\Service\Gmail\RetryHandler;
use App\Service\GmailService;
use App\Service\Util\NotificationStamp;
use Cake\Core\Configure;
use Google\Client as GoogleClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
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

    public function testMarkAsReadThrowsGmailApiExceptionOnAuthError(): void
    {
        $service = $this->buildService();
        $this->stubHttp($service, [new Response(
            401,
            [],
            '{"error":{"code":401,"message":"unauth"}}',
        )]);

        try {
            $service->markAsRead('msg-id');
            $this->fail('Expected GmailApiException');
        } catch (GmailApiException $e) {
            $this->assertSame(GmailErrorCategory::AUTH, $e->getCategory());
            $this->assertSame(401, $e->getCode());
        }
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

    public function testRetryMiddlewareIsRegisteredOnTheGoogleClient(): void
    {
        $service = $this->buildService();
        $httpClient = $this->getClient($service)->getHttpClient();
        $this->assertInstanceOf(GuzzleClient::class, $httpClient);

        // Guzzle\Client::getConfig() is deprecated in 7.5+; read the handler
        // via reflection to stay version-stable.
        $ref = new ReflectionClass($httpClient);
        $configProp = $ref->getProperty('config');
        $config = $configProp->getValue($httpClient);

        $stack = $config['handler'] ?? null;
        $this->assertInstanceOf(HandlerStack::class, $stack);
        // HandlerStack stringifies its middleware list; the retry middleware
        // identifies itself with the literal "retry".
        $this->assertStringContainsString('retry', (string)$stack);
    }

    public function testRetryMiddlewareSucceedsAfter429Retries(): void
    {
        $mock = new MockHandler([
            new Response(429, [], 'rate'),
            new Response(429, [], 'rate'),
            new Response(200, [], 'ok'),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::retry(
            RetryHandler::decider(),
            // override delay to 0 so the test runs instantly
            static fn(): int => 0,
        ));
        $client = new GuzzleClient(['handler' => $stack]);

        $response = $client->request('GET', 'https://example.test/');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', (string)$response->getBody());
    }

    public function testGetUserEmailReturnsAddressFromUsersGetProfile(): void
    {
        $service = $this->buildService();
        $this->stubHttp($service, [new Response(
            200,
            ['Content-Type' => 'application/json'],
            '{"emailAddress":"soporte@example.com","messagesTotal":42,"threadsTotal":17,"historyId":"123"}',
        )]);

        $this->assertSame('soporte@example.com', $service->getUserEmail());
    }

    public function testGetUserEmailThrowsPermanentExceptionOnEmptyAddress(): void
    {
        $service = $this->buildService();
        $this->stubHttp($service, [new Response(
            200,
            ['Content-Type' => 'application/json'],
            '{"emailAddress":"","messagesTotal":0,"threadsTotal":0,"historyId":"0"}',
        )]);

        try {
            $service->getUserEmail();
            $this->fail('Expected GmailApiException');
        } catch (GmailApiException $e) {
            $this->assertSame(GmailErrorCategory::PERMANENT, $e->getCategory());
        }
    }

    public function testParseMessageExtractsRfcThreadingHeaders(): void
    {
        $service = $this->buildService();

        $payload = json_encode([
            'id' => 'gmail-id-1',
            'threadId' => 'thread-1',
            'historyId' => '999',
            'payload' => [
                'headers' => [
                    ['name' => 'Message-ID', 'value' => '<root@example.com>'],
                    ['name' => 'In-Reply-To', 'value' => '<previous@example.com>'],
                    ['name' => 'References', 'value' => '<a@x.com> <b@x.com> <previous@example.com>'],
                    ['name' => 'From', 'value' => 'Alice <alice@example.com>'],
                    ['name' => 'Subject', 'value' => 'Test thread'],
                ],
                'mimeType' => 'text/plain',
                'body' => ['data' => rtrim(strtr(base64_encode('hello'), '+/', '-_'), '='), 'size' => 5],
            ],
        ]);

        $this->stubHttp($service, [new Response(200, ['Content-Type' => 'application/json'], $payload)]);

        $data = $service->parseMessage('gmail-id-1');

        $this->assertSame('root@example.com', $data['rfc_message_id']);
        $this->assertSame('previous@example.com', $data['in_reply_to']);
        $this->assertSame('<a@x.com> <b@x.com> <previous@example.com>', $data['references_header']);
    }

    public function testGetUserEmailWrapsGoogleServiceException(): void
    {
        $service = $this->buildService();
        // 401 is not in the retriable set (decider returns false for 401),
        // so the SDK throws Google\Service\Exception(401) after the first
        // response and our typed catch wraps it.
        $this->stubHttp($service, [new Response(
            401,
            ['Content-Type' => 'application/json'],
            '{"error":{"code":401,"message":"token revoked"}}',
        )]);

        try {
            $service->getUserEmail();
            $this->fail('Expected GmailApiException');
        } catch (GmailApiException $e) {
            $this->assertSame(GmailErrorCategory::AUTH, $e->getCategory());
            $this->assertSame(401, $e->getCode());
        }
    }
}
