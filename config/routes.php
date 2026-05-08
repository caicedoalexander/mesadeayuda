<?php
/**
 * Routes configuration.
 *
 * In this file, you set up routes to your controllers and their actions.
 * Routes are very important mechanism that allows you to freely connect
 * different URLs to chosen controllers and their actions (functions).
 *
 * It's loaded within the context of `Application::routes()` method which
 * receives a `RouteBuilder` instance `$routes` as method argument.
 *
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */

use Cake\Routing\Route\DashedRoute;
use Cake\Routing\RouteBuilder;

/*
 * This file is loaded in the context of the `Application` class.
 * So you can use `$this` to reference the application class instance
 * if required.
 */
return function (RouteBuilder $routes): void {
    /*
     * The default class to use for all routes
     *
     * The following route classes are supplied with CakePHP and are appropriate
     * to set as the default:
     *
     * - Route
     * - InflectedRoute
     * - DashedRoute
     *
     * If no call is made to `Router::defaultRouteClass()`, the class used is
     * `Route` (`Cake\Routing\Route\Route`)
     *
     * Note that `Route` does not do any inflections on URLs which will result in
     * inconsistently cased URLs when used with `{plugin}`, `{controller}` and
     * `{action}` markers.
     */
    $routes->setRouteClass(DashedRoute::class);

    $routes->scope('/', function (RouteBuilder $builder): void {
        // Enable JSON extension for API endpoints
        $builder->setExtensions(['json']);
        // Gmail OAuth callback handler
        // When Google redirects to localhost:8080/?code=xxx, redirect to admin
        $builder->connect('/', ['controller' => 'Tickets', 'action' => 'index'], [
            '_name' => 'home'
        ]);

        // Health check endpoint for Docker monitoring
        // Verifies Nginx + PHP-FPM + PostgreSQL connectivity
        $builder->connect('/health', ['controller' => 'Health', 'action' => 'check'], [
            '_name' => 'health_check'
        ]);

        // Gmail OAuth callback (compat con configs legacy en Google Cloud Console)
        $builder->connect(
            '/oauth/gmail/callback',
            ['controller' => 'Settings', 'action' => 'gmailAuth', 'prefix' => 'Admin']
        );

        // Admin routes
        $builder->prefix('Admin', function (RouteBuilder $routes) {
            $routes->connect('/', ['controller' => 'Settings', 'action' => 'index']);
            $routes->fallbacks();
        });

        /*
         * Connect catchall routes for all controllers.
         */
        $builder->fallbacks();
    });

    $routes->scope('/webhooks', function (RouteBuilder $builder): void {
        $builder->setExtensions(['json']);
        $builder->post(
            '/gmail/import',
            ['controller' => 'Webhooks', 'action' => 'gmailImport'],
            'webhook_gmail_import'
        );
    });

    /*
     * If you need a different set of middleware or none at all,
     * open new scope and define routes there.
     *
     * ```
     * $routes->scope('/api', function (RouteBuilder $builder): void {
     *     // No $builder->applyMiddleware() here.
     *
     *     // Parse specified extensions from URLs
     *     // $builder->setExtensions(['json', 'xml']);
     *
     *     // Connect API actions here.
     * });
     * ```
     */
};
