<?php
declare(strict_types=1);

namespace App\View\Cell;

use App\Constants\RoleConstants;
use App\Constants\TicketConstants;
use App\Service\SidebarCountsService;
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

        $service = new SidebarCountsService();
        $data = $service->getSidebarCounts($userRole, $userId);
        $statusCounts = $data['statusCounts'];

        $isAgent = $userRole === RoleConstants::ROLE_AGENT;

        // For agents: count status-specific tickets assigned to them
        $agentStatusCounts = [];
        if ($isAgent && $userId) {
            $agentStatusCounts = $service->getAgentStatusCounts($userId);
        }

        $countFor = static fn(string $status): int => $isAgent
            ? (int)($agentStatusCounts[$status] ?? 0)
            : (int)($statusCounts[$status] ?? 0);

        $counts = [
            'sin_asignar' => $data['unassigned'],
            'todos_sin_resolver' => ($statusCounts[TicketConstants::STATUS_NUEVO] ?? 0)
                + ($statusCounts[TicketConstants::STATUS_ABIERTO] ?? 0)
                + ($statusCounts[TicketConstants::STATUS_PENDIENTE] ?? 0),
            'pendientes' => $countFor(TicketConstants::STATUS_PENDIENTE),
            'nuevos' => $countFor(TicketConstants::STATUS_NUEVO),
            'abiertos' => $countFor(TicketConstants::STATUS_ABIERTO),
            'resueltos' => $statusCounts[TicketConstants::STATUS_RESUELTO] ?? 0,
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
