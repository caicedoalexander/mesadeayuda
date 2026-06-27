<?php
declare(strict_types=1);

namespace App\Controller\Trait;

use App\Constants\CacheConstants;
use App\Constants\RoleConstants;
use App\Model\Entity\Ticket;
use Cake\Cache\Cache;
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
    public function view(?string $id = null): ?Response
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
     * @return \Cake\Http\Response|null Reservado para ramas de permisos futuras.
     */
    private function _checkTicketViewPermission(Ticket $ticket)
    {
        // Antes filtrábamos requester. Como 'external' nunca inicia sesión,
        // la rama es código muerto. Cualquier staff autenticado ve todo.
        return null;
    }

    /**
     * @param int $id Ticket id
     * @param array $config View configuration overrides
     */
    protected function viewTicket(int $id, array $config = []): ?Response
    {
        $variableName = $this->getSingleEntityVariable();
        $contain = $config['contain'] ?? $this->getDefaultViewContain($config['lazyLoadHistory'] ?? false);
        $entity = $this->fetchTable('Tickets')->get($id, compact('contain'));
        if (isset($config['permissionCheck']) && is_callable($config['permissionCheck'])) {
            $permissionResult = $config['permissionCheck']($entity);
            if ($permissionResult !== null) {
                return $permissionResult;
            }
        }
        $agentsRoleFilter = $config['agentsRoleFilter'] ?? $this->getDefaultAgentsRoleFilter();
        $agents = $this->loadAgentsForRoles($agentsRoleFilter);
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
        $viewVars = array_merge($viewVars, [
            'statuses' => $selectableStatuses,
            'priorities' => $this->getPriorityConfig(),
            'isLocked' => $entity->isLocked(),
            'isAssignmentDisabled' => $this->authService->isAssignmentDisabled($user),
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
            'TicketComments' => [
                'Users',
                'sort' => ['TicketComments.created' => 'ASC', 'TicketComments.id' => 'ASC'],
            ],
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
        return [RoleConstants::ROLE_ADMIN, RoleConstants::ROLE_ASESOR_TIC];
    }

    /**
     * @return string
     */
    private function getSingleEntityVariable(): string
    {
        return 'ticket';
    }

    /**
     * Load the active-agents list for the given role filter, cached so a hot
     * ticket-view page doesn't run the same Users query on every request.
     *
     * Cache is invalidated when settings change (CACHE_CONFIG bucket is the
     * shared settings cache flushed on user create/deactivate paths that
     * touch SystemSettings); a 5-minute TTL bounds staleness for direct
     * Users mutations that don't currently bust this slot.
     *
     * @param list<string> $roles Roles to include (e.g. admin, agent)
     * @return array<int, string>
     */
    private function loadAgentsForRoles(array $roles): array
    {
        sort($roles);
        $cacheKey = 'agents_list_' . md5(implode(',', $roles));

        $agents = Cache::read($cacheKey, CacheConstants::CACHE_CONFIG);
        if (is_array($agents)) {
            return $agents;
        }

        $agents = $this->fetchTable('Users')
            ->find('list')
            ->where(['role IN' => $roles, 'is_active' => true])
            ->toArray();

        Cache::write($cacheKey, $agents, CacheConstants::CACHE_CONFIG);

        return $agents;
    }

    // endregion
}
