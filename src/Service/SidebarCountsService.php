<?php
declare(strict_types=1);

namespace App\Service;

use App\Utility\ValidationConstants;
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
        $resolvedStatuses = ['resuelto', 'convertido'];

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
        if ($userRole === ValidationConstants::ROLE_AGENT && $userId) {
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
}
