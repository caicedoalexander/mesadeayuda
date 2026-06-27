<?php
declare(strict_types=1);

namespace App\Controller\Trait;

use App\Service\Exception\UnauthorizedAssignmentException;
use App\Service\TicketPipelineService;
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
        $entityIds = $this->parseEntityIds();
        $agentId = $this->request->getData('agent_id') ?? $this->request->getData('assignee_id');
        $agentId = $this->normalizeAssigneeId($agentId);
        $actor = $this->Authentication->getIdentity();
        $userId = $this->getCurrentUserId();
        $table = $this->fetchTable('Tickets');
        $service = $this->ticketPipeline;

        // Early actor guard: abort whole batch if actor cannot assign
        if ($this->authService->isAssignmentDisabled($actor)) {
            $this->Flash->error(__('No tienes permisos para asignar tickets.'));

            return $this->redirect(['action' => 'index']);
        }

        $successCount = 0;
        $errorCount = 0;
        $unauthorizedCount = 0;
        foreach ($entityIds as $entityId) {
            try {
                $entity = $table->get($entityId);
                $service->assign($entity, $agentId, $userId, $actor);
                $successCount++;
            } catch (UnauthorizedAssignmentException $e) {
                $unauthorizedCount++;
                Log::warning("Bulk assign blocked for ticket {$entityId}: " . $e->getMessage());
            } catch (Exception $e) {
                $errorCount++;
                Log::error("Error in bulk assign ticket {$entityId}: " . $e->getMessage());
            }
        }
        if ($successCount > 0) {
            $this->Flash->success(__("{$successCount} Ticket(s) asignado(s) correctamente."));
        }
        if ($unauthorizedCount > 0) {
            $this->Flash->warning(__("{$unauthorizedCount} Ticket(s) no se asignaron por reglas de autorización (lockeado, usuario inactivo o no-staff)."));
        }
        if ($errorCount > 0) {
            $this->Flash->error(__("{$errorCount} Ticket(s) no pudieron ser asignados."));
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Bulk change priority of tickets.
     *
     * Routes through TicketPipelineService::changePriority so the audit
     * trail (system internal comment + history) and the isLocked() invariant
     * stay in lockstep with the single-ticket flow.
     */
    protected function bulkChangeTicketPriority(): Response
    {
        $this->request->allowMethod(['post']);
        $entityIds = $this->parseEntityIds();
        $newPriority = $this->request->getData('priority');
        $userId = $this->getCurrentUserId();
        $table = $this->fetchTable('Tickets');
        $service = $this->ticketPipeline;

        $successCount = 0;
        $errorCount = 0;
        $lockedCount = 0;
        foreach ($entityIds as $entityId) {
            try {
                $entity = $table->get($entityId);
                if ($entity->isLocked()) {
                    $lockedCount++;
                    continue;
                }
                if ($service->changePriority($entity, $newPriority, $userId)) {
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
            $this->Flash->success(__("{$successCount} Ticket(s) actualizado(s) correctamente."));
        }
        if ($lockedCount > 0) {
            $this->Flash->warning(__("{$lockedCount} Ticket(s) en estado final no fueron modificados."));
        }
        if ($errorCount > 0) {
            $this->Flash->error(__("{$errorCount} Ticket(s) no pudieron ser actualizados."));
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Bulk add tag to tickets.
     *
     * Routes through TicketPipelineService::addTag so the history entry and
     * race-handling logic match the single-ticket flow (INC-5 fix).
     */
    protected function bulkAddTicketTag(): Response
    {
        $this->request->allowMethod(['post']);
        $entityIds = $this->parseEntityIds();
        $tagId = (int)$this->request->getData('tag_id');
        $userId = $this->getCurrentUserId();
        $service = $this->ticketPipeline;
        $successCount = 0;
        $errorCount = 0;
        foreach ($entityIds as $entityId) {
            try {
                $result = $service->addTag($entityId, $tagId, $userId);
                if ($result['success'] || $result['message'] === TicketPipelineService::MESSAGE_TAG_ALREADY_ADDED) {
                    $successCount++;
                } else {
                    $errorCount++;
                }
            } catch (Exception $e) {
                $errorCount++;
                Log::error("Error in bulk tag add ticket {$entityId}: " . $e->getMessage());
            }
        }
        if ($successCount > 0) {
            $this->Flash->success(__("Etiqueta agregada a {$successCount} Ticket(s)."));
        }
        if ($errorCount > 0) {
            $this->Flash->error(__("{$errorCount} Ticket(s) no pudieron ser etiquetados."));
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Bulk delete tickets.
     *
     * Writes a terminal history entry BEFORE the delete so the audit trail
     * survives the cascade. The delete itself is non-blocking when the row
     * is gone (subsequent FK cascade removes child rows).
     */
    protected function bulkDeleteTickets(): Response
    {
        $this->request->allowMethod(['post']);
        $entityIds = $this->parseEntityIds();
        $userId = $this->getCurrentUserId();
        $table = $this->fetchTable('Tickets');
        $historyTable = $this->fetchTable('TicketHistory');
        $successCount = 0;
        $errorCount = 0;
        foreach ($entityIds as $entityId) {
            try {
                $entity = $table->get($entityId);
                // Pre-delete audit row. ticket_history has FK to tickets and
                // will be removed by the same cascade; the row exists just
                // long enough to be captured by any audit export running in
                // parallel and to leave a trace in operation logs.
                $history = $historyTable->newEntity([
                    'ticket_id' => $entity->id,
                    'changed_by' => $userId,
                    'field_name' => 'deleted',
                    'old_value' => (string)$entity->status,
                    'new_value' => null,
                    'description' => 'Ticket eliminado en operación bulk',
                ], ['accessibleFields' => ['changed_by' => true]]);
                $historyTable->save($history);

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
            $this->Flash->success(__("{$successCount} Ticket(s) eliminado(s) correctamente."));
        }
        if ($errorCount > 0) {
            $this->Flash->error(__("{$errorCount} Ticket(s) no pudieron ser eliminados."));
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Parse the comma-separated id list submitted by the bulk form.
     *
     * Drops anything that doesn't represent a positive int, so a malformed
     * payload yields an empty batch instead of silently fetching ticket #0
     * and inflating the error counter.
     *
     * @return list<int>
     */
    private function parseEntityIds(): array
    {
        $raw = $this->request->getData('entity_ids') ?? $this->request->getData('ticket_ids') ?? '';

        $ids = [];
        foreach (explode(',', (string)$raw) as $candidate) {
            $id = (int)trim($candidate);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    // endregion
}
