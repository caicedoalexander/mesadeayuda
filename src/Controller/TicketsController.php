<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\CacheConstants;
use App\Constants\RoleConstants;
use App\Constants\TicketConstants;
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

        return $this->redirectByRole([RoleConstants::ROLE_ADMIN, RoleConstants::ROLE_AGENT, RoleConstants::ROLE_REQUESTER], 'tickets');
    }

    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        $this->initializeTicketSystemServices();
    }

    /**
     * Index method - List tickets with filters
     */
    public function index()
    {
        $this->indexTicketList([
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

                    return true;
                }

                return null;
            },
        ]);
    }

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
     * @param string|null $id Ticket id
     */
    public function addComment(?string $id = null)
    {
        return $this->addTicketComment((int)$id);
    }

    /**
     * @param string|null $id Ticket id
     */
    public function assign(?string $id = null)
    {
        return $this->assignTicket((int)$id, $this->request->getData('assignee_id'));
    }

    /**
     * @param string|null $id Ticket id
     */
    public function changeStatus(?string $id = null)
    {
        return $this->changeTicketStatus((int)$id, $this->request->getData('status'));
    }

    /**
     * @param string|null $id Ticket id
     */
    public function changePriority(?string $id = null)
    {
        return $this->changeTicketPriority((int)$id, $this->request->getData('priority'));
    }

    /**
     * @param string|null $id Ticket id
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
     * @param string|null $id Ticket id
     * @param string|null $tagId Tag id
     */
    public function removeTag(?string $id = null, ?string $tagId = null)
    {
        $this->request->allowMethod(['post', 'delete']);

        $result = $this->ticketService->removeTag((int)$id, (int)$tagId);

        $this->Flash->{$result['success'] ? 'success' : 'error'}($result['message']);

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * @param string|null $id Ticket id
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

    /**
     * @param string|null $id Attachment id
     */
    public function downloadAttachment(?string $id = null)
    {
        return $this->downloadTicketAttachment((int)$id);
    }

    /**
     * AJAX endpoint for lazy loading ticket history
     *
     * @param string|null $id Ticket id
     */
    public function history(?string $id = null)
    {
        $this->historyTicket((int)$id);
    }

    // region: ServiceInitializer

    /**
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
     * Status display configuration with icons, colors and labels.
     */
    protected function getStatusConfig(): array
    {
        $config = [];
        foreach (TicketConstants::STATUSES as $status) {
            $config[$status] = [
                'icon' => TicketConstants::STATUS_ICONS[$status] ?? 'bi-circle-fill',
                'color' => TicketConstants::STATUS_COLORS[$status] ?? '#6c757d',
                'label' => TicketConstants::STATUS_LABELS[$status] ?? ucfirst($status),
            ];
        }

        return $config;
    }

    /**
     * Priority options for dropdowns and display.
     */
    protected function getPriorityConfig(): array
    {
        return TicketConstants::PRIORITY_LABELS;
    }

    /**
     * Status keys considered "resolved".
     */
    protected function getResolvedStatuses(): array
    {
        return TicketConstants::RESOLVED_STATUSES;
    }

    protected function isEntityLocked($entity): bool
    {
        return in_array($entity->status, $this->getResolvedStatuses(), true);
    }

    // endregion

    // region: TicketSystemController helpers

    /**
     * @return array{table: \Cake\ORM\Table, service: ?\App\Service\TicketService, displayName: string, tableName: string, foreignKey: string, 0: \Cake\ORM\Table, 1: ?\App\Service\TicketService, 2: string}
     */
    private function getEntityComponents(): array
    {
        $components = [
            'table' => $this->Tickets ?? $this->fetchTable('Tickets'),
            'service' => $this->ticketService ?? null,
            'displayName' => 'Ticket',
            'tableName' => 'Tickets',
            'foreignKey' => 'ticket_id',
        ];

        return array_merge($components, [
            0 => $components['table'],
            1 => $components['service'],
            2 => $components['displayName'],
        ]);
    }

    /**
     * @return \Cake\ORM\Table
     */
    private function getHistoryTable(): Table
    {
        return $this->fetchTable('TicketHistory');
    }

    // endregion

    // region: Listing

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

    private function applyRoleBasedFilters($query, $user, ?string $userRole, string $tableAlias): void
    {
        if (!$user || !$userRole) {
            return;
        }
        if ($userRole === RoleConstants::ROLE_REQUESTER) {
            $query->where([$tableAlias . '.requester_id' => $user->get('id')]);
        }
    }

    /**
     * @return array
     */
    private function getDefaultContain(): array
    {
        return ['Requesters', 'Assignees'];
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
        return [RoleConstants::ROLE_ADMIN, RoleConstants::ROLE_AGENT];
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

    // region: View

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
            'isLocked' => $this->isEntityLocked($entity),
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

    // region: Actions

    protected function assignTicket(
        int $entityId,
        $assigneeId,
        string $redirectAction = 'index',
    ): Response {
        $this->request->allowMethod(['post', 'put']);
        $assigneeId = $this->normalizeAssigneeId($assigneeId);
        $userId = $this->getCurrentUserId();

        $components = $this->getEntityComponents();
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

    /**
     * @param int $entityId Ticket id
     * @param string $newStatus New status value
     * @param string $redirectAction Action to redirect to on completion
     */
    protected function changeTicketStatus(
        int $entityId,
        string $newStatus,
        string $redirectAction = 'index',
    ): Response {
        $this->request->allowMethod(['post', 'put']);
        $userId = $this->getCurrentUserId();

        $components = $this->getEntityComponents();
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

    /**
     * @param int $entityId Ticket id
     * @param string $newPriority New priority value
     * @param string $redirectAction Action to redirect to on completion
     */
    protected function changeTicketPriority(
        int $entityId,
        string $newPriority,
        string $redirectAction = 'index',
    ): Response {
        $this->request->allowMethod(['post', 'put']);
        $userId = $this->getCurrentUserId();

        $components = $this->getEntityComponents();
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

    /**
     * @param int $entityId Ticket id
     */
    protected function addTicketComment(int $entityId): Response
    {
        $this->request->allowMethod(['post']);
        $userId = $this->getCurrentUserId();

        $components = $this->getEntityComponents();
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

    /**
     * @param int $attachmentId Attachment id
     */
    protected function downloadTicketAttachment(int $attachmentId): Response
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

    /**
     * @return int
     */
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

    /**
     * @param array $result Service result with success/message keys
     * @param string $redirectUrl URL to redirect to
     */
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

    // region: History

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
            $components = $this->getEntityComponents();
            $tableName = $components['tableName'];
            $foreignKey = $components['foreignKey'];
            $entity = $this->fetchTable($tableName)->get($id);
            $userRole = $user->get('role');
            $userId = $user->get('id');
            if ($userRole === RoleConstants::ROLE_REQUESTER && $entity->requester_id !== $userId) {
                $this->set('error', 'No tienes permiso para ver este historial');
                $this->viewBuilder()->setOption('serialize', ['error']);
                $this->response = $this->response->withStatus(403);

                return;
            }
            $historyTable = $this->getHistoryTable();
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
