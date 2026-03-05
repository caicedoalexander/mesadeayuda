<?php
declare(strict_types=1);

namespace App\Controller;

use App\Controller\Traits\StatisticsControllerTrait;
use App\Controller\Traits\TicketSystemControllerTrait;
use App\Controller\Traits\ServiceInitializerTrait;
use App\Service\TicketService;
use App\Utility\ValidationConstants;
use App\Service\StatisticsService;

/**
 * Tickets Controller
 *
 * @property \App\Model\Table\TicketsTable $Tickets
 */
class TicketsController extends AppController
{
    use StatisticsControllerTrait;
    use TicketSystemControllerTrait;
    use ServiceInitializerTrait;

    private TicketService $ticketService;
    private StatisticsService $statisticsService;
    private \App\Service\ComprasService $comprasService;

    /**
     * beforeFilter callback - Redirect users based on their role
     *
     * REFACTORED: Uses AppController::redirectByRole() to eliminate duplicated code
     *
     * @param \Cake\Event\EventInterface<\Cake\Controller\Controller> $event Event
     * @return \Cake\Http\Response|null|void
     */
    public function beforeFilter(\Cake\Event\EventInterface $event)
    {
        parent::beforeFilter($event);

        // Unlock actions that use JS-submitted forms or AJAX
        $this->FormProtection->setConfig('unlockedActions', [
            'addComment', 'assign', 'changeStatus', 'changePriority',
            'addTag', 'removeTag', 'addFollower', 'convertToCompra',
            'bulkAssign', 'bulkChangePriority', 'bulkAddTag', 'bulkDelete',
            'history',
        ]);

        // Allow admin, agent, and requester roles for Tickets module
        return $this->redirectByRole([ValidationConstants::ROLE_ADMIN, ValidationConstants::ROLE_AGENT, ValidationConstants::ROLE_REQUESTER], 'tickets');
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
            'filterParams' => [
                'organization_id' => 'filter_organization',
            ],
            'specialRedirects' => function($request, $user, $userRole) {
                // Handle Gmail OAuth callback redirect
                $code = $request->getQuery('code');
                if ($code) {
                    $this->redirect([
                        'controller' => 'Settings',
                        'action' => 'gmailAuth',
                        'prefix' => 'Admin',
                        '?' => ['code' => $code]
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
    public function view($id = null)
    {
        return $this->viewEntity('ticket', (int)$id, [
            'lazyLoadHistory' => true, // PERFORMANCE FIX: Load history via AJAX
            'permissionCheck' => function($ticket) {
                return $this->_checkTicketViewPermission($ticket);
            },
            'beforeSet' => function($ticket, $viewVars) {
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
    private function _checkTicketViewPermission($ticket)
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
    public function addComment($id = null)
    {
        return $this->addEntityComment('ticket', (int) $id);
    }

    /**
     * Assign ticket to agent
     *
     * @param string|null $id Ticket id
     * @return \Cake\Http\Response|null|void Redirects back
     */
    public function assign($id = null)
    {
        return $this->assignEntity('ticket', (int) $id, $this->request->getData('assignee_id'));
    }

    /**
     * Change ticket status
     *
     * @param string|null $id Ticket id
     * @return \Cake\Http\Response|null|void Redirects back
     */
    public function changeStatus($id = null)
    {
        return $this->changeEntityStatus('ticket', (int) $id, $this->request->getData('status'));
    }

    /**
     * Change ticket priority
     *
     * @param string|null $id Ticket id
     * @return \Cake\Http\Response|null|void Redirects back
     */
    public function changePriority($id = null)
    {
        return $this->changeEntityPriority('ticket', (int) $id, $this->request->getData('priority'));
    }

    /**
     * Add tag to ticket
     *
     * @param string|null $id Ticket id
     * @return \Cake\Http\Response|null|void Redirects back
     */
    public function addTag($id = null)
    {
        $this->request->allowMethod(['post']);

        $tagId = (int) $this->request->getData('tag_id');
        $result = $this->ticketService->addTag((int) $id, $tagId);

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
    public function removeTag($id = null, $tagId = null)
    {
        $this->request->allowMethod(['post', 'delete']);

        $result = $this->ticketService->removeTag((int) $id, (int) $tagId);

        $this->Flash->{$result['success'] ? 'success' : 'error'}($result['message']);

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Add follower to ticket
     *
     * @param string|null $id Ticket id
     * @return \Cake\Http\Response|null|void Redirects back
     */
    public function addFollower($id = null)
    {
        $this->request->allowMethod(['post']);

        $userId = (int) $this->request->getData('user_id');
        $result = $this->ticketService->addFollower((int) $id, $userId);

        $this->Flash->{$result['success'] ? 'success' : ($result['message'] === 'Este usuario ya está siguiendo el ticket.' ? 'warning' : 'error')}($result['message']);

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Statistics - Dashboard with metrics and analytics
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function statistics()
    {
        $this->renderStatistics('ticket', ['defaultRange' => 'all']);
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
    public function downloadAttachment($id = null)
    {
        return $this->downloadEntityAttachment('ticket', (int) $id);
    }

    /**
     * AJAX endpoint for lazy loading ticket history
     * PERFORMANCE FIX: Only loads when history tab is opened
     *
     * @param string|null $id Ticket id
     * @return void JSON response
     */
    public function history($id = null)
    {
        $this->historyEntity('ticket', (int)$id);
    }

    /**
     * Convert ticket to compra
     *
     * REFACTORED: Business logic moved to TicketService::convertToCompra()
     *
     * @param int|null $id Ticket id
     * @return \Cake\Http\Response|null Redirects to tickets index
     */
    public function convertToCompra($id = null)
    {
        $this->request->allowMethod(['post']);

        $user = $this->Authentication->getIdentity();

        // Allow admin and agent to convert tickets
        $allowedRoles = [ValidationConstants::ROLE_ADMIN, ValidationConstants::ROLE_AGENT];
        if (!$user || !in_array($user->role, $allowedRoles)) {
            $this->Flash->error(__('No tienes permiso para esta acción.'));
            return $this->redirect(['action' => 'view', $id]);
        }

        try {
            // Load ticket with necessary associations
            $ticket = $this->Tickets->get($id, [
                'contain' => ['TicketComments', 'Attachments']
            ]);

            // Perform conversion via service (handles all business logic)
            $compra = $this->ticketService->convertToCompra(
                $ticket,
                (int) $user->id,
                $this->comprasService
            );

            if ($compra) {
                $this->Flash->success(__('Ticket convertido exitosamente a Compra'));
                return $this->redirect(['controller' => 'Tickets', 'action' => 'index']);
            }

            $this->Flash->error(__('Error al convertir ticket a compra.'));
            return $this->redirect(['action' => 'view', $id]);

        } catch (\Exception $e) {
            \Cake\Log\Log::error('Error en convertToCompra: ' . $e->getMessage());
            $this->Flash->error(__('Error al procesar la conversión. Contacta al administrador.'));
            return $this->redirect(['action' => 'view', $id]);
        }
    }
}
