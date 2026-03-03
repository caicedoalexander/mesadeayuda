<?php
declare(strict_types=1);

namespace App\Controller;

use App\Controller\Traits\StatisticsControllerTrait;
use App\Controller\Traits\TicketSystemControllerTrait;
use App\Controller\Traits\ServiceInitializerTrait;
use App\Service\ComprasService;
use App\Service\ResponseService;
use App\Service\StatisticsService;
use Cake\Event\EventInterface;

/**
 * Compras Controller
 *
 * Sistema de gestión de compras convertidas desde tickets
 * Solo accesible para roles: admin y compras
 *
 * @property \App\Model\Table\ComprasTable $Compras
 */
class ComprasController extends AppController
{
    use StatisticsControllerTrait;
    use TicketSystemControllerTrait;
    use ServiceInitializerTrait;

    private ComprasService $comprasService;
    private ResponseService $responseService;
    private StatisticsService $statisticsService;
    private \App\Service\TicketService $ticketService;

    /**
     * beforeFilter callback - Restrict access to admin and compras roles only
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
            'convertToTicket', 'bulkAssign', 'bulkChangePriority', 'bulkDelete',
            'history',
        ]);

        // Allow admin and compras roles for Compras module
        return $this->redirectByRole(['admin', 'compras'], 'compras');
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

        // Initialize all Compras services using trait
        $this->initializeComprasServices();
    }

    /**
     * Index method - List compras with filters
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function index()
    {
        $this->indexEntity('compra', [
            'paginationLimit' => 10,
            'beforeQuery' => function($query) {
                // Query is already built, we'll process SLA data after pagination
                return $query;
            },
            'additionalViewVars' => [
                'comprasService' => $this->comprasService,
            ],
        ]);
    }

    /**
     * View method - Display individual compra
     *
     * @param int|null $id Compra id
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function view($id = null)
    {
        return $this->viewEntity('compra', (int)$id, [
            'lazyLoadHistory' => true, // PERFORMANCE FIX: Load history via AJAX
            'beforeSet' => function($compra, $viewVars) {
                // Check SLA status
                $isSLABreached = $this->comprasService->isResolutionSLABreached($compra);

                // Get users for assignment with formatted names
                $comprasUsers = $this->fetchTable('Users')->find()
                    ->where(['role' => 'compras', 'is_active' => true])
                    ->orderBy(['first_name' => 'ASC', 'last_name' => 'ASC'])
                    ->all()
                    ->combine('id', function ($user) {
                        return $user->first_name . ' ' . $user->last_name;
                    })
                    ->toArray();

                $priorities = [
                    'baja' => 'Baja',
                    'media' => 'Media',
                    'alta' => 'Alta',
                    'urgente' => 'Urgente',
                ];

                // Status configuration for reply editor (with icon, color, label)
                $statuses = [
                    'nuevo' => ['icon' => 'bi-circle-fill', 'color' => 'info', 'label' => 'Nuevo'],
                    'en_revision' => ['icon' => 'bi-circle-fill', 'color' => 'warning', 'label' => 'En Revisión'],
                    'aprobado' => ['icon' => 'bi-circle-fill', 'color' => 'success', 'label' => 'Aprobado'],
                    'en_proceso' => ['icon' => 'bi-circle-fill', 'color' => 'primary', 'label' => 'En Proceso'],
                    'completado' => ['icon' => 'bi-circle-fill', 'color' => 'success', 'label' => 'Completado'],
                    'rechazado' => ['icon' => 'bi-circle-fill', 'color' => 'danger', 'label' => 'Rechazado'],
                ];

                // Merge with existing view vars
                return array_merge($viewVars, compact('comprasUsers', 'priorities', 'statuses', 'isSLABreached'));
            },
        ]);
    }

    /**
     * Assign compra to user (uses trait method)
     *
     * @param int|null $id Compra id
     * @return \Cake\Http\Response|null
     */
    public function assign($id = null)
    {
        return $this->assignEntity('compra', (int)$id, $this->request->getData('assignee_id'));
    }

