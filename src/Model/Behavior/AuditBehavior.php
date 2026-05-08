<?php
declare(strict_types=1);

namespace App\Model\Behavior;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Behavior;
use RuntimeException;

/**
 * AuditBehavior
 *
 * Provides a generic logChange() method for TicketHistoryTable.
 *
 * Configuration:
 * - 'foreignKey': The FK field name (e.g., 'ticket_id')
 *
 * Usage in Table::initialize():
 *   $this->addBehavior('Audit', ['foreignKey' => 'ticket_id']);
 */
class AuditBehavior extends Behavior
{
    /**
     * Default config
     *
     * @var array<string, mixed>
     */
    protected array $_defaultConfig = [
        'foreignKey' => null,
    ];

    /**
     * Log a change to the history table
     *
     * @param int $entityId Entity ID (ticket)
     * @param string $fieldName Field that changed
     * @param string|null $oldValue Old value
     * @param string|null $newValue New value
     * @param int|null $userId User who made the change (null for system)
     * @param string|null $description Human-readable description
     * @return \Cake\Datasource\EntityInterface|false
     */
    public function logChange(
        int $entityId,
        string $fieldName,
        ?string $oldValue,
        ?string $newValue,
        ?int $userId = null,
        ?string $description = null,
    ): EntityInterface|false {
        $table = $this->table();
        $foreignKey = $this->getConfig('foreignKey');

        if (!$foreignKey) {
            throw new RuntimeException('AuditBehavior requires foreignKey config');
        }

        $description = $description ?? "Campo '{$fieldName}' cambiado de '{$oldValue}' a '{$newValue}'";

        $history = $table->newEntity([
            $foreignKey => $entityId,
            'changed_by' => $userId,
            'field_name' => $fieldName,
            'old_value' => $oldValue !== null ? (string)$oldValue : null,
            'new_value' => $newValue !== null ? (string)$newValue : null,
            'description' => $description,
        ], ['accessibleFields' => ['changed_by' => true]]);

        return $table->save($history);
    }
}
