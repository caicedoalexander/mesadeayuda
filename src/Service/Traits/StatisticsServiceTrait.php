<?php
declare(strict_types=1);

namespace App\Service\Traits;

use App\Utility\ValidationConstants;

/**
 * Statistics Service Trait
 *
 * Provides shared statistics methods for Tickets, PQRS, and Compras modules.
 * Contains all common query logic to eliminate code duplication.
 */
trait StatisticsServiceTrait
{
    /**
     * Parse date filters from request
     *
     * @param array $filters Raw filters array
     * @return array Normalized filters with start_date and end_date
     */
    protected function parseDateFilters(array $filters): array
    {
        $dateRange = $filters['date_range'] ?? 'all';
        $startDate = $filters['start_date'] ?? null;
        $endDate = $filters['end_date'] ?? null;

        $now = new \Cake\I18n\DateTime();
        switch ($dateRange) {
            case 'today':
                $startDate = $now->format('Y-m-d');
                $endDate = $now->format('Y-m-d');
                break;
            case 'week':
                $startDate = (new \Cake\I18n\DateTime('-7 days'))->format('Y-m-d');
                $endDate = $now->format('Y-m-d');
                break;
            case 'month':
                $startDate = (new \Cake\I18n\DateTime('-30 days'))->format('Y-m-d');
                $endDate = $now->format('Y-m-d');
                break;
            case 'custom':
                // Use provided dates
                break;
            default:
                // 'all' or '30days' - no date filter
                $startDate = null;
                $endDate = null;
        }

        return [
            'date_range' => $dateRange,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
    }

    /**
     * Build base query with date filters applied
     *
     * @param string $tableName Table name (e.g., 'Tickets', 'Pqrs', 'Compras')
     * @param array $filters Parsed filters with start_date and end_date
     * @return \Cake\ORM\Query
     */
    protected function buildBaseQuery(string $tableName, array $filters): \Cake\ORM\Query
    {
        $table = $this->fetchTable($tableName);
        $baseQuery = $table->find();

        $startDate = $filters['start_date'] ?? null;
        $endDate = $filters['end_date'] ?? null;

        if ($startDate && $endDate) {
            $baseQuery->where([
                "{$tableName}.created >=" => $startDate . ' 00:00:00',
                "{$tableName}.created <=" => $endDate . ' 23:59:59'
            ]);
        }

        return $baseQuery;
    }

    /**
     * Get status distribution
     *
     * @param string $tableName Table name
     * @param array $validStatuses List of valid status values
     * @param \Cake\ORM\Query|null $baseQuery Optional pre-filtered query
     * @return array Status => count mapping
     */
    protected function getStatusDistribution(string $tableName, array $validStatuses, $baseQuery = null): array
    {
        if ($baseQuery === null) {
            $table = $this->fetchTable($tableName);
            $baseQuery = $table->find();
        }

        $statusCountsRaw = (clone $baseQuery)
            ->select([
                'status',
                'count' => $baseQuery->func()->count('*')
            ])
            ->group('status')
            ->all()
            ->combine('status', 'count')
            ->toArray();

        // Ensure all valid statuses are present with 0 count
        $statusCounts = [];
        foreach ($validStatuses as $status) {
            $statusCounts[$status] = $statusCountsRaw[$status] ?? 0;
        }

        return $statusCounts;
    }

    /**
     * Get priority distribution
     *
     * @param string $tableName Table name
     * @param \Cake\ORM\Query|null $baseQuery Optional pre-filtered query
     * @return array Priority => count mapping
     */
    protected function getPriorityDistribution(string $tableName, $baseQuery = null): array
    {
        if ($baseQuery === null) {
            $table = $this->fetchTable($tableName);
            $baseQuery = $table->find();
        }

        $validPriorities = ['baja', 'media', 'alta', 'urgente'];

        $priorityCountsRaw = (clone $baseQuery)
            ->select([
                'priority',
                'count' => $baseQuery->func()->count('*')
            ])
            ->group('priority')
            ->all()
            ->combine('priority', 'count')
            ->toArray();

        // Ensure all priorities are present
        $priorityCounts = [];
        foreach ($validPriorities as $priority) {
            $priorityCounts[$priority] = $priorityCountsRaw[$priority] ?? 0;
        }

        return $priorityCounts;
    }

    /**
     * Get trend data (daily creation counts)
     *
     * @param string $tableName Table name
     * @param int $days Number of days to analyze
     * @return array ['chart_labels' => [...], 'chart_data' => [...]]
     */
    protected function getTrendData(string $tableName, int $days = 30): array
    {
        $table = $this->fetchTable($tableName);

        // Get daily counts
        $dailyStats = $table->find()
            ->select([
                'date' => 'DATE(created)',
                'count' => 'COUNT(*)'
            ])
            ->where(['created >=' => date('Y-m-d', strtotime("-{$days} days"))])
            ->group(['DATE(created)'])
            ->orderBy(['date' => 'ASC'])
            ->toArray();

        // Create a map of dates to counts
        $statsMap = [];
        foreach ($dailyStats as $stat) {
            $statsMap[$stat->date] = $stat->count;
        }

        // Generate complete range with zeros for missing days
        $chartLabels = [];
        $chartData = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $chartLabels[] = date('d/m', strtotime($date));
            $chartData[] = $statsMap[$date] ?? 0;
        }

        return [
            'chart_labels' => $chartLabels,
            'chart_data' => $chartData,
        ];
    }

