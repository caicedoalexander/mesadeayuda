<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link      https://cakephp.org CakePHP(tm) Project
 * @since     3.3.0
 * @license   https://opensource.org/licenses/mit-license.php MIT License
 */
namespace App;

use App\Constants\CacheConstants;
use App\Listener\TicketNotificationListener;
use App\Service\Dto\SystemConfig;
use App\Service\TicketNotificationService;
use Authentication\AuthenticationService;
use Authentication\AuthenticationServiceInterface;
use Authentication\AuthenticationServiceProviderInterface;
use Authentication\Middleware\AuthenticationMiddleware;
use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Core\ContainerInterface;
use Cake\Datasource\FactoryLocator;
use Cake\Error\Middleware\ErrorHandlerMiddleware;
use Cake\Event\EventManager;
use Cake\Http\BaseApplication;
use Cake\Http\Middleware\BodyParserMiddleware;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\Middleware\SecurityHeadersMiddleware;
use Cake\Http\MiddlewareQueue;
use Cake\ORM\Locator\TableLocator;
use Cake\Routing\Middleware\AssetMiddleware;
use Cake\Routing\Middleware\RoutingMiddleware;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Application setup class.
 *
 * This defines the bootstrapping logic and middleware layers you
 * want to use in your application.
 *
 * @extends \Cake\Http\BaseApplication<\App\Application>
 */
class Application extends BaseApplication implements AuthenticationServiceProviderInterface
{
    /**
     * Load all the application configuration and bootstrap logic.
     *
     * @return void
     */
    public function bootstrap(): void
    {
        // Call parent to load bootstrap from files.
        parent::bootstrap();

        if (PHP_SAPI !== 'cli') {
            // The bake plugin requires fallback table classes to work properly
            FactoryLocator::add('Table', (new TableLocator())->allowFallbackClass(false));
        }

        $this->registerDomainEventListeners();
    }

    /**
     * Register listeners for domain events on the global EventManager.
     *
     * @return void
     */
    private function registerDomainEventListeners(): void
    {
        // Lazy: TicketNotificationService is built only if/when a domain event
        // actually fires, so CLI commands that never dispatch tickets
        // (`bin/cake migrations migrate`, `bin/cake bake`, …) skip the work.
        $notificationsFactory = static function (): TicketNotificationService {
            $raw = Cache::read(CacheConstants::CACHE_SETTINGS, CacheConstants::CACHE_CONFIG);
            $config = SystemConfig::fromSettingsArray(is_array($raw) ? $raw : null);

            return new TicketNotificationService($config);
        };

        EventManager::instance()->on(new TicketNotificationListener($notificationsFactory));
    }

    /**
     * Setup the middleware queue your application will use.
     *
     * @param \Cake\Http\MiddlewareQueue $middlewareQueue The middleware queue to setup.
     * @return \Cake\Http\MiddlewareQueue The updated middleware queue.
     */
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        $middlewareQueue
            // Catch any exceptions in the lower layers,
            // and make an error page/response
            ->add(new ErrorHandlerMiddleware(Configure::read('Error'), $this))

            // Handle plugin/theme assets like CakePHP normally does.
            ->add(new AssetMiddleware([
                'cacheTime' => Configure::read('Asset.cacheTime'),
            ]))

            // Add routing middleware.
            // If you have a large number of routes connected, turning on routes
            // caching in production could improve performance.
            // See https://github.com/CakeDC/cakephp-cached-routing
            ->add(new RoutingMiddleware($this))

            // Parse various types of encoded request bodies so that they are
            // available as array through $request->getData()
            // https://book.cakephp.org/5/en/controllers/middleware.html#body-parser-middleware
            ->add(new BodyParserMiddleware())

            // Cross Site Request Forgery (CSRF) Protection Middleware
            // https://book.cakephp.org/5/en/security/csrf.html#cross-site-request-forgery-csrf-middleware
            ->add((new CsrfProtectionMiddleware([
                'httponly' => true,
            ]))->skipCheckCallback(static function ($request): bool {
                return str_starts_with($request->getUri()->getPath(), '/webhooks/');
            }))

            // Security headers middleware
            ->add((new SecurityHeadersMiddleware())
                ->noSniff()
                ->setXFrameOptions('sameorigin')
                ->setXssProtection('block')
                ->setReferrerPolicy('strict-origin-when-cross-origin')
                ->setPermissionsPolicy('camera=(), microphone=(), geolocation=()')
                ->setCrossDomainPolicy('none'))

            // Content-Security-Policy header (not supported by SecurityHeadersMiddleware)
            ->add(function ($request, $handler) {
                $response = $handler->handle($request);
                $csp = implode('; ', [
                    "default-src 'self'",
                    "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://code.jquery.com",
                    "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com",
                    "font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com",
                    "img-src 'self' data: https:",
                    "connect-src 'self' https://cdn.jsdelivr.net",
                    "frame-ancestors 'self'",
                    "base-uri 'self'",
                    "form-action 'self'",
                ]);

                return $response->withHeader('Content-Security-Policy', $csp);
            })

            // Add authentication middleware
            ->add(new AuthenticationMiddleware($this));

        return $middlewareQueue;
    }

    /**
     * Returns a service provider instance.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Request
     * @return \Authentication\AuthenticationServiceInterface
     */
    public function getAuthenticationService(ServerRequestInterface $request): AuthenticationServiceInterface
    {
        $authenticationService = new AuthenticationService([
            'unauthenticatedRedirect' => '/users/login',
            'queryParam' => 'redirect',
        ]);

        $fields = [
            'username' => 'email',
            'password' => 'password',
        ];

        $authenticationService->loadAuthenticator('Authentication.Session');
        $authenticationService->loadAuthenticator('Authentication.Form', [
            'fields' => $fields,
            'loginUrl' => '/users/login',
            'identifier' => [
                'className' => 'Authentication.Password',
                'fields' => $fields,
            ],
        ]);

        return $authenticationService;
    }

    /**
     * Register application container services.
     *
     * @param \Cake\Core\ContainerInterface $container The Container to update.
     * @return void
     * @link https://book.cakephp.org/5/en/development/dependency-injection.html#dependency-injection
     */
    public function services(ContainerInterface $container): void
    {
    }
}
