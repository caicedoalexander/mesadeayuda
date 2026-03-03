<?php
declare(strict_types=1);

namespace App\Controller\Traits;

use App\Utility\ValidationConstants;

/**
 * TicketSystemListingTrait
 *
 * Listing, viewing, and history methods for entity management.
 * Extracted from TicketSystemControllerTrait for SRP compliance.
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
        $viewVars = [
            $entityVariable => $entities,
            'view' => $view,
            'filters' => $filters,
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

    private function getSingleEntityVariable(string $entityType): string
    {
        return match ($entityType) {
            'ticket' => 'ticket',
            'pqrs' => 'pqrs',
            'compra' => 'compra',
            default => $entityType,
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

    protected function viewEntity(string $entityType, int $id, array $config = []): ?\Cake\Http\Response
    {
        $components = $this->getEntityComponents($entityType);
        $tableName = $components['tableName'];
        $variableName = $this->getSingleEntityVariable($entityType);
        $contain = $config['contain'] ?? $this->getDefaultViewContain($entityType, $config['lazyLoadHistory'] ?? false);
        $entity = $this->fetchTable($tableName)->get($id, compact('contain'));
        if (isset($config['permissionCheck']) && is_callable($config['permissionCheck'])) {
            $permissionResult = $config['permissionCheck']($entity);
            if ($permissionResult !== null) {
                return $permissionResult;
            }
        }
        $agentsRoleFilter = $config['agentsRoleFilter'] ?? $this->getDefaultAgentsRoleFilter($entityType);
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
        $allStatuses = $this->getStatusConfig($entityType);
        $selectableStatuses = array_filter($allStatuses, function($key) {
            return $key !== 'convertido';
        }, ARRAY_FILTER_USE_KEY);
        $viewVars = array_merge($viewVars, [
            'entityType' => $entityType,
            'entityMetadata' => $this->getEntityMetadata($entityType, $entity),
            'statuses' => $selectableStatuses,
            'priorities' => $this->getPriorityConfig($entityType),
            'resolvedStatuses' => $this->getResolvedStatuses($entityType),
            'isLocked' => $this->isEntityLocked($entityType, $entity),
        ]);
        $this->set($viewVars);
        return null;
    }

    private function getDefaultViewContain(string $entityType, bool $lazyLoadHistory = false): array
    {
        $contain = match ($entityType) {
            'ticket' => [
                'Requesters' => ['Organizations'],
                'Assignees',
                'TicketComments' => ['Users'],
                'Attachments',
                'Tags',
                'TicketFollowers' => ['Users'],
            ],
            'pqrs' => [
                'Assignees',
                'PqrsComments' => [
                    'Users',
                    'PqrsAttachments',
                    'sort' => ['PqrsComments.created' => 'ASC']
                ],
                'PqrsAttachments',
            ],
            'compra' => [
                'Requesters',
                'Assignees',
                'ComprasComments' => ['Users'],
                'ComprasAttachments',
            ],
            default => [],
        };
        if (!$lazyLoadHistory) {
            $historyAssoc = match ($entityType) {
                'ticket' => 'TicketHistory',
                'pqrs' => 'PqrsHistory',
                'compra' => 'ComprasHistory',
                default => null,
            };
            if ($historyAssoc) {
                $contain[$historyAssoc] = [
                    'Users',
                    'sort' => [$historyAssoc . '.created' => 'DESC']
                ];
            }
        }
        return $contain;
    }

    private function getDefaultAgentsRoleFilter(string $entityType): array
    {
        return match ($entityType) {
            'ticket' => [ValidationConstants::ROLE_ADMIN, ValidationConstants::ROLE_AGENT],
            'pqrs' => [ValidationConstants::ROLE_SERVICIO_CLIENTE],
            'compra' => [ValidationConstants::ROLE_COMPRAS],
            default => [ValidationConstants::ROLE_ADMIN, ValidationConstants::ROLE_AGENT],
        };
    }

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
