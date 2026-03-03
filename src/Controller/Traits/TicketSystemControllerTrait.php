<?php
declare(strict_types=1);

namespace App\Controller\Traits;

use Cake\Http\Response;
use App\Service\Traits\GenericAttachmentTrait;
use App\Controller\Traits\ViewDataNormalizerTrait;

/**
 * TicketSystemControllerTrait
 *
 * Composite trait for Tickets, PQRS, and Compras controller logic.
 * Delegates to specialized sub-traits for SRP compliance:
 *
 * - TicketSystemActionsTrait: assign, changeStatus, changePriority, addComment, download
 * - TicketSystemBulkTrait: bulkAssign, bulkChangePriority, bulkAddTag, bulkDelete
 * - TicketSystemListingTrait: indexEntity, viewEntity, historyEntity + filter/pagination helpers
 *
 * Shared helpers (getEntityComponents, getHistoryTable) remain here
 * since they are used across all sub-traits.
 *
 * @package App\Controller\Traits
 */
trait TicketSystemControllerTrait
{
    use GenericAttachmentTrait;
    use ViewDataNormalizerTrait;
    use TicketSystemActionsTrait;
    use TicketSystemBulkTrait;
    use TicketSystemListingTrait;

    /**
     * Get entity components (table, service, display name) based on type
     *
     * Shared helper used by Actions, Bulk, and Listing sub-traits.
     *
     * @param string $entityType 'ticket', 'pqrs', or 'compra'
     * @return array Associative array with keys: table, service, displayName, tableName, foreignKey
     */
    private function getEntityComponents(string $entityType): array
    {
        $components = match ($entityType) {
            'ticket' => [
                'table' => $this->Tickets ?? $this->fetchTable('Tickets'),
                'service' => $this->ticketService ?? null,
                'displayName' => 'Ticket',
                'tableName' => 'Tickets',
                'foreignKey' => 'ticket_id',
            ],
            'pqrs' => [
                'table' => $this->Pqrs ?? $this->fetchTable('Pqrs'),
                'service' => $this->pqrsService ?? null,
                'displayName' => 'PQRS',
                'tableName' => 'Pqrs',
                'foreignKey' => 'pqrs_id',
            ],
            'compra' => [
                'table' => $this->Compras ?? $this->fetchTable('Compras'),
                'service' => $this->comprasService ?? null,
                'displayName' => 'Compra',
                'tableName' => 'Compras',
                'foreignKey' => 'compra_id',
            ],
            default => throw new \InvalidArgumentException("Invalid entity type: {$entityType}"),
        };

        // For backward compatibility, also add numeric indices
        return array_merge($components, [
            0 => $components['table'],
            1 => $components['service'],
            2 => $components['displayName'],
        ]);
    }

    /**
     * Get history table based on entity type
     *
     * Shared helper used by Bulk and Listing sub-traits.
     *
     * @param string $entityType 'ticket', 'pqrs', or 'compra'
     * @return \Cake\ORM\Table History table instance
     */
    private function getHistoryTable(string $entityType): \Cake\ORM\Table
    {
        return match ($entityType) {
            'ticket' => $this->fetchTable('TicketHistory'),
            'pqrs' => $this->fetchTable('PqrsHistory'),
            'compra' => $this->fetchTable('ComprasHistory'),
            default => throw new \InvalidArgumentException("Invalid entity type: {$entityType}"),
        };
    }
}
