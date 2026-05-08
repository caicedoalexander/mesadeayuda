<?php
declare(strict_types=1);

namespace App\Service\Traits;

use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Provides logHistory() to write entries to history tables (e.g., ticket_history).
 * Consumed by ticket-domain services that mutate auditable fields.
 */
trait TicketHistoryLoggerTrait
{
    use LocatorAwareTrait;

    /**
     * Log change to a history table (e.g., ticket_history).
     *
     * @param string $tableName Table alias (e.g., 'TicketHistory')
     * @param string $foreignKey Foreign key column on the history table
     * @param int $entityId ID of the audited entity
     * @param string $fieldName Field that changed
     * @param string|null $oldValue Previous value
     * @param string|null $newValue New value
     * @param int|null $userId User performing the change
     * @param string|null $description Human-readable description
     * @return void
     */
    private function logHistory(
        string $tableName,
        string $foreignKey,
        int $entityId,
        string $fieldName,
        ?string $oldValue,
        ?string $newValue,
        ?int $userId = null,
        ?string $description = null,
    ): void {
        $historyTable = $this->fetchTable($tableName);

        if (method_exists($historyTable, 'logChange')) {
            $historyTable->logChange($entityId, $fieldName, $oldValue, $newValue, $userId, $description);
        } else {
            $history = $historyTable->newEntity([
                $foreignKey => $entityId,
                'changed_by' => $userId,
                'field_name' => $fieldName,
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'description' => $description,
            ], ['accessibleFields' => ['changed_by' => true]]);
            $historyTable->save($history);
        }
    }
}
