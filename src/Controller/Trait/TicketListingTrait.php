<?php
declare(strict_types=1);

namespace App\Controller\Trait;

use App\Constants\RoleConstants;
use App\Constants\TicketConstants;

/**
 * Listing region for TicketsController: index action and filter helpers.
 */
trait TicketListingTrait
{
    // region: Listing

    /**
     * Index method - List tickets with filters
     */
    public function index()
    {
        $this->indexTicketList(['filterParams' => []]);
    }

    /**
     * @param array $config Listing configuration overrides
     */
    protected function indexTicketList(array $config = []): void
    {
        $defaults = [
            'defaultView' => 'todos_sin_resolver',
            'defaultSort' => 'created',
            'defaultDirection' => 'desc',
            'paginationLimit' => 10,
            'contain' => null,
            'validSortFields' => null,
            'filterParams' => [],
            'usersRoleFilter' => null,
            'additionalViewVars' => [],
            'beforeQuery' => null,
        ];
        $config = array_merge($defaults, $config);
        $user = $this->Authentication->getIdentity();
        $userRole = $user ? $user->get('role') : null;
        $view = $this->request->getQuery('view', $config['defaultView']);
        $search = $this->request->getQuery('search');
        $filterStatus = $this->request->getQuery('filter_status');
        $filterPriority = $this->request->getQuery('filter_priority');
        $filterAssignee = $this->request->getQuery('filter_assignee');
        $filterDateFrom = $this->request->getQuery('filter_date_from');
        $filterDateTo = $this->request->getQuery('filter_date_to');
        $sortField = $this->request->getQuery('sort', $config['defaultSort']);
        $sortDirection = $this->request->getQuery('direction', $config['defaultDirection']);
        $additionalFilters = [];
        foreach ($config['filterParams'] as $paramName => $queryKey) {
            $additionalFilters[$paramName] = $this->request->getQuery($queryKey);
        }
        [$table, , ] = $this->getEntityComponents();
        $tableAlias = $table->getAlias();
        $entityVariable = $this->getEntityVariable();
        $filters = array_merge([
            'search' => $search,
            'status' => $filterStatus,
            'priority' => $filterPriority,
            'assignee_id' => $filterAssignee,
            'date_from' => $filterDateFrom,
            'date_to' => $filterDateTo,
        ], $additionalFilters);
        $query = $table->find('withFilters', view: $view, filters: $filters, user: $user);
        if ($config['contain'] !== null) {
            $query->contain($config['contain']);
        } else {
            $query->contain($this->getDefaultContain());
        }
        $commentsCountSub = $this->fetchTable('TicketComments')
            ->find()
            ->select(['c' => $query->func()->count('*')])
            ->where([
                "TicketComments.ticket_id = {$tableAlias}.id",
                'TicketComments.is_system_comment' => false,
            ]);
        $query->select(['comments_count' => $commentsCountSub])->enableAutoFields(true);
        $validSortFields = $config['validSortFields'] ?? $this->getValidSortFields();
        $resolvedViews = ['resueltos', 'resueltas', 'completados'];
        $isResolvedView = in_array($view, $resolvedViews);
        if ($isResolvedView && $this->request->getQuery('sort') === null) {
            $query->orderBy([$tableAlias . '.resolved_at' => 'DESC']);
        } elseif (in_array($sortField, $validSortFields)) {
            $query->orderBy([$tableAlias . '.' . $sortField => strtoupper($sortDirection)]);
        } else {
            $query->orderBy([$tableAlias . '.' . $config['defaultSort'] => 'DESC']);
        }
        $this->applyRoleBasedFilters($query, $user, $userRole, $tableAlias);
        if (is_callable($config['beforeQuery'])) {
            $config['beforeQuery']($query, $user, $userRole);
        }
        $entities = $this->paginate($query, [
            'limit' => $config['paginationLimit'],
        ]);
        $filterData = $this->getFilterDataForView($config);
        $filters = compact(
            'search',
            'filterStatus',
            'filterPriority',
            'filterAssignee',
            'filterDateFrom',
            'filterDateTo',
            'sortField',
            'sortDirection',
        );
        foreach ($config['filterParams'] as $paramName => $queryKey) {
            $filterVarName = 'filter' . ucfirst($paramName);
            $filters[$filterVarName] = $this->request->getQuery($queryKey);
        }
        $isAssignmentDisabled = $this->authService->isAssignmentDisabled($user);

        $viewVars = [
            $entityVariable => $entities,
            'view' => $view,
            'filters' => $filters,
            'isAssignmentDisabled' => $isAssignmentDisabled,
        ];
        $viewVars = array_merge($viewVars, $filterData);
        $viewVars = array_merge($viewVars, $config['additionalViewVars']);
        $this->set($viewVars);
    }

    private function applyRoleBasedFilters($query, $user, ?string $userRole, string $tableAlias): void
    {
        // Antes filtrábamos por requester_id cuando el rol era 'requester'.
        // 'external' no inicia sesión, así que esa rama es código muerto.
        // El método se conserva como punto de extensión para filtros por rol
        // futuros (p.ej. organización).
    }

    /**
     * @return array
     */
    private function getDefaultContain(): array
    {
        return ['Requesters', 'Assignees', 'Tags'];
    }

    /**
     * @return array
     */
    private function getValidSortFields(): array
    {
        return ['created', 'modified', 'status', 'priority', 'subject', 'ticket_number'];
    }

    /**
     * @return string
     */
    private function getEntityVariable(): string
    {
        return 'tickets';
    }

    /**
     * @param array $config Listing config
     * @return array
     */
    private function getFilterDataForView(array $config): array
    {
        $data = [];
        $usersRoleFilter = $config['usersRoleFilter'] ?? $this->getDefaultUsersRoleFilter();
        if ($usersRoleFilter !== null) {
            $usersVarName = $this->getUsersVariableName();
            $data[$usersVarName] = $this->fetchTable('Users')
                ->find('list')
                ->where(['role IN' => $usersRoleFilter, 'is_active' => true])
                ->toArray();
        }
        $data['priorities'] = TicketConstants::PRIORITY_LABELS;
        $data['statuses'] = $this->getStatusesForEntity();
        $data['tags'] = $this->fetchTable('Tags')->find()->toArray();

        return $data;
    }

    /**
     * @return array
     */
    private function getDefaultUsersRoleFilter(): array
    {
        return [RoleConstants::ROLE_ADMIN, RoleConstants::ROLE_ASESOR_TIC];
    }

    /**
     * @return string
     */
    private function getUsersVariableName(): string
    {
        return 'agents';
    }

    /**
     * @return array
     */
    private function getStatusesForEntity(): array
    {
        return TicketConstants::STATUS_LABELS;
    }

    // endregion
}
