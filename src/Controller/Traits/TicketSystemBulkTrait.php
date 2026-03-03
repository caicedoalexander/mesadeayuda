<?php
declare(strict_types=1);

namespace App\Controller\Traits;

use Cake\Http\Response;

/**
 * TicketSystemBulkTrait
 *
 * Bulk operation methods: assign, change priority, add tag, delete.
 * Extracted from TicketSystemControllerTrait for SRP compliance.
 */
trait TicketSystemBulkTrait
{
    protected function bulkAssignEntity(string $entityType): Response
    {
        $this->request->allowMethod(['post']);
        $entityIds = array_map('intval', explode(',', $this->request->getData('entity_ids') ?? $this->request->getData('ticket_ids') ?? ''));
        $agentId = $this->request->getData('agent_id') ?? $this->request->getData('assignee_id');
        $agentId = $this->normalizeAssigneeId($agentId);
        $user = $this->Authentication->getIdentity();
        $userId = $user ? $user->get('id') : 1;
        [$table, $service, $entityName] = $this->getEntityComponents($entityType);
        $successCount = 0;
        $errorCount = 0;
        foreach ($entityIds as $entityId) {
            try {
                $entity = $table->get($entityId);
                $service->assign($entity, $agentId, $userId);
                $successCount++;
            } catch (\Exception $e) {
                $errorCount++;
                \Cake\Log\Log::error("Error in bulk assign {$entityType} {$entityId}: " . $e->getMessage());
            }
        }
        if ($successCount > 0) {
            $this->Flash->success(__("{$successCount} {$entityName}(s) asignado(s) correctamente."));
        }
        if ($errorCount > 0) {
            $this->Flash->error(__("{$errorCount} {$entityName}(s) no pudieron ser asignados."));
        }

        return $this->redirect(['action' => 'index']);
    }

    protected function bulkChangeEntityPriority(string $entityType): Response
    {
        $this->request->allowMethod(['post']);
        $entityIds = array_map('intval', explode(',', $this->request->getData('entity_ids') ?? $this->request->getData('ticket_ids') ?? ''));
        $newPriority = $this->request->getData('priority');
        $user = $this->Authentication->getIdentity();
        $userId = $user ? $user->get('id') : 1;
        [$table, $service, $entityName] = $this->getEntityComponents($entityType);
        $historyTable = $this->getHistoryTable($entityType);
        $successCount = 0;
        $errorCount = 0;
        foreach ($entityIds as $entityId) {
            try {
                $entity = $table->get($entityId);
                $oldPriority = $entity->priority;
                $entity->priority = $newPriority;
                if ($table->save($entity)) {
                    $historyTable->logChange(
                        $entity->id,
                        'priority',
                        $oldPriority,
                        $newPriority,
                        $userId,
                        "Prioridad cambiada de {$oldPriority} a {$newPriority}"
                    );
                    $successCount++;
                } else {
                    $errorCount++;
                }
            } catch (\Exception $e) {
                $errorCount++;
                \Cake\Log\Log::error("Error in bulk priority change {$entityType} {$entityId}: " . $e->getMessage());
            }
        }
        if ($successCount > 0) {
            $this->Flash->success(__("{$successCount} {$entityName}(s) actualizado(s) correctamente."));
        }
        if ($errorCount > 0) {
            $this->Flash->error(__("{$errorCount} {$entityName}(s) no pudieron ser actualizados."));
        }

        return $this->redirect(['action' => 'index']);
    }

    protected function bulkAddTagEntity(string $entityType): Response
    {
        $this->request->allowMethod(['post']);
        $entityIds = array_map('intval', explode(',', $this->request->getData('entity_ids') ?? $this->request->getData('ticket_ids') ?? ''));
        $tagId = (int) $this->request->getData('tag_id');
        [, , $entityName] = $this->getEntityComponents($entityType);
        $tagsTableName = $this->getTagsTableName($entityType);
        $tagsTable = $this->fetchTable($tagsTableName);
        $foreignKey = $entityType . '_id';
        $successCount = 0;
        $errorCount = 0;
        foreach ($entityIds as $entityId) {
            try {
                $exists = $tagsTable->exists([
                    $foreignKey => $entityId,
                    'tag_id' => $tagId
                ]);
                if (!$exists) {
                    $entityTag = $tagsTable->newEntity([
                        $foreignKey => $entityId,
                        'tag_id' => $tagId
                    ]);
                    if ($tagsTable->save($entityTag)) {
                        $successCount++;
                    } else {
                        $errorCount++;
                    }
                } else {
                    $successCount++;
                }
            } catch (\Exception $e) {
                $errorCount++;
                \Cake\Log\Log::error("Error in bulk tag add {$entityType} {$entityId}: " . $e->getMessage());
            }
        }
        if ($successCount > 0) {
            $this->Flash->success(__("Etiqueta agregada a {$successCount} {$entityName}(s)."));
        }
        if ($errorCount > 0) {
            $this->Flash->error(__("{$errorCount} {$entityName}(s) no pudieron ser etiquetados."));
        }

        return $this->redirect(['action' => 'index']);
    }

    protected function bulkDeleteEntity(string $entityType): Response
    {
        $this->request->allowMethod(['post']);
        $entityIds = array_map('intval', explode(',', $this->request->getData('entity_ids') ?? $this->request->getData('ticket_ids') ?? ''));
        [$table, , $entityName] = $this->getEntityComponents($entityType);
        $successCount = 0;
        $errorCount = 0;
        foreach ($entityIds as $entityId) {
            try {
                $entity = $table->get($entityId);
                if ($table->delete($entity)) {
                    $successCount++;
                } else {
                    $errorCount++;
                }
            } catch (\Exception $e) {
                $errorCount++;
                \Cake\Log\Log::error("Error in bulk delete {$entityType} {$entityId}: " . $e->getMessage());
            }
        }
        if ($successCount > 0) {
            $this->Flash->success(__("{$successCount} {$entityName}(s) eliminado(s) correctamente."));
        }
        if ($errorCount > 0) {
            $this->Flash->error(__("{$errorCount} {$entityName}(s) no pudieron ser eliminados."));
        }

        return $this->redirect(['action' => 'index']);
    }

    private function getTagsTableName(string $entityType): string
    {
        return match ($entityType) {
            'ticket' => 'TicketTags',
            'pqrs' => 'PqrsTags',
            'compra' => 'ComprasTags',
            default => throw new \InvalidArgumentException("Invalid entity type: {$entityType}"),
        };
    }
}
