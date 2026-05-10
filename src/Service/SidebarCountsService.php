<?php
declare(strict_types=1);

namespace App\Service;

use App\Constants\RoleConstants;
use App\Constants\TicketConstants;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Sidebar Counts Service
 *
 * Centralizes ticket sidebar counter queries (status counts, unassigned, my tickets).
 */
class SidebarCountsService
{
    use LocatorAwareTrait;

    /**
     * Get sidebar counts for tickets.
     *
     * @param string|null $userRole Current user role
     * @param int|null $userId Current user ID
     * @return array{statusCounts: array, unassigned: int, myItems: int|null}
     */
    public function getSidebarCounts(?string $userRole = null, ?int $userId = null): array
    {
        $table = $this->fetchTable('Tickets');
        $resolvedStatuses = TicketConstants::RESOLVED_STATUSES;

        $statusCounts = $table->find()
            ->select(['status', 'count' => $table->find()->func()->count('*')])
            ->groupBy(['status'])
            ->all()
            ->combine('status', 'count')
            ->toArray();

        $unassigned = $table->find()
            ->where(['assignee_id IS' => null, 'status NOT IN' => $resolvedStatuses])
            ->count();

        $myItems = null;
        if ($userRole === RoleConstants::ROLE_ASESOR_TIC && $userId) {
            $myItems = $table->find()
                ->where(['assignee_id' => $userId, 'status NOT IN' => $resolvedStatuses])
                ->count();
        }

        return [
            'statusCounts' => $statusCounts,
            'unassigned' => $unassigned,
            'myItems' => $myItems,
        ];
    }

    /**
     * Get per-status ticket counts for tickets assigned to a specific agent.
     *
     * Returns an associative array of status => count for OPEN_STATUSES only.
     * Statuses with no tickets are absent from the result.
     *
     * @param int $userId Agent user ID
     * @return array<string, int>
     */
    public function getAgentStatusCounts(int $userId): array
    {
        $table = $this->fetchTable('Tickets');

        return $table->find()
            ->select(['status', 'count' => $table->find()->func()->count('*')])
            ->where([
                'assignee_id' => $userId,
                'status IN' => TicketConstants::OPEN_STATUSES,
            ])
            ->groupBy(['status'])
            ->all()
            ->combine('status', 'count')
            ->toArray();
    }
}
