<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\CacheConstants;
use App\Constants\RoleConstants;
use App\Model\Entity\Ticket;
use App\Service\AuthorizationService;
use App\Service\TicketService;
use App\Service\Traits\GenericAttachmentTrait;
use Cake\Cache\Cache;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Event\EventInterface;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use Cake\Log\Log;
use Cake\ORM\Table;
use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * Tickets Controller
 *
 * @property \App\Model\Table\TicketsTable $Tickets
 */
class TicketsController extends AppController
{
    use GenericAttachmentTrait;

    private TicketService $ticketService;

    /**
     * beforeFilter callback - Redirect users based on their role
     *
     * REFACTORED: Uses AppController::redirectByRole() to eliminate duplicated code
     *
     * @param \Cake\Event\EventInterface<\Cake\Controller\Controller> $event Event
     * @return \Cake\Http\Response|null|void
     */
    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);

        // Unlock actions that use JS-submitted forms or AJAX
        $this->FormProtection->setConfig('unlockedActions', [
            'addComment', 'assign', 'changeStatus', 'changePriority',
            'addTag', 'removeTag', 'addFollower',
            'bulkAssign', 'bulkChangePriority', 'bulkAddTag', 'bulkDelete',
            'history',
        ]);

        // Allow admin, agent, and requester roles for Tickets module
        return $this->redirectByRole([RoleConstants::ROLE_ADMIN, RoleConstants::ROLE_AGENT, RoleConstants::ROLE_REQUESTER], 'tickets');
    }

    /**
     * Initialize
     *
     * REFACTORED: Uses ServiceInitializerTrait for clean service initialization
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        // Initialize all ticket system services using trait
        $this->initializeTicketSystemServices();
    }

    /**
     * Index method - List tickets with filters
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function index()
    {
        $this->indexEntity('ticket', [
            'filterParams' => [],
            'specialRedirects' => function ($request, $user, $userRole) {
                // Handle Gmail OAuth callback redirect
                $code = $request->getQuery('code');
                if ($code) {
                    $this->redirect([
                        'controller' => 'Settings',
                        'action' => 'gmailAuth',
                        'prefix' => 'Admin',
                        '?' => ['code' => $code],
                    ]);

                    return true; // Indicate redirect happened
                }

                return null; // No redirect
            },
        ]);
    }

    /**
     * View method - Show ticket detail
     *
     * @param string|null $id Ticket id.
     * @return \Cake\Http\Response|null|void Renders view
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function view(?string $id = null)
    {
        return $this->viewEntity('ticket', (int)$id, [
            'lazyLoadHistory' => true, // PERFORMANCE FIX: Load history via AJAX
            'permissionCheck' => function ($ticket) {
                return $this->_checkTicketViewPermission($ticket);
            },
            'beforeSet' => function ($ticket, $viewVars) {
                // Get all tags for selection
                $tags = $this->fetchTable('Tags')->find('list')->toArray();

                return array_merge($viewVars, compact('tags'));
            },
        ]);
    }

    /**
     * Check if current user has permission to view ticket
     *
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

        // Requester can only view their own tickets
        if ($userRole === 'requester' && $ticket->requester_id !== $userId) {
            $this->Flash->error('No tienes permiso para ver este ticket.');

            return $this->redirect(['action' => 'index']);
        }

        return null;
    }

    /**
     * Add comment to ticket
     *
     * @param string|null $id Ticket id
     * @return \Cake\Http\Response|null|void Redirects back to ticket view
     */
    public function addComment(?string $id = null)
    {
        return $this->addEntityComment('ticket', (int)$id);
    }

    /**
     * Assign ticket to agent
     *
     * @param string|null $id Ticket id
     * @return \Cake\Http\Response|null|void Redirects back
     */
    public function assign(?string $id = null)
    {
        return $this->assignEntity('ticket', (int)$id, $this->request->getData('assignee_id'));
    }

    /**
     * Change ticket status
     *
     * @param string|null $id Ticket id
     * @return \Cake\Http\Response|null|void Redirects back
     */
    public function changeStatus(?string $id = null)
    {
        return $this->changeEntityStatus('ticket', (int)$id, $this->request->getData('status'));
    }

    /**
     * Change ticket priority
     *
     * @param string|null $id Ticket id
     * @return \Cake\Http\Response|null|void Redirects back
     */
    public function changePriority(?string $id = null)
    {
        return $this->changeEntityPriority('ticket', (int)$id, $this->request->getData('priority'));
    }

    /**
     * Add tag to ticket
     *
     * @param string|null $id Ticket id
     * @return \Cake\Http\Response|null|void Redirects back
     */
    public function addTag(?string $id = null)
    {
        $this->request->allowMethod(['post']);

        $tagId = (int)$this->request->getData('tag_id');
        $result = $this->ticketService->addTag((int)$id, $tagId);

        $this->Flash->{$result['success'] ? 'success' : ($result['message'] === 'Esta etiqueta ya está agregada.' ? 'warning' : 'error')}($result['message']);

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Remove tag from ticket
     *
     * @param string|null $id Ticket id
     * @param string|null $tagId Tag id
     * @return \Cake\Http\Response|null|void Redirects back
     */
    public function removeTag(?string $id = null, ?string $tagId = null)
    {
        $this->request->allowMethod(['post', 'delete']);

        $result = $this->ticketService->removeTag((int)$id, (int)$tagId);

        $this->Flash->{$result['success'] ? 'success' : 'error'}($result['message']);

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Add follower to ticket
     *
     * @param string|null $id Ticket id
     * @return \Cake\Http\Response|null|void Redirects back
     */
    public function addFollower(?string $id = null)
    {
        $this->request->allowMethod(['post']);

        $userId = (int)$this->request->getData('user_id');
        $result = $this->ticketService->addFollower((int)$id, $userId);

        $this->Flash->{$result['success'] ? 'success' : ($result['message'] === 'Este usuario ya está siguiendo el ticket.' ? 'warning' : 'error')}($result['message']);

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Bulk assign tickets to an agent
     *
     * @return \Cake\Http\Response|null|void Redirects on success
     */
    public function bulkAssign()
    {
        return $this->bulkAssignEntity('ticket');
    }

    /**
     * Bulk change priority of tickets
     *
     * @return \Cake\Http\Response|null|void Redirects on success
     */
    public function bulkChangePriority()
    {
        return $this->bulkChangeEntityPriority('ticket');
    }

    /**
     * Bulk add tag to tickets
     *
     * @return \Cake\Http\Response|null|void Redirects on success
     */
    public function bulkAddTag()
    {
        return $this->bulkAddTagEntity('ticket');
    }

    /**
     * Bulk delete tickets
     *
     * @return \Cake\Http\Response|null|void Redirects on success
     */
    public function bulkDelete()
    {
        return $this->bulkDeleteEntity('ticket');
    }

    /**
     * Download ticket attachment
     *
     * @param string|null $id Attachment id
     * @return \Cake\Http\Response File download response
     */
    public function downloadAttachment(?string $id = null)
    {
        return $this->downloadEntityAttachment('ticket', (int)$id);
    }

    /**
     * AJAX endpoint for lazy loading ticket history
     * PERFORMANCE FIX: Only loads when history tab is opened
     *
     * @param string|null $id Ticket id
     * @return void JSON response
     */
    public function history(?string $id = null)
    {
        $this->historyEntity('ticket', (int)$id);
    }

    // region: ServiceInitializer

    /**
     * Initialize services based on provided configuration
     *
     * @param array<string, class-string> $serviceMap Map of property names to class names
     * @return void
     */
    protected function initializeServices(array $serviceMap): void
    {
        $systemConfig = Cache::read(CacheConstants::CACHE_SETTINGS, CacheConstants::CACHE_CONFIG);

        foreach ($serviceMap as $propertyName => $serviceClass) {
            $this->{$propertyName} = new $serviceClass($systemConfig);
        }
    }

    /**
     * Initialize standard ticket system services
     *
     * @return void
     */
    protected function initializeTicketSystemServices(): void
    {
        $this->initializeServices([
            'ticketService' => TicketService::class,
        ]);
    }

    // endregion

    // region: ViewDataNormalizer

    /**
     * Status display configuration with icons, colors and labels
     * for use in status badges, dropdowns and filters.
     */
    protected function getStatusConfig(): array
    {
        return [
            'nuevo' => ['icon' => 'bi-circle-fill', 'color' => '#ffc107', 'label' => 'Nuevo'],
            'abierto' => ['icon' => 'bi-circle-fill', 'color' => '#dc3545', 'label' => 'Abierto'],
            'pendiente' => ['icon' => 'bi-circle-fill', 'color' => '#0d6efd', 'label' => 'Pendiente'],
            'resuelto' => ['icon' => 'bi-circle-fill', 'color' => '#198754', 'label' => 'Resuelto'],
            'convertido' => ['icon' => 'bi-arrow-left-right', 'color' => '#6c757d', 'label' => 'Convertido'],
        ];
    }

    /**
     * Priority options for dropdowns and display.
     */
    protected function getPriorityConfig(): array
    {
        return [
            'baja' => 'Baja',
            'media' => 'Media',
            'alta' => 'Alta',
            'urgente' => 'Urgente',
        ];
    }

    /**
     * Status keys considered "resolved".
     */
    protected function getResolvedStatuses(): array
    {
        return ['resuelto', 'convertido'];
    }

    /**
     * Whether a ticket is locked (in a final/closed status).
     */
    protected function isEntityLocked($entity): bool
    {
        return in_array($entity->status, $this->getResolvedStatuses(), true);
    }

    // endregion

    // region: TicketSystemController helpers

    /**
     * Get entity components (table, service, display name) based on type
     *
     * @param string $entityType 'ticket'
     * @return array Associative array with keys: table, service, displayName, tableName, foreignKey
     */
    private function getEntityComponents(string $entityType): array
    {
        $components = match ($entityType) {
            'ticket' => [
                'table' => $this->Tickets ?? $this->fetchTable('Tickets'),
                'service' => $this->ticketService ?? null,
                'displayName' => 'Ticket',
                'tableName' => 'Tickets',
                'foreignKey' => 'ticket_id',
            ],
            default => throw new InvalidArgumentException("Invalid entity type: {$entityType}"),
        };

        return array_merge($components, [
            0 => $components['table'],
            1 => $components['service'],
            2 => $components['displayName'],
        ]);
    }

    /**
     * Get history table based on entity type
     *
     * @param string $entityType 'ticket'
     * @return \Cake\ORM\Table History table instance
     */
    private function getHistoryTable(string $entityType): Table
    {
        return match ($entityType) {
            'ticket' => $this->fetchTable('TicketHistory'),
            default => throw new InvalidArgumentException("Invalid entity type: {$entityType}"),
        };
    }

    // endregion

    // region: Listing

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
            'sortDirection',
        );
        foreach ($config['filterParams'] as $paramName => $queryKey) {
            $filterVarName = 'filter' . ucfirst($paramName);
            $filters[$filterVarName] = $this->request->getQuery($queryKey);
        }
        $authService = new AuthorizationService();
        $isAssignmentDisabled = $authService->isAssignmentDisabled($user);

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
            'ticket' => ['Requesters', 'Assignees'],
            default => [],
        };
    }

    private function getValidSortFields(string $entityType): array
    {
        $common = ['created', 'modified', 'status', 'priority', 'subject'];

        return match ($entityType) {
            'ticket' => array_merge($common, ['ticket_number']),
            default => $common,
        };
    }

    private function getEntityVariable(string $entityType): string
    {
        return match ($entityType) {
            'ticket' => 'tickets',
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
            $data['tags'] = $this->fetchTable('Tags')->find()->toArray();
        }

        return $data;
    }

    private function getDefaultUsersRoleFilter(string $entityType): ?array
    {
        return match ($entityType) {
            'ticket' => [RoleConstants::ROLE_ADMIN, RoleConstants::ROLE_AGENT],
            default => null,
        };
    }

    private function getUsersVariableName(string $entityType): string
    {
        return match ($entityType) {
            'ticket' => 'agents',
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
            default => [],
        };
    }

    // endregion

    // region: View

    protected function viewEntity(string $entityType, int $id, array $config = []): ?Response
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
        $allStatuses = $this->getStatusConfig();
        $selectableStatuses = array_filter($allStatuses, function ($key) {
            return $key !== 'convertido';
        }, ARRAY_FILTER_USE_KEY);
        $user = $this->Authentication->getIdentity();
        $authService = new AuthorizationService();
        $viewVars = array_merge($viewVars, [
            'statuses' => $selectableStatuses,
            'priorities' => $this->getPriorityConfig(),
            'resolvedStatuses' => $this->getResolvedStatuses(),
            'isLocked' => $this->isEntityLocked($entity),
            'isAssignmentDisabled' => $authService->isAssignmentDisabled($user),
        ]);
        $this->set($viewVars);

        return null;
    }

    private function getDefaultViewContain(string $entityType, bool $lazyLoadHistory = false): array
    {
        $contain = match ($entityType) {
            'ticket' => [
                'Requesters',
                'Assignees',
                'TicketComments' => ['Users'],
                'Attachments',
                'Tags',
                'TicketFollowers' => ['Users'],
            ],
            default => [],
        };
        if (!$lazyLoadHistory) {
            $historyAssoc = match ($entityType) {
                'ticket' => 'TicketHistory',
                default => null,
            };
            if ($historyAssoc) {
                $contain[$historyAssoc] = [
                    'Users',
                    'sort' => [$historyAssoc . '.created' => 'DESC'],
                ];
            }
        }

        return $contain;
    }

    private function getDefaultAgentsRoleFilter(string $entityType): array
    {
        return match ($entityType) {
            'ticket' => [RoleConstants::ROLE_ADMIN, RoleConstants::ROLE_AGENT],
            default => [RoleConstants::ROLE_ADMIN, RoleConstants::ROLE_AGENT],
        };
    }

    private function getSingleEntityVariable(string $entityType): string
    {
        return match ($entityType) {
            'ticket' => 'ticket',
            default => $entityType,
        };
    }

    // endregion

    // region: Actions

    protected function assignEntity(
        string $entityType,
        int $entityId,
        $assigneeId,
        string $redirectAction = 'index',
    ): Response {
        $this->request->allowMethod(['post', 'put']);
        $assigneeId = $this->normalizeAssigneeId($assigneeId);
        $userId = $this->getCurrentUserId();

        $components = $this->getEntityComponents($entityType);
        $entity = $components['table']->get($entityId);
        $entityName = $components['displayName'];

        if ($this->isEntityLocked($entity)) {
            $this->Flash->error(__("No se puede modificar una {$entityName} en estado final."));

            return $this->redirect(['action' => $redirectAction]);
        }

        $result = $components['service']->assign($entity, $assigneeId, $userId);
        if ($result) {
            $this->Flash->success(__("{$entityName} asignada correctamente."));
        } else {
            $this->Flash->error(__("No se pudo asignar la {$entityName}."));
        }

        return $this->redirect(['action' => $redirectAction]);
    }

    protected function changeEntityStatus(
        string $entityType,
        int $entityId,
        string $newStatus,
        string $redirectAction = 'index',
    ): Response {
        $this->request->allowMethod(['post', 'put']);
        $userId = $this->getCurrentUserId();

        $components = $this->getEntityComponents($entityType);
        $entity = $components['table']->get($entityId);
        $entityName = $components['displayName'];

        if ($this->isEntityLocked($entity)) {
            $this->Flash->error(__("No se puede modificar una {$entityName} en estado final."));

            return $this->redirect(['action' => $redirectAction]);
        }

        $result = $components['service']->changeStatus($entity, $newStatus, $userId);
        if ($result['success']) {
            $this->Flash->success($result['message'] ?? __("Estado de {$entityName} actualizado."));
        } else {
            $this->Flash->error($result['message'] ?? __("Error al cambiar el estado de {$entityName}."));
        }

        return $this->redirect(['action' => $redirectAction]);
    }

    protected function changeEntityPriority(
        string $entityType,
        int $entityId,
        string $newPriority,
        string $redirectAction = 'index',
    ): Response {
        $this->request->allowMethod(['post', 'put']);
        $userId = $this->getCurrentUserId();

        $components = $this->getEntityComponents($entityType);
        $entity = $components['table']->get($entityId);
        $entityName = $components['displayName'];

        if ($this->isEntityLocked($entity)) {
            $this->Flash->error(__("No se puede modificar una {$entityName} en estado final."));

            return $this->redirect(['action' => $redirectAction]);
        }

        $result = $components['service']->changePriority($entity, $newPriority, $userId);
        if ($result) {
            $this->Flash->success(__("Prioridad de {$entityName} actualizada."));
        } else {
            $this->Flash->error(__("Error al cambiar la prioridad de {$entityName}."));
        }

        return $this->redirect(['action' => $redirectAction]);
    }

    protected function addEntityComment(string $entityType, int $entityId): Response
    {
        $this->request->allowMethod(['post']);
        $userId = $this->getCurrentUserId();

        $components = $this->getEntityComponents($entityType);
        $entityName = $components['displayName'];
        $service = $components['service'];

        $data = $this->request->getData();
        $files = $this->request->getUploadedFiles();

        $result = $service->handleResponse($entityId, $userId, $data, $files);

        if ($result['success']) {
            $this->Flash->success($result['message'] ?? __("Comentario agregado a {$entityName}."));
        } else {
            $this->Flash->error($result['message'] ?? __("Error al agregar comentario a {$entityName}."));
        }

        return $this->redirect(['action' => 'view', $entityId]);
    }

    protected function downloadEntityAttachment(string $entityType, int $attachmentId): Response
    {
        $attachmentsTable = $this->fetchTable('Attachments');
        $attachment = $attachmentsTable->get($attachmentId);
        $filePath = $this->getFullPath($attachment);

        if (!$filePath || !file_exists($filePath)) {
            throw new NotFoundException('Archivo no encontrado.');
        }

        return $this->response
            ->withFile($filePath, ['download' => true, 'name' => $attachment->original_filename])
            ->withType($attachment->mime_type ?? 'application/octet-stream');
    }

    protected function getCurrentUserId(): int
    {
        $user = $this->Authentication->getIdentity();
        if (!$user) {
            throw new RuntimeException('No authenticated user');
        }

        return (int)$user->get('id');
    }

    protected function normalizeAssigneeId($value): ?int
    {
        if ($value === '' || $value === null || $value === '0' || $value === 0) {
            return null;
        }

        return (int)$value;
    }

    protected function handleServiceResult(array $result, string $redirectUrl): Response
    {
        if (!empty($result['success'])) {
            $this->Flash->success($result['message'] ?? 'Operación exitosa.');
        } else {
            $this->Flash->error($result['message'] ?? 'Error en la operación.');
        }

        return $this->redirect($redirectUrl);
    }

    // endregion

    // region: Bulk

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
            } catch (Exception $e) {
                $errorCount++;
                Log::error("Error in bulk assign {$entityType} {$entityId}: " . $e->getMessage());
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
                        "Prioridad cambiada de {$oldPriority} a {$newPriority}",
                    );
                    $successCount++;
                } else {
                    $errorCount++;
                }
            } catch (Exception $e) {
                $errorCount++;
                Log::error("Error in bulk priority change {$entityType} {$entityId}: " . $e->getMessage());
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
        $tagId = (int)$this->request->getData('tag_id');
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
                Log::error("Error in bulk tag add {$entityType} {$entityId}: " . $e->getMessage());
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
            } catch (Exception $e) {
                $errorCount++;
                Log::error("Error in bulk delete {$entityType} {$entityId}: " . $e->getMessage());
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
        return 'TicketTags';
    }

    // endregion

    // region: History

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
        } catch (RecordNotFoundException $e) {
            Log::warning(ucfirst($entityType) . ' not found for history: ' . $id);
            $this->set('error', ucfirst($entityType) . ' no encontrado');
            $this->viewBuilder()->setOption('serialize', ['error']);
            $this->response = $this->response->withStatus(404);
        } catch (Exception $e) {
            Log::error('Error loading ' . $entityType . ' history: ' . $e->getMessage(), [
                $entityType . '_id' => $id,
                'exception' => $e,
            ]);
            $this->set('error', 'Error al cargar el historial');
            $this->viewBuilder()->setOption('serialize', ['error']);
            $this->response = $this->response->withStatus(500);
        }
    }

    // endregion
}
