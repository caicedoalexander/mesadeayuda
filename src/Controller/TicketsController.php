<?php
declare(strict_types=1);

namespace App\Controller;

use App\Constants\RoleConstants;
use App\Controller\Trait\TicketActionsTrait;
use App\Controller\Trait\TicketBulkTrait;
use App\Controller\Trait\TicketHistoryTrait;
use App\Controller\Trait\TicketListingTrait;
use App\Controller\Trait\TicketServiceInitializerTrait;
use App\Controller\Trait\TicketViewTrait;
use App\Service\AuthorizationService;
use App\Service\TicketPipelineService;
use App\Service\Traits\GenericAttachmentTrait;
use Cake\Event\EventInterface;

/**
 * Tickets Controller
 *
 * Composes six region traits under App\Controller\Trait. Each trait owns
 * a cohesive slice of the ticket workflow (initialization, listing, view,
 * single-entity actions, bulk operations, history). The controller itself
 * only wires up auth/CSRF and exposes the shared TicketPipelineService property.
 *
 * @property \App\Model\Table\TicketsTable $Tickets
 */
class TicketsController extends AppController
{
    use GenericAttachmentTrait;
    use TicketServiceInitializerTrait;
    use TicketListingTrait;
    use TicketViewTrait;
    use TicketActionsTrait;
    use TicketBulkTrait;
    use TicketHistoryTrait;

    private TicketPipelineService $ticketPipeline;

    private AuthorizationService $authService;

    /**
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

        return $this->redirectByRole(RoleConstants::STAFF_ROLES, 'tickets');
    }

    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        $this->initializeTicketSystemServices();
    }
}
