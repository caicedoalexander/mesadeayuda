<?php
declare(strict_types=1);

namespace App\Controller\Trait;

use App\Constants\RoleConstants;
use App\Model\Entity\Ticket;
use App\Service\AuthorizationService;
use Cake\Http\Response;

/**
 * View region for TicketsController: ticket detail action and helpers.
 */
trait TicketViewTrait
{
    // region: View

    /**
     * View method - Show ticket detail
     *
     * @param string|null $id Ticket id.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function view(?string $id = null)
    {
        return $this->viewTicket((int)$id, [
            'lazyLoadHistory' => true,
            'permissionCheck' => function ($ticket) {
                return $this->_checkTicketViewPermission($ticket);
            },
            'beforeSet' => function ($ticket, $viewVars) {
                $tags = $this->fetchTable('Tags')->find('list')->toArray();

                return array_merge($viewVars, compact('tags'));
            },
        ]);
    }

    /**
     * @param \App\Model\Entity\Ticket $ticket Ticket entity
     * @return \Cake\Http\Response|null Redirect response if no permission, null if allowed
     */
    private function _checkTicketViewPermission(Ticket $ticket)
    {
        $user = $this->Authentication->getIdentity();
        if (!$user) {
            return null;
        }

        $userRole = $user->get('role');
        $userId = $user->get('id');

        if ($userRole === RoleConstants::ROLE_REQUESTER && $ticket->requester_id !== $userId) {
            $this->Flash->error('No tienes permiso para ver este ticket.');

            return $this->redirect(['action' => 'index']);
        }

        return null;
    }

    /**
     * @param int $id Ticket id
     * @param array $config View configuration overrides
     */
    protected function viewTicket(int $id, array $config = []): ?Response
    {
        $components = $this->getEntityComponents();
        $tableName = $components['tableName'];
        $variableName = $this->getSingleEntityVariable();
        $contain = $config['contain'] ?? $this->getDefaultViewContain($config['lazyLoadHistory'] ?? false);
        $entity = $this->fetchTable($tableName)->get($id, compact('contain'));
        if (isset($config['permissionCheck']) && is_callable($config['permissionCheck'])) {
            $permissionResult = $config['permissionCheck']($entity);
            if ($permissionResult !== null) {
                return $permissionResult;
            }
        }
        $agentsRoleFilter = $config['agentsRoleFilter'] ?? $this->getDefaultAgentsRoleFilter();
        $agents = $this->fetchTable('Users')
            ->find('list')
            ->where(['role IN' => $agentsRoleFilter, 'is_active' => true])
            ->toArray();
        $viewVars = [
            $variableName => $entity,
            'agents' => $agents,
        ];
        if (isset($config['additionalViewVars'])) {
            $viewVars = array_merge($viewVars, $config['additionalViewVars']);
        }
        if (isset($config['beforeSet']) && is_callable($config['beforeSet'])) {
            $viewVars = $config['beforeSet']($entity, $viewVars);
        }
        $selectableStatuses = $this->getStatusConfig();
        $user = $this->Authentication->getIdentity();
        $authService = new AuthorizationService();
        $viewVars = array_merge($viewVars, [
            'statuses' => $selectableStatuses,
            'priorities' => $this->getPriorityConfig(),
            'resolvedStatuses' => $this->getResolvedStatuses(),
            'isLocked' => $entity->isLocked(),
            'isAssignmentDisabled' => $authService->isAssignmentDisabled($user),
        ]);
        $this->set($viewVars);

        return null;
    }

    /**
     * @param bool $lazyLoadHistory Whether to skip loading history eagerly
     * @return array
     */
    private function getDefaultViewContain(bool $lazyLoadHistory = false): array
    {
        $contain = [
            'Requesters',
            'Assignees',
            'TicketComments' => ['Users'],
            'Attachments',
            'Tags',
            'TicketFollowers' => ['Users'],
        ];
        if (!$lazyLoadHistory) {
            $contain['TicketHistory'] = [
                'Users',
                'sort' => ['TicketHistory.created' => 'DESC'],
            ];
        }

        return $contain;
    }

    /**
     * @return array
     */
    private function getDefaultAgentsRoleFilter(): array
    {
        return [RoleConstants::ROLE_ADMIN, RoleConstants::ROLE_AGENT];
    }

    /**
     * @return string
     */
    private function getSingleEntityVariable(): string
    {
        return 'ticket';
    }

    // endregion
}