    /**
     * Get agent performance metrics
     *
     * @param string $tableName Table name (e.g., 'Tickets', 'Pqrs', 'Compras')
     * @param array $resolvedStatuses Status values that count as "resolved"
     * @param int $limit Number of top agents to return
     * @param array $agentRoles Roles that count as agents (defaults based on table)
     * @param \Cake\ORM\Query|null $baseQuery Optional pre-filtered query
     * @return array ['top_agents' => [...], 'active_agents_count' => int]
     */
    protected function getAgentPerformance(string $tableName, array $resolvedStatuses = [], int $limit = 5, array $agentRoles = [], $baseQuery = null): array
    {
        $table = $this->fetchTable($tableName);
        $usersTable = $this->fetchTable('Users');

        // Determine agent roles based on module if not specified
        if (empty($agentRoles)) {
            $agentRoles = match($tableName) {
                'Tickets' => [ValidationConstants::ROLE_AGENT],  // Tickets: only agents (not admin)
                'Pqrs' => [ValidationConstants::ROLE_SERVICIO_CLIENTE],  // PQRS: customer service agents
                'Compras' => [ValidationConstants::ROLE_COMPRAS],  // Compras: procurement agents
                default => [ValidationConstants::ROLE_AGENT]
            };
        }

        // Active agents count - only count relevant roles for this module
        $activeAgents = $usersTable->find()
            ->where([
                'role IN' => $agentRoles,
                'is_active' => true
            ])
            ->count();

        // Define resolved statuses - use parameter if provided, otherwise use defaults
        if (empty($resolvedStatuses)) {
            $resolvedStatuses = match($tableName) {
                'Tickets' => ['resuelto', 'convertido'],
                'Pqrs' => ['resuelto', 'cerrado'],
                'Compras' => ['completado'],  // Only successfully completed purchases
                default => ['resuelto']
            };
        }

        // Build query for top agents with detailed performance metrics
        $query = $baseQuery !== null ? (clone $baseQuery) : $table->find();
        $query->where(['assignee_id IS NOT' => null]);

        // Add select fields with CASE expression for resolved count
        $caseExpression = $query->newExpr()
            ->case()
            ->when(['status IN' => $resolvedStatuses])
            ->then(1)
            ->else(0);

        $query->select([
            'assignee_id',
            'assigned_count' => $query->func()->count('*'),
            'resolved_count' => $query->func()->sum($caseExpression),
        ])
        ->group(['assignee_id'])
        ->order(['assigned_count' => 'DESC'])
        ->limit($limit);

        // Note: We don't filter by status here - we want to show all assigned tickets
        // The CASE expression already counts only resolved ones

        $topAgentsRaw = $query->all();

        // Load full user objects for each agent
        $userIds = [];
        foreach ($topAgentsRaw as $agent) {
            if ($agent->assignee_id) {
                $userIds[] = $agent->assignee_id;
            }
        }

        // Fetch all users at once
        $users = [];
        if (!empty($userIds)) {
            $usersCollection = $usersTable->find()
                ->where(['id IN' => $userIds])
                ->all();
            foreach ($usersCollection as $user) {
                $users[$user->id] = $user;
            }
        }

        // Calculate resolution rate for each agent and attach user object
        $topAgents = [];
        foreach ($topAgentsRaw as $agent) {
            $assignedCount = $agent->assigned_count ?? 0;
            $resolvedCount = $agent->resolved_count ?? 0;

            // Calculate resolution rate (percentage)
            $resolutionRate = $assignedCount > 0
                ? round(($resolvedCount / $assignedCount) * 100, 1)
                : 0;

            // Add resolution_rate and user object
            $agent->resolution_rate = $resolutionRate;
            $agent->count = $assignedCount; // Keep for backward compatibility

            // Attach full user object
            if (isset($users[$agent->assignee_id])) {
                $agent->assignee = $users[$agent->assignee_id];
                $agent->agent_name = $users[$agent->assignee_id]->first_name . ' ' . $users[$agent->assignee_id]->last_name;
            }

            $topAgents[] = $agent;
        }

        return [
            'top_agents' => $topAgents,
            'active_agents_count' => $activeAgents,
        ];
    }

