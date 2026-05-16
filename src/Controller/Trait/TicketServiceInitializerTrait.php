<?php
declare(strict_types=1);

namespace App\Controller\Trait;

use App\Constants\CacheConstants;
use App\Constants\TicketConstants;
use App\Service\AuthorizationService;
use App\Service\Dto\SystemConfig;
use App\Service\TicketPipelineService;
use Cake\Cache\Cache;
use Cake\ORM\Table;

/**
 * Initialization, view-data normalization, and table/history helpers
 * shared by all Tickets controller regions.
 */
trait TicketServiceInitializerTrait
{
    // region: ServiceInitializer

    /**
     * @param array<string, class-string> $serviceMap Map of property names to class names
     * @return void
     */
    protected function initializeServices(array $serviceMap): void
    {
        $raw = Cache::read(CacheConstants::CACHE_SETTINGS, CacheConstants::CACHE_CONFIG);
        $config = SystemConfig::fromSettingsArray(is_array($raw) ? $raw : null);

        foreach ($serviceMap as $propertyName => $serviceClass) {
            $this->{$propertyName} = new $serviceClass($config);
        }
    }

    /**
     * @return void
     */
    protected function initializeTicketSystemServices(): void
    {
        $this->initializeServices([
            'ticketPipeline' => TicketPipelineService::class,
        ]);

        $this->authService = new AuthorizationService();
    }

    // endregion

    // region: ViewDataNormalizer

    /**
     * Status display configuration with icons, colors and labels.
     */
    protected function getStatusConfig(): array
    {
        $config = [];
        foreach (TicketConstants::STATUSES as $status) {
            $config[$status] = [
                'icon' => TicketConstants::STATUS_ICONS[$status] ?? 'bi-circle-fill',
                'color' => TicketConstants::STATUS_COLORS[$status] ?? '#6c757d',
                'label' => TicketConstants::STATUS_LABELS[$status] ?? ucfirst($status),
            ];
        }

        return $config;
    }

    /**
     * Priority options for dropdowns and display.
     */
    protected function getPriorityConfig(): array
    {
        return TicketConstants::PRIORITY_LABELS;
    }

    // endregion

    // region: TicketSystemController helpers

    /**
     * @return \Cake\ORM\Table
     */
    private function getHistoryTable(): Table
    {
        return $this->fetchTable('TicketHistory');
    }

    // endregion
}
