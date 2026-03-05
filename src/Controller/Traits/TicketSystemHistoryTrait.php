<?php
declare(strict_types=1);

namespace App\Controller\Traits;

/**
 * TicketSystemHistoryTrait
 *
 * Entity history (JSON API) method.
 * Extracted from TicketSystemListingTrait for SRP compliance.
 */
trait TicketSystemHistoryTrait
{
    protected function historyEntity(string $entityType, int $id): void
    {
        $this->request->allowMethod(['get']);
        $this->viewBuilder()->setClassName('Json');
        try {
            $user = $this->Authentication->getIdentity();
            if (!$user) {
                $this->set('error', 'No autenticado');
                $this->viewBuilder()->setOption('serialize', ['error']);
                $this->response = $this->response->withStatus(401);
                return;
            }
            $components = $this->getEntityComponents($entityType);
            $tableName = $components['tableName'];
            $foreignKey = $components['foreignKey'];
            $entity = $this->fetchTable($tableName)->get($id);
            $userRole = $user->get('role');
            $userId = $user->get('id');
            if ($userRole === 'requester' && $entity->requester_id !== $userId) {
                $this->set('error', 'No tienes permiso para ver este historial');
                $this->viewBuilder()->setOption('serialize', ['error']);
                $this->response = $this->response->withStatus(403);
                return;
            }
            $historyTable = $this->getHistoryTable($entityType);
            $history = $historyTable
                ->find()
                ->where([$foreignKey => $id])
                ->contain(['Users'])
                ->order([$historyTable->getAlias() . '.created' => 'DESC'])
                ->all();
            $formattedHistory = [];
            foreach ($history as $entry) {
                $userData = null;
                if ($entry->user) {
                    $userData = [
                        'id' => $entry->user->id,
                        'name' => $entry->user->name,
                    ];
                } else {
                    $userData = [
                        'id' => null,
                        'name' => 'Sistema',
                    ];
                }
                $formattedHistory[] = [
                    'id' => $entry->id,
                    'field_name' => $entry->field_name,
                    'old_value' => $entry->old_value,
                    'new_value' => $entry->new_value,
                    'description' => $entry->description,
                    'created' => $entry->created->format('Y-m-d H:i:s'),
                    'user' => $userData,
                ];
            }
            $this->set('history', $formattedHistory);
            $this->viewBuilder()->setOption('serialize', ['history']);
        } catch (\Cake\Datasource\Exception\RecordNotFoundException $e) {
            \Cake\Log\Log::warning(ucfirst($entityType) . ' not found for history: ' . $id);
            $this->set('error', ucfirst($entityType) . ' no encontrado');
            $this->viewBuilder()->setOption('serialize', ['error']);
            $this->response = $this->response->withStatus(404);
        } catch (\Exception $e) {
            \Cake\Log\Log::error('Error loading ' . $entityType . ' history: ' . $e->getMessage(), [
                $entityType . '_id' => $id,
                'exception' => $e,
            ]);
            $this->set('error', 'Error al cargar el historial');
            $this->viewBuilder()->setOption('serialize', ['error']);
            $this->response = $this->response->withStatus(500);
        }
    }
}
