<?php
declare(strict_types=1);

namespace App\Controller\Traits;

use App\Service\ComprasService;
use App\Utility\ValidationConstants;
use App\Service\PqrsService;
use App\Service\StatisticsService;
use App\Service\TicketService;

/**
 * ServiceInitializerTrait
 *
 * Provides clean service initialization for controllers
 * Eliminates ~30 lines of duplicated code across 3 controllers
 *
 * Requirements:
 * - Controller must have viewBuilder() method
 * - System config must be set in parent beforeFilter()
 */
trait ServiceInitializerTrait
{
    /**
     * Initialize services based on provided configuration
     *
     * Example usage:
     * ```php
     * $this->initializeServices([
     *     'ticketService' => TicketService::class,
     *     'statisticsService' => StatisticsService::class, // No systemConfig needed
     * ]);
     * ```
     *
     * @param array<string, class-string> $serviceMap Map of property names to class names
     * @return void
     */
    protected function initializeServices(array $serviceMap): void
    {
        // Read full config from cache (view config excludes sensitive keys like api_key/tokens)
        $systemConfig = \Cake\Cache\Cache::read(ValidationConstants::CACHE_SETTINGS, ValidationConstants::CACHE_CONFIG);

        // Services that don't need systemConfig
        $noConfigServices = [
            StatisticsService::class,
        ];

        foreach ($serviceMap as $propertyName => $serviceClass) {
            // Check if service needs systemConfig
            if (in_array($serviceClass, $noConfigServices, true)) {
                $this->{$propertyName} = new $serviceClass();
            } else {
                $this->{$propertyName} = new $serviceClass($systemConfig);
            }
        }
    }

    /**
     * Initialize standard ticket system services
     *
     * Convenience method for controllers that use the full ticket system
     * (TicketsController)
     *
     * @return void
     */
    protected function initializeTicketSystemServices(): void
    {
        $this->initializeServices([
            'ticketService' => TicketService::class,
            'statisticsService' => StatisticsService::class,
            'comprasService' => ComprasService::class,
        ]);
    }

    /**
     * Initialize standard PQRS services
     *
     * Convenience method for PqrsController
     *
     * @return void
     */
    protected function initializePqrsServices(): void
    {
        $this->initializeServices([
            'pqrsService' => PqrsService::class,
            'statisticsService' => StatisticsService::class,
        ]);
    }

    /**
     * Initialize standard Compras services
     *
     * Convenience method for ComprasController
     *
     * @return void
     */
    protected function initializeComprasServices(): void
    {
        $this->initializeServices([
            'comprasService' => ComprasService::class,
            'statisticsService' => StatisticsService::class,
            'ticketService' => TicketService::class,
        ]);
    }
}
