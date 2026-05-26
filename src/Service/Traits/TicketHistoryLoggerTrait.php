<?php
declare(strict_types=1);

namespace App\Service\Traits;

use Cake\Datasource\EntityInterface;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Provides logHistory() to write entries to history tables (e.g., ticket_history).
 * Consumed by ticket-domain services that mutate auditable fields.
 *
 * userId semantics: NULL means "system event" — used for inbound ingestion
 * (Gmail/WhatsApp), scheduled jobs, or any mutation without a logged-in actor.
 * The history table's changed_by column is nullable to support this.
 */
trait TicketHistoryLoggerTrait
{
    use LocatorAwareTrait;

    /**
     * Log change to a history table (e.g., ticket_history). Failures are
     * logged via Cake\Log but never propagated — audit is best-effort and
     * must not block the surrounding business operation.
     *
     * @param string $tableName Table alias (e.g., 'TicketHistory')
     * @param string $foreignKey Foreign key column on the history table
     * @param int $entityId ID of the audited entity
     * @param string $fieldName Field that changed
     * @param string|null $oldValue Previous value
     * @param string|null $newValue New value
     * @param int|null $userId User performing the change (NULL = system event)
     * @param string|null $description Human-readable description
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
            $result = $historyTable->logChange($entityId, $fieldName, $oldValue, $newValue, $userId, $description);
            if ($result === false) {
                Log::warning('History logChange returned false', [
                    'table' => $tableName,
                    'entity_id' => $entityId,
                    'field' => $fieldName,
                ]);
            }

            return;
        }

        $history = $historyTable->newEntity([
            $foreignKey => $entityId,
            'changed_by' => $userId,
            'field_name' => $fieldName,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'description' => $description,
        ], ['accessibleFields' => ['changed_by' => true]]);

        $saved = $historyTable->save($history);
        if (!$saved instanceof EntityInterface) {
            Log::warning('History save returned false', [
                'table' => $tableName,
                'entity_id' => $entityId,
                'field' => $fieldName,
                'errors' => $history->getErrors(),
            ]);
        }
    }
}
