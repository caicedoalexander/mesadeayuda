<?php
declare(strict_types=1);

namespace App\Controller\Trait;

use Cake\Http\Response;
use Cake\Log\Log;
use Exception;

/**
 * Bulk-operations region for TicketsController.
 */
trait TicketBulkTrait
{
    // region: Bulk — public dispatchers

    /**
     * Bulk assign tickets to an agent.
     */
    public function bulkAssign()
    {
        return $this->bulkAssignTickets();
    }

    /**
     * Bulk change priority of tickets.
     */
    public function bulkChangePriority()
    {
        return $this->bulkChangeTicketPriority();
    }

    /**
     * Bulk add tag to tickets.
     */
    public function bulkAddTag()
    {
        return $this->bulkAddTicketTag();
    }

    /**
     * Bulk delete tickets.
     */
    public function bulkDelete()
    {
        return $this->bulkDeleteTickets();
    }

    // endregion

    // region: Bulk — protected workhorses

    /**
     * Bulk assign tickets to an agent.
     */
    protected function bulkAssignTickets(): Response
    {
        $this->request->allowMethod(['post']);
        $entityIds = array_map('intval', explode(',', $this->request->getData('entity_ids') ?? $this->request->getData('ticket_ids') ?? ''));
        $agentId = $this->request->getData('agent_id') ?? $this->request->getData('assignee_id');
        $agentId = $this->normalizeAssigneeId($agentId);
        $user = $this->Authentication->getIdentity();
        $userId = $user ? $user->get('id') : 1;
        [$table, $service, $entityName] = $this->getEntityComponents();
        $successCount = 0;
        $errorCount = 0;
        foreach ($entityIds as $entityId) {
            try {
                $entity = $table->get($entityId);
                $service->assign($entity, $agentId, $userId);
                $successCount++;
            } catch (Exception $e) {
                $errorCount++;
                Log::error("Error in bulk assign ticket {$entityId}: " . $e->getMessage());
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

    /**
     * Bulk change priority of tickets.
     */
    protected function bulkChangeTicketPriority(): Response
    {
        $this->request->allowMethod(['post']);
        $entityIds = array_map('intval', explode(',', $this->request->getData('entity_ids') ?? $this->request->getData('ticket_ids') ?? ''));
        $newPriority = $this->request->getData('priority');
        $user = $this->Authentication->getIdentity();
        $userId = $user ? $user->get('id') : 1;
        [$table, , $entityName] = $this->getEntityComponents();
        $historyTable = $this->getHistoryTable();
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
                        "Prioridad cambiada de {$oldPriority} a {$newPriority}",
                    );
                    $successCount++;
                } else {
                    $errorCount++;
                }
            } catch (Exception $e) {
                $errorCount++;
                Log::error("Error in bulk priority change ticket {$entityId}: " . $e->getMessage());
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

    /**
     * Bulk add tag to tickets.
     */
    protected function bulkAddTicketTag(): Response
    {
        $this->request->allowMethod(['post']);
        $entityIds = array_map('intval', explode(',', $this->request->getData('entity_ids') ?? $this->request->getData('ticket_ids') ?? ''));
        $tagId = (int)$this->request->getData('tag_id');
        [, , $entityName] = $this->getEntityComponents();
        $tagsTable = $this->fetchTable('TicketTags');
        $foreignKey = 'ticket_id';
        $successCount = 0;
        $errorCount = 0;
        foreach ($entityIds as $entityId) {
            try {
                $exists = $tagsTable->exists([
                    $foreignKey => $entityId,
                    'tag_id' => $tagId,
                ]);
                if (!$exists) {
                    $entityTag = $tagsTable->newEntity([
                        $foreignKey => $entityId,
                        'tag_id' => $tagId,
                    ]);
                    if ($tagsTable->save($entityTag)) {
                        $successCount++;
                    } else {
                        $errorCount++;
                    }
                } else {
                    $successCount++;
                }
            } catch (Exception $e) {
                $errorCount++;
                Log::error("Error in bulk tag add ticket {$entityId}: " . $e->getMessage());
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

    /**
     * Bulk delete tickets.
     */
    protected function bulkDeleteTickets(): Response
    {
        $this->request->allowMethod(['post']);
        $entityIds = array_map('intval', explode(',', $this->request->getData('entity_ids') ?? $this->request->getData('ticket_ids') ?? ''));
        [$table, , $entityName] = $this->getEntityComponents();
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
            } catch (Exception $e) {
                $errorCount++;
                Log::error("Error in bulk delete ticket {$entityId}: " . $e->getMessage());
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

    // endregion
}
