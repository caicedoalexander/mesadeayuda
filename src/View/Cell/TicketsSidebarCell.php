<?php
declare(strict_types=1);

namespace App\View\Cell;

use App\Service\StatisticsService;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\View\Cell;

class TicketsSidebarCell extends Cell
{
    use LocatorAwareTrait;

    /**
     * Display method
     *
     * @param string $currentView Current active view
     * @param string|null $userRole User role (admin, agent, requester)
     * @param int|null $userId Current user ID
     * @return void
     */
    public function display(string $currentView = 'todos_sin_resolver', ?string $userRole = null, ?int $userId = null): void
    {
        $currentUser = null;
        if ($userId) {
            $currentUser = $this->fetchTable('Users')->get($userId);
        }

        $service = new StatisticsService();
        $data = $service->getSidebarCounts('Tickets', $userRole, $userId);
        $statusCounts = $data['statusCounts'];

        $isAgent = $userRole === 'agent';

        // For agents: count status-specific tickets assigned to them
        $agentStatusCounts = [];
        if ($isAgent && $userId) {
            $ticketsTable = $this->fetchTable('Tickets');
            $agentStatusCounts = $ticketsTable->find()
                ->select(['status', 'count' => $ticketsTable->find()->func()->count('*')])
                ->where(['assignee_id' => $userId, 'status IN' => ['nuevo', 'abierto', 'pendiente']])
                ->group(['status'])
                ->all()
                ->combine('status', 'count')
                ->toArray();
        }

        $counts = [
            'sin_asignar' => $data['unassigned'],
            'todos_sin_resolver' => ($statusCounts['nuevo'] ?? 0) + ($statusCounts['abierto'] ?? 0) + ($statusCounts['pendiente'] ?? 0),
            'pendientes' => $isAgent ? ($agentStatusCounts['pendiente'] ?? 0) : ($statusCounts['pendiente'] ?? 0),
            'nuevos' => $isAgent ? ($agentStatusCounts['nuevo'] ?? 0) : ($statusCounts['nuevo'] ?? 0),
            'abiertos' => $isAgent ? ($agentStatusCounts['abierto'] ?? 0) : ($statusCounts['abierto'] ?? 0),
            'resueltos' => $statusCounts['resuelto'] ?? 0,
            'convertidos' => $statusCounts['convertido'] ?? 0,
        ];

        if ($isAgent && $userId) {
            $counts['mis_tickets'] = $data['myItems'];
        }

        $this->set('counts', $counts);
        $this->set('view', $currentView);
        $this->set('userRole', $userRole);
        $this->set('currentUser', $currentUser);
    }
}
