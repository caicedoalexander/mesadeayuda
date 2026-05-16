<?php
declare(strict_types=1);

namespace App\Controller\Trait;

use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Log\Log;
use Exception;

/**
 * History region for TicketsController: lazy-loaded JSON history endpoint.
 */
trait TicketHistoryTrait
{
    // region: History

    /**
     * AJAX endpoint for lazy loading ticket history
     *
     * @param string|null $id Ticket id
     */
    public function history(?string $id = null)
    {
        $this->historyTicket((int)$id);
    }

    /**
     * @param int $id Ticket id
     */
    protected function historyTicket(int $id): void
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
            $foreignKey = 'ticket_id';
            $this->fetchTable('Tickets')->get($id);
            $historyTable = $this->getHistoryTable();
            $history = $historyTable
                ->find()
                ->where([$foreignKey => $id])
                ->contain(['Users'])
                ->orderBy([$historyTable->getAlias() . '.created' => 'DESC'])
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
        } catch (RecordNotFoundException $e) {
            Log::warning('Ticket not found for history: ' . $id);
            $this->set('error', 'Ticket no encontrado');
            $this->viewBuilder()->setOption('serialize', ['error']);
            $this->response = $this->response->withStatus(404);
        } catch (Exception $e) {
            Log::error('Error loading ticket history: ' . $e->getMessage(), [
                'ticket_id' => $id,
                'exception' => $e,
            ]);
            $this->set('error', 'Error al cargar el historial');
            $this->viewBuilder()->setOption('serialize', ['error']);
            $this->response = $this->response->withStatus(500);
        }
    }

    // endregion
}
