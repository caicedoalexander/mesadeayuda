<?php
declare(strict_types=1);

namespace App\Controller\Traits;

use App\Service\AuthorizationService;
use App\Utility\ValidationConstants;

/**
 * TicketSystemListingTrait
 *
 * Entity listing (index) method and filter/pagination helpers.
 * viewEntity and historyEntity moved to TicketSystemViewTrait and TicketSystemHistoryTrait.
 */
trait TicketSystemListingTrait
{
    protected function indexEntity(string $entityType, array $config = []): void
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
            'specialRedirects' => null,
        ];
        $config = array_merge($defaults, $config);
        $user = $this->Authentication->getIdentity();
        $userRole = $user ? $user->get('role') : null;
        if (is_callable($config['specialRedirects'])) {
            $redirect = $config['specialRedirects']($this->request, $user, $userRole);
            if ($redirect !== null) {
                return;
            }
        }
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
        [$table, , $entityName] = $this->getEntityComponents($entityType);
        $tableAlias = $table->getAlias();
        $entityVariable = $this->getEntityVariable($entityType);
        $queryOptions = [
            'view' => $view,
            'filters' => array_merge([
                'search' => $search,
                'status' => $filterStatus,
                'priority' => $filterPriority,
                'assignee_id' => $filterAssignee,
                'date_from' => $filterDateFrom,
                'date_to' => $filterDateTo,
            ], $additionalFilters),
            'user' => $user
        ];
        $query = $table->find('withFilters', $queryOptions);
        if ($config['contain'] !== null) {
            $query->contain($config['contain']);
        } else {
            $query->contain($this->getDefaultContain($entityType));
        }
        $validSortFields = $config['validSortFields'] ?? $this->getValidSortFields($entityType);
        $resolvedViews = ['resueltos', 'resueltas', 'completados'];
        $isResolvedView = in_array($view, $resolvedViews);
        if ($isResolvedView && $this->request->getQuery('sort') === null) {
            $query->orderBy([$tableAlias . '.resolved_at' => 'DESC']);
        } elseif (in_array($sortField, $validSortFields)) {
            $query->orderBy([$tableAlias . '.' . $sortField => strtoupper($sortDirection)]);
        } else {
            $query->orderBy([$tableAlias . '.' . $config['defaultSort'] => 'DESC']);
        }
        $this->applyRoleBasedFilters($query, $entityType, $user, $userRole, $tableAlias);
        if (is_callable($config['beforeQuery'])) {
            $config['beforeQuery']($query, $user, $userRole);
        }
        $entities = $this->paginate($query, [
            'limit' => $config['paginationLimit'],
        ]);
        $filterData = $this->getFilterDataForView($entityType, $config);
        $filters = compact(
            'search',
            'filterStatus',
            'filterPriority',
            'filterAssignee',
            'filterDateFrom',
            'filterDateTo',
            'sortField',
            'sortDirection'
        );
        foreach ($config['filterParams'] as $paramName => $queryKey) {
            $filterVarName = 'filter' . ucfirst($paramName);
            $filters[$filterVarName] = $this->request->getQuery($queryKey);
        }
        $authService = new AuthorizationService();
        $isAssignmentDisabled = $authService->isAssignmentDisabled($entityType, $user);

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

    private function applyRoleBasedFilters($query, string $entityType, $user, ?string $userRole, string $tableAlias): void
    {
        if (!$user || !$userRole) {
            return;
        }
        $userId = $user->get('id');
        if ($userRole === 'requester' && $entityType === 'ticket') {
            $query->where([$tableAlias . '.requester_id' => $userId]);
        }
    }

    private function getDefaultContain(string $entityType): array
    {
        return match ($entityType) {
            'ticket' => ['Requesters' => ['Organizations'], 'Assignees'],
            'pqrs' => ['Assignees'],
            'compra' => ['Requesters', 'Assignees'],
            default => [],
        };
    }

    private function getValidSortFields(string $entityType): array
    {
        $common = ['created', 'modified', 'status', 'priority', 'subject'];
        return match ($entityType) {
            'ticket' => array_merge($common, ['ticket_number']),
            'pqrs' => array_merge($common, ['pqrs_number', 'type']),
            'compra' => array_merge($common, ['compra_number']),
            default => $common,
        };
    }

    private function getEntityVariable(string $entityType): string
    {
        return match ($entityType) {
            'ticket' => 'tickets',
            'pqrs' => 'pqrs',
            'compra' => 'compras',
            default => $entityType . 's',
        };
    }

    private function getFilterDataForView(string $entityType, array $config): array
    {
        $data = [];
        $usersRoleFilter = $config['usersRoleFilter'] ?? $this->getDefaultUsersRoleFilter($entityType);
        if ($usersRoleFilter !== null) {
            $usersVarName = $this->getUsersVariableName($entityType);
            $data[$usersVarName] = $this->fetchTable('Users')
                ->find('list')
                ->where(['role IN' => $usersRoleFilter, 'is_active' => true])
                ->toArray();
        }
        $data['priorities'] = [
            'baja' => 'Baja',
            'media' => 'Media',
            'alta' => 'Alta',
            'urgente' => 'Urgente',
        ];
        $data['statuses'] = $this->getStatusesForEntity($entityType);
        if ($entityType === 'ticket') {
            $data['organizations'] = $this->fetchTable('Organizations')->find('list')->toArray();
            $data['tags'] = $this->fetchTable('Tags')->find()->toArray();
        } elseif ($entityType === 'pqrs') {
            $data['types'] = [
                'peticion' => 'Petición',
                'queja' => 'Queja',
                'reclamo' => 'Reclamo',
                'sugerencia' => 'Sugerencia',
            ];
        }
        return $data;
    }

    private function getDefaultUsersRoleFilter(string $entityType): ?array
    {
        return match ($entityType) {
            'ticket' => [ValidationConstants::ROLE_ADMIN, ValidationConstants::ROLE_AGENT],
            'pqrs' => [ValidationConstants::ROLE_SERVICIO_CLIENTE],
            'compra' => [ValidationConstants::ROLE_COMPRAS],
            default => null,
        };
    }

    private function getUsersVariableName(string $entityType): string
    {
        return match ($entityType) {
            'ticket' => 'agents',
            'pqrs' => 'users',
            'compra' => 'comprasUsers',
            default => 'users',
        };
    }

    private function getStatusesForEntity(string $entityType): array
    {
        return match ($entityType) {
            'ticket' => [
                'nuevo' => 'Nuevo',
                'abierto' => 'Abierto',
                'pendiente' => 'Pendiente',
                'resuelto' => 'Resuelto',
                'cerrado' => 'Cerrado',
            ],
            'pqrs' => [
                'nuevo' => 'Nuevo',
                'en_revision' => 'En Revisión',
                'en_proceso' => 'En Proceso',
                'resuelto' => 'Resuelto',
                'cerrado' => 'Cerrado',
            ],
            'compra' => [
                'nuevo' => 'Nuevo',
                'en_revision' => 'En Revisión',
                'aprobado' => 'Aprobado',
                'en_proceso' => 'En Proceso',
                'completado' => 'Completado',
                'rechazado' => 'Rechazado',
            ],
            default => [],
        };
    }
}
