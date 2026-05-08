<?php
declare(strict_types=1);

namespace App\Controller\Trait;

use App\Constants\CacheConstants;
use App\Constants\TicketConstants;
use App\Service\TicketService;
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
        $systemConfig = Cache::read(CacheConstants::CACHE_SETTINGS, CacheConstants::CACHE_CONFIG);

        foreach ($serviceMap as $propertyName => $serviceClass) {
            $this->{$propertyName} = new $serviceClass($systemConfig);
        }
    }

    /**
     * @return void
     */
    protected function initializeTicketSystemServices(): void
    {
        $this->initializeServices([
            'ticketService' => TicketService::class,
        ]);
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

    /**
     * Status keys considered "resolved".
     */
    protected function getResolvedStatuses(): array
    {
        return TicketConstants::RESOLVED_STATUSES;
    }

    // endregion

    // region: TicketSystemController helpers

    /**
     * @return array{table: \Cake\ORM\Table, service: ?\App\Service\TicketService, displayName: string, tableName: string, foreignKey: string, 0: \Cake\ORM\Table, 1: ?\App\Service\TicketService, 2: string}
     */
    private function getEntityComponents(): array
    {
        $components = [
            'table' => $this->Tickets ?? $this->fetchTable('Tickets'),
            'service' => $this->ticketService ?? null,
            'displayName' => 'Ticket',
            'tableName' => 'Tickets',
            'foreignKey' => 'ticket_id',
        ];

        return array_merge($components, [
            0 => $components['table'],
            1 => $components['service'],
            2 => $components['displayName'],
        ]);
    }

    /**
     * @return \Cake\ORM\Table
     */
    private function getHistoryTable(): Table
    {
        return $this->fetchTable('TicketHistory');
    }

    // endregion
}