    /**
     * Change compra status (uses trait method)
     *
     * @param int|null $id Compra id
     * @return \Cake\Http\Response|null
     */
    public function changeStatus($id = null)
    {
        return $this->changeEntityStatus('compra', (int)$id, $this->request->getData('status'));
    }

    /**
     * Change compra priority (uses trait method)
     *
     * @param int|null $id Compra id
     * @return \Cake\Http\Response|null
     */
    public function changePriority($id = null)
    {
        return $this->changeEntityPriority('compra', (int)$id, $this->request->getData('priority'));
    }

    /**
     * Add comment to compra (uses trait method)
     *
     * @param int|null $id Compra id
     * @return \Cake\Http\Response|null
     */
    public function addComment($id = null)
    {
        return $this->addEntityComment('compra', (int)$id);
    }

    /**
     * Download attachment (uses trait method)
     *
     * @param int|null $id Attachment id
     * @return \Cake\Http\Response
     */
    public function download($id = null)
    {
        return $this->downloadEntityAttachment('compra', (int)$id);
    }

    /**
     * AJAX endpoint for lazy loading compra history
     * PERFORMANCE FIX: Only loads when history tab is opened
     *
     * @param string|null $id Compra id
     * @return void JSON response
     */
    public function history($id = null)
    {
        $this->historyEntity('compra', (int)$id);
    }

    /**
     * Bulk assign compras to agents
     *
     * @return \Cake\Http\Response|null|void Redirects on success
     */
    public function bulkAssign()
    {
        return $this->bulkAssignEntity('compra');
    }

    /**
     * Bulk change priority of compras
     *
     * @return \Cake\Http\Response|null|void Redirects on success
     */
    public function bulkChangePriority()
    {
        return $this->bulkChangeEntityPriority('compra');
    }

    /**
     * Bulk delete compras
     *
     * @return \Cake\Http\Response|null|void Redirects on success
     */
    public function bulkDelete()
    {
        return $this->bulkDeleteEntity('compra');
    }

    /**
     * Convert compra to ticket
     *
     * REFACTORED: Business logic moved to ComprasService::convertToTicket()
     *
     * @param int|null $id Compra id
     * @return \Cake\Http\Response|null Redirects to compras index
     */
    public function convertToTicket($id = null)
    {
        $this->request->allowMethod(['post']);

        $user = $this->Authentication->getIdentity();

        // Allow admin and compras users to convert
        $allowedRoles = ['admin', 'compras'];
        if (!$user || !in_array($user->role, $allowedRoles)) {
            $this->Flash->error(__('No tienes permiso para esta acción.'));
            return $this->redirect(['action' => 'view', $id]);
        }

        try {
            // Load compra with necessary associations
            $compra = $this->Compras->get($id, [
                'contain' => ['Requesters']
            ]);

            // Perform conversion via service (handles all business logic)
            $ticket = $this->comprasService->convertToTicket(
                $compra,
                (int) $user->id,
                $this->ticketService
            );

            if ($ticket) {
                $this->Flash->success(__('Compra convertida exitosamente a Ticket'));
                return $this->redirect(['controller' => 'Compras', 'action' => 'index']);
            }

            $this->Flash->error(__('Error al convertir compra a ticket.'));
            return $this->redirect(['action' => 'view', $id]);

        } catch (\Exception $e) {
            \Cake\Log\Log::error('Error en convertToTicket: ' . $e->getMessage());
            $this->Flash->error(__('Error al procesar la conversión. Contacta al administrador.'));
            return $this->redirect(['action' => 'view', $id]);
        }
    }

    /**
     * Statistics - Display compras statistics and analytics
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function statistics()
    {
        $this->renderStatistics('compra', ['defaultRange' => 'all']);
    }

}