    /**
     * Calculate average resolution time in hours
     *
     * @param string $tableName Table name
     * @param \Cake\ORM\Query|null $baseQuery Optional pre-filtered query
     * @return object|null Object with avg_hours property
     */
    protected function getAvgResolutionTime(string $tableName, $baseQuery = null)
    {
        if ($baseQuery === null) {
            $table = $this->fetchTable($tableName);
            $baseQuery = $table->find();
        }

        return (clone $baseQuery)
            ->where(['resolved_at IS NOT' => null])
            ->select([
                'avg_hours' => $baseQuery->func()->avg(
                    "TIMESTAMPDIFF(SECOND, created, resolved_at) / 3600"
                )
            ])
            ->first();
    }

    /**
     * Calculate average first response time in hours
     *
     * @param string $tableName Table name
     * @param \Cake\ORM\Query|null $baseQuery Optional pre-filtered query
     * @return object|null Object with avg_hours property
     */
    protected function getAvgResponseTime(string $tableName, $baseQuery = null)
    {
        if ($baseQuery === null) {
            $table = $this->fetchTable($tableName);
            $baseQuery = $table->find();
        }

        return (clone $baseQuery)
            ->where(['first_response_at IS NOT' => null])
            ->select([
                'avg_hours' => $baseQuery->func()->avg(
                    "TIMESTAMPDIFF(SECOND, created, first_response_at) / 3600"
                )
            ])
            ->first();
    }

    /**
     * Get unassigned entities count
     *
     * @param string $tableName Table name
     * @return int Count of unassigned entities
     */
    protected function getUnassignedCount(string $tableName): int
    {
        $table = $this->fetchTable($tableName);

        return $table->find()
            ->where(['assignee_id IS' => null])
            ->count();
    }

    /**
     * Get recent activity count (last 7 days)
     *
     * @param string $tableName Table name
     * @return int Count of entities created in last 7 days
     */
    protected function getRecentActivityCount(string $tableName): int
    {
        $table = $this->fetchTable($tableName);

        return $table->find()
            ->where(['created >=' => new \Cake\I18n\DateTime('-7 days')])
            ->count();
    }

    /**
     * Get channel distribution (for modules that have 'channel' field)
     *
     * @param string $tableName Table name
     * @param \Cake\ORM\Query|null $baseQuery Optional pre-filtered query
     * @return array Channel => count mapping
     */
    protected function getChannelDistribution(string $tableName, $baseQuery = null): array
    {
        if ($baseQuery === null) {
            $table = $this->fetchTable($tableName);
            $baseQuery = $table->find();
        }

        $channelCountsRaw = (clone $baseQuery)
            ->select([
                'channel',
                'count' => $baseQuery->func()->count('*')
            ])
            ->group('channel')
            ->all()
            ->combine('channel', 'count')
            ->toArray();

        return $channelCountsRaw;
    }

    /**
     * Calculate response rate percentage
     *
     * @param string $tableName Table name
     * @param \Cake\ORM\Query|null $baseQuery Optional pre-filtered query
     * @return float Response rate percentage
     */
    protected function calculateResponseRate(string $tableName, $baseQuery = null): float
    {
        if ($baseQuery === null) {
            $table = $this->fetchTable($tableName);
            $baseQuery = $table->find();
        }

        $total = (clone $baseQuery)->count();
        if ($total === 0) {
            return 0.0;
        }

        $withResponse = (clone $baseQuery)
            ->where(['first_response_at IS NOT' => null])
            ->count();

        return round(($withResponse / $total) * 100, 1);
    }

    /**
     * Calculate resolution rate percentage
     *
     * @param string $tableName Table name
     * @param \Cake\ORM\Query|null $baseQuery Optional pre-filtered query
     * @return float Resolution rate percentage
     */
    protected function calculateResolutionRate(string $tableName, $baseQuery = null): float
    {
        if ($baseQuery === null) {
            $table = $this->fetchTable($tableName);
            $baseQuery = $table->find();
        }

        $total = (clone $baseQuery)->count();
        if ($total === 0) {
            return 0.0;
        }

        $resolved = (clone $baseQuery)
            ->where(['resolved_at IS NOT' => null])
            ->count();

        return round(($resolved / $total) * 100, 1);
    }
}
