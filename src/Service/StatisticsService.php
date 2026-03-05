<?php
declare(strict_types=1);

namespace App\Service;

use App\Utility\ValidationConstants;
use Cake\Cache\Cache;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Statistics Service
 *
 * Centralizes all dashboard and reporting queries for Tickets, PQRS, and Compras.
 */
class StatisticsService
{
    use LocatorAwareTrait;

    private const CACHE_TTL = '+5 minutes';
    private const CACHE_CONFIG = ValidationConstants::CACHE_CONFIG;

    /**
     * Build a cache key from method name and filters
     *
     * @param string $prefix Cache key prefix
     * @param array $filters Filters used for the query
     * @return string
     */
    private function buildCacheKey(string $prefix, array $filters = []): string
    {
        return $prefix . '_' . md5(serialize($filters));
    }

    /**
     * Get ticket statistics
     *
     * @param array $filters Optional filters (date_range, start_date, end_date)
     * @return array Statistics data
     */
    public function getTicketStats(array $filters = []): array
    {
        $cacheKey = $this->buildCacheKey('stats_tickets', $filters);

        return Cache::remember($cacheKey, function () use ($filters) {
            return $this->computeTicketStats($filters);
        }, self::CACHE_CONFIG);
    }

    /**
     * Compute ticket statistics (uncached)
     *
     * @param array $filters Optional filters
     * @return array Statistics data
     */
    private function computeTicketStats(array $filters = []): array
    {
        $parsedFilters = $this->parseDateFilters($filters);
        $baseQuery = $this->buildBaseQuery('Tickets', $parsedFilters);

        // Use trait methods for common metrics
        $statusDistribution = $this->getStatusDistribution(
            'Tickets',
            ['nuevo', 'abierto', 'pendiente', 'resuelto', 'convertido'],
            $baseQuery
        );

        $priorityDistribution = $this->getPriorityDistribution('Tickets', $baseQuery);
        $channelDistribution = $this->getChannelDistribution('Tickets', $baseQuery);

        $avgResponseTime = $this->getAvgResponseTime('Tickets', $baseQuery);
        $avgResolutionTime = $this->getAvgResolutionTime('Tickets', $baseQuery);

        $responseRate = $this->calculateResponseRate('Tickets', $baseQuery);
        $resolutionRate = $this->calculateResolutionRate('Tickets', $baseQuery);

        $totalTickets = (clone $baseQuery)->count();

        // Recent tickets (last 7 days) - independent query
        $recentTickets = $this->getRecentActivityCount('Tickets');

        $unassignedTickets = $this->getUnassignedCount('Tickets');

        // Conversion metrics (tickets converted to Compras)
        $conversionCount = $statusDistribution['convertido'] ?? 0;
        $conversionRate = $totalTickets > 0
            ? round(($conversionCount / $totalTickets) * 100, 1)
            : 0.0;

        return [
            'total_tickets' => $totalTickets,
            'tickets_by_status' => $statusDistribution,
            'tickets_by_priority' => $priorityDistribution,
            'channel_counts' => $channelDistribution,
            'recent_tickets' => $recentTickets,
            'unassigned_tickets' => $unassignedTickets,
            'avg_response_time' => $avgResponseTime,
            'avg_resolution_time' => $avgResolutionTime,
            'response_rate' => $responseRate,
            'resolution_rate' => $resolutionRate,
            'conversion_count' => $conversionCount,
            'conversion_rate' => $conversionRate,
        ];
    }

    /**
     * Get agent performance metrics for Tickets
     *
     * @param array $filters Optional filters
     * @return array Agent performance data
     */
    public function getTicketAgentPerformance(array $filters = []): array
    {
        $parsedFilters = $this->parseDateFilters($filters);
        $baseQuery = $this->buildBaseQuery('Tickets', $parsedFilters);

        $performanceData = $this->getAgentPerformance('Tickets', [], 5, [], $baseQuery);

        return [
            'active_agents' => $performanceData['active_agents_count'],
            'tickets_by_agent' => $performanceData['top_agents'],
        ];
    }

    /**
     * Get ticket trend data for charts
     *
     * @param int $days Number of days to include
     * @return array Chart data
     */
    public function getTicketTrendData(int $days = 30): array
    {
        return $this->getTrendData('Tickets', $days);
    }

    /**
     * Get recent activity for Tickets dashboard
     *
     * @param int $limit Number of items to return
     * @return array Recent activity data
     */
    public function getRecentActivity(int $limit = 10): array
    {
        $ticketsTable = $this->fetchTable('Tickets');
        $commentsTable = $this->fetchTable('TicketComments');

        // Most active requesters (top 5) with detailed metrics
        $resolvedStatuses = ['resuelto', 'convertido'];
        $activeStatuses = ['nuevo', 'abierto', 'pendiente'];

        $query = $ticketsTable->find()
            ->contain(['Requesters']);

        // CASE expressions for counting by status
        $resolvedCase = $query->newExpr()
            ->case()
            ->when(['status IN' => $resolvedStatuses])
            ->then(1)
            ->else(0);

        $activeCase = $query->newExpr()
            ->case()
            ->when(['status IN' => $activeStatuses])
            ->then(1)
            ->else(0);

        $topRequestersRaw = $query->select([
                'requester_id',
                'requester_name' => $query->func()->concat([
                    'Requesters.first_name' => 'identifier',
                    ' ',
                    'Requesters.last_name' => 'identifier'
                ]),
                'requester_email' => 'Requesters.email',
                'total_count' => $query->func()->count('*'),
                'resolved_count' => $query->func()->sum($resolvedCase),
                'active_count' => $query->func()->sum($activeCase),
            ])
            ->group(['requester_id', 'Requesters.email'])
            ->order(['total_count' => 'DESC'])
            ->limit(5)
            ->all();

        // Process requesters data
        $topRequesters = [];
        foreach ($topRequestersRaw as $requester) {
            $requesterData = $requester->toArray();
            $requesterData['count'] = $requesterData['total_count']; // Keep for backward compatibility
            $topRequesters[] = (object) $requesterData;
        }

        // Comments stats - optimized with single query
        $commentStats = $commentsTable->find()
            ->select([
                'comment_type',
                'is_system_comment',
                'count' => $commentsTable->find()->func()->count('*')
            ])
            ->group(['comment_type', 'is_system_comment'])
            ->all()
            ->toArray();

        // Calculate from grouped results
        // IMPORTANT: Exclude system comments from all counts
        $totalComments = 0;
        $publicComments = 0;
        $internalComments = 0;

        foreach ($commentStats as $stat) {
            $count = $stat->count;

            // Skip system-generated comments entirely
            if ($stat->is_system_comment) {
                continue;
            }

            // Now count only non-system comments
            $totalComments += $count;

            if ($stat->comment_type === 'public') {
                $publicComments += $count;
            }

            if ($stat->comment_type === 'internal') {
                $internalComments += $count;
            }
        }

        return [
            'top_requesters' => $topRequesters,
            'total_comments' => $totalComments,
            'public_comments' => $publicComments,
            'internal_comments' => $internalComments,
        ];
    }


    /**
     * Get PQRS statistics
     *
     * @param array $filters Optional filters (date_from, date_to, date_range)
     * @return array PQRS statistics data
     */
    public function getPqrsStats(array $filters = []): array
    {
        $cacheKey = $this->buildCacheKey('stats_pqrs', $filters);

        return Cache::remember($cacheKey, function () use ($filters) {
            return $this->computePqrsStats($filters);
        }, self::CACHE_CONFIG);
    }

    /**
     * Compute PQRS statistics (uncached)
     *
     * @param array $filters Optional filters
     * @return array PQRS statistics data
     */
    private function computePqrsStats(array $filters = []): array
    {
        // Handle both old format (date_from/date_to) and new format (date_range)
        if (!isset($filters['date_range']) && (isset($filters['date_from']) || isset($filters['date_to']))) {
            // Convert old format to new format
            $filters['date_range'] = 'custom';
            $filters['start_date'] = $filters['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
            $filters['end_date'] = $filters['date_to'] ?? date('Y-m-d');
        }

        $parsedFilters = $this->parseDateFilters($filters);

        // For PQRS, default to last 30 days if 'all' is selected
        if ($parsedFilters['date_range'] === 'all' || $parsedFilters['date_range'] === '30days') {
            $parsedFilters['start_date'] = date('Y-m-d', strtotime('-30 days'));
            $parsedFilters['end_date'] = date('Y-m-d');
        }

        $baseQuery = $this->buildBaseQuery('Pqrs', $parsedFilters);

        // Use trait methods
        $statusCounts = $this->getStatusDistribution(
            'Pqrs',
            ['nuevo', 'en_revision', 'en_proceso', 'resuelto', 'cerrado'],
            $baseQuery
        );

        $priorityCounts = $this->getPriorityDistribution('Pqrs', $baseQuery);

        // Get type distribution (PQRS-specific)
        $typeCounts = $this->getTypeDistribution($baseQuery);

        // Get channel distribution (now implemented in trait)
        $channelCounts = $this->getChannelDistribution('Pqrs', $baseQuery);

        // Calculate totals
        $totalPqrs = array_sum($statusCounts);
        $totalResolved = ($statusCounts['resuelto'] ?? 0) + ($statusCounts['cerrado'] ?? 0);
        $totalPending = $totalPqrs - $totalResolved;

        $totalUnassigned = $this->getUnassignedCount('Pqrs');
        $recentPqrs = $this->getRecentActivityCount('Pqrs');

        // Resolved in period
        $pqrsTable = $this->fetchTable('Pqrs');
        $resolvedInPeriod = $pqrsTable->find()
            ->where([
                'resolved_at IS NOT' => null,
                'resolved_at >=' => $parsedFilters['start_date'],
                'resolved_at <=' => $parsedFilters['end_date'] . ' 23:59:59'
            ])
            ->count();

        // Average resolution time
        $avgResolutionTime = $this->getAvgResolutionTime('Pqrs', $baseQuery);
        $avgResolutionHours = ($avgResolutionTime && $avgResolutionTime->avg_hours !== null)
            ? round((float) $avgResolutionTime->avg_hours, 1)
            : 0;
        $avgResolutionDays = $avgResolutionHours > 0 ? round($avgResolutionHours / 24, 1) : 0;

        // Top agents
        $agentPerformance = $this->getAgentPerformance('Pqrs', ['resuelto', 'cerrado'], 5);

        return [
            'total_pqrs' => $totalPqrs,
            'total_resolved' => $totalResolved,
            'total_pending' => $totalPending,
            'total_unassigned' => $totalUnassigned,
            'status_counts' => $statusCounts,
            'type_counts' => $typeCounts,
            'priority_counts' => $priorityCounts,
            'channel_counts' => $channelCounts,
            'recent_pqrs' => $recentPqrs,
            'resolved_in_period' => $resolvedInPeriod,
            'avg_resolution_days' => $avgResolutionDays,
            'avg_resolution_hours' => $avgResolutionHours,
            'top_agents' => $agentPerformance['top_agents'],
            'active_agents_count' => $agentPerformance['active_agents_count'],
            'date_from' => $parsedFilters['start_date'],
            'date_to' => $parsedFilters['end_date'],
        ];
    }

    /**
     * Get PQRS SLA metrics
     *
     * @param array $filters Optional filters
     * @return array SLA metrics for PQRS
     */
    public function getPqrsSlaMetrics(array $filters = []): array
    {
        $parsedFilters = $this->parseDateFilters($filters);

        if ($parsedFilters['date_range'] === 'all' || $parsedFilters['date_range'] === '30days') {
            $parsedFilters['start_date'] = date('Y-m-d', strtotime('-30 days'));
            $parsedFilters['end_date'] = date('Y-m-d');
        }

        $baseQuery = $this->buildBaseQuery('Pqrs', $parsedFilters);
        $now = new \Cake\I18n\DateTime();
        $openStatuses = ['nuevo', 'en_revision', 'en_proceso'];

        // First response SLA breached (due date passed, no response yet)
        $responseBreachedQuery = clone $baseQuery;
        $responseBreached = $responseBreachedQuery
            ->where([
                'first_response_sla_due <' => $now,
                'first_response_at IS' => null,
                'status IN' => $openStatuses,
            ])
            ->count();

        // Resolution SLA breached (due date passed, not resolved/closed)
        $resolutionBreachedQuery = clone $baseQuery;
        $resolutionBreached = $resolutionBreachedQuery
            ->where([
                'resolution_sla_due <' => $now,
                'status IN' => $openStatuses,
            ])
            ->count();

        // Total with first_response_sla_due set (for compliance calculation)
        $totalWithResponseSlaQuery = clone $baseQuery;
        $totalWithResponseSla = $totalWithResponseSlaQuery
            ->where(['first_response_sla_due IS NOT' => null])
            ->count();

        // Responded on time
        $respondedOnTimeQuery = clone $baseQuery;
        $respondedOnTime = $respondedOnTimeQuery
            ->where([
                'first_response_sla_due IS NOT' => null,
                'first_response_at IS NOT' => null,
                'first_response_at <= first_response_sla_due',
            ])
            ->count();

        // Total with resolution_sla_due set
        $totalWithResolutionSlaQuery = clone $baseQuery;
        $totalWithResolutionSla = $totalWithResolutionSlaQuery
            ->where(['resolution_sla_due IS NOT' => null])
            ->count();

        // Resolved on time
        $resolvedOnTimeQuery = clone $baseQuery;
        $resolvedOnTime = $resolvedOnTimeQuery
            ->where([
                'resolution_sla_due IS NOT' => null,
                'resolved_at IS NOT' => null,
                'resolved_at <= resolution_sla_due',
            ])
            ->count();

        $responseComplianceRate = $totalWithResponseSla > 0
            ? round(($respondedOnTime / $totalWithResponseSla) * 100, 1)
            : 100.0;

        $resolutionComplianceRate = $totalWithResolutionSla > 0
            ? round(($resolvedOnTime / $totalWithResolutionSla) * 100, 1)
            : 100.0;

        return [
            'response_breached' => $responseBreached,
            'resolution_breached' => $resolutionBreached,
            'response_compliance_rate' => $responseComplianceRate,
            'resolution_compliance_rate' => $resolutionComplianceRate,
        ];
    }

    /**
     * Get PQRS trend data for charts
     *
     * @param int $days Number of days to include
     * @return array Chart data
     */
    public function getPqrsTrendData(int $days = 30): array
    {
        return $this->getTrendData('Pqrs', $days);
    }

    /**
     * Get Compras statistics (NEW)
     *
     * @param array $filters Optional filters (date_range, start_date, end_date)
     * @return array Compras statistics data
     */
    public function getComprasStats(array $filters = []): array
    {
        $cacheKey = $this->buildCacheKey('stats_compras', $filters);

        return Cache::remember($cacheKey, function () use ($filters) {
            return $this->computeComprasStats($filters);
        }, self::CACHE_CONFIG);
    }

    /**
     * Compute Compras statistics (uncached)
     *
     * @param array $filters Optional filters
     * @return array Compras statistics data
     */
    private function computeComprasStats(array $filters = []): array
    {
        $parsedFilters = $this->parseDateFilters($filters);

        // For Compras, default to last 30 days if 'all' is selected
        if ($parsedFilters['date_range'] === 'all' || $parsedFilters['date_range'] === '30days') {
            $parsedFilters['start_date'] = date('Y-m-d', strtotime('-30 days'));
            $parsedFilters['end_date'] = date('Y-m-d');
        }

        $baseQuery = $this->buildBaseQuery('Compras', $parsedFilters);

        // Use trait methods
        $statusCounts = $this->getStatusDistribution(
            'Compras',
            ['nuevo', 'en_revision', 'aprobado', 'en_proceso', 'completado', 'rechazado', 'convertido'],
            $baseQuery
        );

        $priorityCounts = $this->getPriorityDistribution('Compras', $baseQuery);
        $channelCounts = $this->getChannelDistribution('Compras', $baseQuery);

        $totalCompras = array_sum($statusCounts);
        $unassignedCompras = $this->getUnassignedCount('Compras');
        $recentCompras = $this->getRecentActivityCount('Compras');

        // Average response time
        $avgResponseTime = $this->getAvgResponseTime('Compras', $baseQuery);
        $avgResponseHours = ($avgResponseTime && $avgResponseTime->avg_hours !== null)
            ? round((float) $avgResponseTime->avg_hours, 1)
            : 0;

        $responseRate = $this->calculateResponseRate('Compras', $baseQuery);

        // Average resolution time
        $avgResolutionTime = $this->getAvgResolutionTime('Compras', $baseQuery);
        $avgResolutionHours = ($avgResolutionTime && $avgResolutionTime->avg_hours !== null)
            ? round((float) $avgResolutionTime->avg_hours, 1)
            : 0;
        $avgResolutionDays = $avgResolutionHours > 0 ? round($avgResolutionHours / 24, 1) : 0;

        // Agent performance (by completed compras)
        $agentPerformance = $this->getAgentPerformance('Compras', ['completado'], 5);

        // Top requesters
        $topRequesters = $this->getTopRequestersCompras(5);

        // Compras-specific metrics
        $slaMetrics = $this->getSLAMetrics($baseQuery);
        $approvalMetrics = $this->getApprovalMetrics($baseQuery);

        return [
            'total_compras' => $totalCompras,
            'status_counts' => $statusCounts,
            'priority_counts' => $priorityCounts,
            'channel_counts' => $channelCounts,
            'unassigned_compras' => $unassignedCompras,
            'recent_compras' => $recentCompras,
            'avg_response_hours' => $avgResponseHours,
            'response_rate' => $responseRate,
            'avg_resolution_hours' => $avgResolutionHours,
            'avg_resolution_days' => $avgResolutionDays,
            'top_agents' => $agentPerformance['top_agents'],
            'active_agents_count' => $agentPerformance['active_agents_count'],
            'top_requesters' => $topRequesters,
            'sla_metrics' => $slaMetrics,
            'approval_metrics' => $approvalMetrics,
            'date_from' => $parsedFilters['start_date'],
            'date_to' => $parsedFilters['end_date'],
        ];
    }

    /**
     * Get Compras trend data for charts (NEW)
     *
     * @param int $days Number of days to include
     * @return array Chart data
     */
    public function getComprasTrendData(int $days = 30): array
    {
        return $this->getTrendData('Compras', $days);
    }

    // ==================== PRIVATE MODULE-SPECIFIC METHODS ====================

    /**
     * Get type distribution (PQRS-specific)
     *
     * @param \Cake\ORM\Query|null $baseQuery Optional pre-filtered query
     * @return array Type => count mapping
     */
    private function getTypeDistribution($baseQuery = null): array
    {
        if ($baseQuery === null) {
            $pqrsTable = $this->fetchTable('Pqrs');
            $baseQuery = $pqrsTable->find();
        }

        $typeCountsRaw = (clone $baseQuery)
            ->select(['type', 'count' => $baseQuery->func()->count('*')])
            ->group(['type'])
            ->all()
            ->combine('type', 'count')
            ->toArray();

        return [
            'peticion' => $typeCountsRaw['peticion'] ?? 0,
            'queja' => $typeCountsRaw['queja'] ?? 0,
            'reclamo' => $typeCountsRaw['reclamo'] ?? 0,
            'sugerencia' => $typeCountsRaw['sugerencia'] ?? 0,
        ];
    }

    /**
     * Get SLA metrics (Compras-specific)
     *
     * @param \Cake\ORM\Query $baseQuery Base query with filters applied
     * @return array SLA metrics
     */
    private function getSLAMetrics($baseQuery): array
    {
        $now = new \Cake\I18n\DateTime();

        // SLA breached count (past deadline and not completed/rejected/converted)
        $breachedQuery = clone $baseQuery;
        $breachedCount = $breachedQuery
            ->where([
                'resolution_sla_due <' => $now,
                'status NOT IN' => ['completado', 'rechazado', 'convertido']
            ])
            ->count();

        // SLA at risk (< 24 hours remaining)
        $atRiskQuery = clone $baseQuery;
        $tomorrow = (new \Cake\I18n\DateTime())->modify('+24 hours');
        $atRiskCount = $atRiskQuery
            ->where([
                'resolution_sla_due >=' => $now,
                'resolution_sla_due <' => $tomorrow,
                'status NOT IN' => ['completado', 'rechazado', 'convertido']
            ])
            ->count();

        // Total with active SLA
        $activeSLAQuery = clone $baseQuery;
        $activeSLACount = $activeSLAQuery
            ->where(['status NOT IN' => ['completado', 'rechazado', 'convertido']])
            ->count();

        // Compliance rate
        $complianceRate = $activeSLACount > 0
            ? round((($activeSLACount - $breachedCount) / $activeSLACount) * 100, 1)
            : 100.0;

        return [
            'breached_count' => $breachedCount,
            'at_risk_count' => $atRiskCount,
            'active_count' => $activeSLACount,
            'compliance_rate' => $complianceRate,
        ];
    }

    /**
     * Get approval metrics (Compras-specific)
     *
     * @param \Cake\ORM\Query $baseQuery Base query with filters applied
     * @return array Approval metrics
     */
    private function getApprovalMetrics($baseQuery): array
    {
        // Approved count (aprobado + en_proceso + completado)
        $approvedQuery = clone $baseQuery;
        $approvedCount = $approvedQuery
            ->where(['status IN' => ['aprobado', 'en_proceso', 'completado']])
            ->count();

        // Rejected count
        $rejectedQuery = clone $baseQuery;
        $rejectedCount = $rejectedQuery
            ->where(['status' => 'rechazado'])
            ->count();

        // Total decided (approved + rejected)
        $totalDecided = $approvedCount + $rejectedCount;

        // Approval rate
        $approvalRate = $totalDecided > 0
            ? round(($approvedCount / $totalDecided) * 100, 1)
            : 0.0;

        return [
            'approved_count' => $approvedCount,
            'rejected_count' => $rejectedCount,
            'total_decided' => $totalDecided,
            'approval_rate' => $approvalRate,
        ];
    }

    /**
     * Get top requesters for Compras (NEW)
     *
     * @param int $limit Number of top requesters to return
     * @return array Top requesters data
     */
    private function getTopRequestersCompras(int $limit = 5): array
    {
        $comprasTable = $this->fetchTable('Compras');

        $completedStatuses = ['completado'];
        $activeStatuses = ['nuevo', 'en_revision', 'aprobado', 'en_proceso'];

        $query = $comprasTable->find();

        $completedCase = $query->newExpr()
            ->case()
            ->when(['Compras.status IN' => $completedStatuses])
            ->then(1)
            ->else(0);

        $activeCase = $query->newExpr()
            ->case()
            ->when(['Compras.status IN' => $activeStatuses])
            ->then(1)
            ->else(0);

        // Single query with JOIN instead of N+1 pattern
        $topRequestersRaw = $query->select([
                'Compras.requester_id',
                'total_count' => $query->func()->count('*'),
                'resolved_count' => $query->func()->sum($completedCase),
                'active_count' => $query->func()->sum($activeCase),
                'requester_name' => $query->func()->concat([
                    'Requesters.first_name' => 'identifier',
                    ' ',
                    'Requesters.last_name' => 'identifier',
                ]),
                'requester_email' => 'Requesters.email',
            ])
            ->innerJoinWith('Requesters')
            ->where(['Compras.requester_id IS NOT' => null])
            ->group(['Compras.requester_id', 'Requesters.first_name', 'Requesters.last_name', 'Requesters.email'])
            ->order(['total_count' => 'DESC'])
            ->limit($limit)
            ->all();

        $topRequesters = [];
        foreach ($topRequestersRaw as $requester) {
            $requesterData = $requester->toArray();
            $requesterData['count'] = $requesterData['total_count'];
            $topRequesters[] = (object) $requesterData;
        }

        return $topRequesters;
    }

    // ==================== INLINED FROM StatisticsServiceTrait ====================

    /**
     * Parse date filters from request
     *
     * @param array $filters Raw filters array
     * @return array Normalized filters with start_date and end_date
     */
    private function parseDateFilters(array $filters): array
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
                break;
            default:
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
     * @param string $tableName Table name
     * @param array $filters Parsed filters
     * @return \Cake\ORM\Query
     */
    private function buildBaseQuery(string $tableName, array $filters): \Cake\ORM\Query
    {
        $table = $this->fetchTable($tableName);
        $baseQuery = $table->find();

        $startDate = $filters['start_date'] ?? null;
        $endDate = $filters['end_date'] ?? null;

        if ($startDate && $endDate) {
            $baseQuery->where([
                "{$tableName}.created >=" => $startDate . ' 00:00:00',
                "{$tableName}.created <=" => $endDate . ' 23:59:59',
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
    private function getStatusDistribution(string $tableName, array $validStatuses, $baseQuery = null): array
    {
        if ($baseQuery === null) {
            $table = $this->fetchTable($tableName);
            $baseQuery = $table->find();
        }

        $statusCountsRaw = (clone $baseQuery)
            ->select([
                'status',
                'count' => $baseQuery->func()->count('*'),
            ])
            ->group('status')
            ->all()
            ->combine('status', 'count')
            ->toArray();

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
    private function getPriorityDistribution(string $tableName, $baseQuery = null): array
    {
        if ($baseQuery === null) {
            $table = $this->fetchTable($tableName);
            $baseQuery = $table->find();
        }

        $validPriorities = ['baja', 'media', 'alta', 'urgente'];

        $priorityCountsRaw = (clone $baseQuery)
            ->select([
                'priority',
                'count' => $baseQuery->func()->count('*'),
            ])
            ->group('priority')
            ->all()
            ->combine('priority', 'count')
            ->toArray();

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
    private function getTrendData(string $tableName, int $days = 30): array
    {
        $table = $this->fetchTable($tableName);

        $dailyStats = $table->find()
            ->select([
                'date' => 'DATE(created)',
                'count' => 'COUNT(*)',
            ])
            ->where(['created >=' => date('Y-m-d', strtotime("-{$days} days"))])
            ->group(['DATE(created)'])
            ->orderBy(['date' => 'ASC'])
            ->toArray();

        $statsMap = [];
        foreach ($dailyStats as $stat) {
            $statsMap[$stat->date] = $stat->count;
        }

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
     * @param string $tableName Table name
     * @param array $resolvedStatuses Status values that count as "resolved"
     * @param int $limit Number of top agents to return
     * @param array $agentRoles Roles that count as agents
     * @param \Cake\ORM\Query|null $baseQuery Optional pre-filtered query
     * @return array ['top_agents' => [...], 'active_agents_count' => int]
     */
    private function getAgentPerformance(string $tableName, array $resolvedStatuses = [], int $limit = 5, array $agentRoles = [], $baseQuery = null): array
    {
        $table = $this->fetchTable($tableName);
        $usersTable = $this->fetchTable('Users');

        if (empty($agentRoles)) {
            $agentRoles = match ($tableName) {
                'Tickets' => [ValidationConstants::ROLE_AGENT],
                'Pqrs' => [ValidationConstants::ROLE_SERVICIO_CLIENTE],
                'Compras' => [ValidationConstants::ROLE_COMPRAS],
                default => [ValidationConstants::ROLE_AGENT],
            };
        }

        $activeAgents = $usersTable->find()
            ->where([
                'role IN' => $agentRoles,
                'is_active' => true,
            ])
            ->count();

        if (empty($resolvedStatuses)) {
            $resolvedStatuses = match ($tableName) {
                'Tickets' => ['resuelto', 'convertido'],
                'Pqrs' => ['resuelto', 'cerrado'],
                'Compras' => ['completado'],
                default => ['resuelto'],
            };
        }

        $query = $baseQuery !== null ? (clone $baseQuery) : $table->find();
        $query->where(['assignee_id IS NOT' => null]);

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

        $topAgentsRaw = $query->all();

        $userIds = [];
        foreach ($topAgentsRaw as $agent) {
            if ($agent->assignee_id) {
                $userIds[] = $agent->assignee_id;
            }
        }

        $users = [];
        if (!empty($userIds)) {
            $usersCollection = $usersTable->find()
                ->where(['id IN' => $userIds])
                ->all();
            foreach ($usersCollection as $user) {
                $users[$user->id] = $user;
            }
        }

        $topAgents = [];
        foreach ($topAgentsRaw as $agent) {
            $assignedCount = $agent->assigned_count ?? 0;
            $resolvedCount = $agent->resolved_count ?? 0;

            $resolutionRate = $assignedCount > 0
                ? round(($resolvedCount / $assignedCount) * 100, 1)
                : 0;

            $agent->resolution_rate = $resolutionRate;
            $agent->count = $assignedCount;

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
    private function getAvgResolutionTime(string $tableName, $baseQuery = null)
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
                ),
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
    private function getAvgResponseTime(string $tableName, $baseQuery = null)
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
                ),
            ])
            ->first();
    }

    /**
     * Get unassigned entities count
     *
     * @param string $tableName Table name
     * @return int Count of unassigned entities
     */
    private function getUnassignedCount(string $tableName): int
    {
        return $this->fetchTable($tableName)->find()
            ->where(['assignee_id IS' => null])
            ->count();
    }

    /**
     * Get recent activity count (last 7 days)
     *
     * @param string $tableName Table name
     * @return int Count of entities created in last 7 days
     */
    private function getRecentActivityCount(string $tableName): int
    {
        return $this->fetchTable($tableName)->find()
            ->where(['created >=' => new \Cake\I18n\DateTime('-7 days')])
            ->count();
    }

    /**
     * Get channel distribution
     *
     * @param string $tableName Table name
     * @param \Cake\ORM\Query|null $baseQuery Optional pre-filtered query
     * @return array Channel => count mapping
     */
    private function getChannelDistribution(string $tableName, $baseQuery = null): array
    {
        if ($baseQuery === null) {
            $table = $this->fetchTable($tableName);
            $baseQuery = $table->find();
        }

        return (clone $baseQuery)
            ->select([
                'channel',
                'count' => $baseQuery->func()->count('*'),
            ])
            ->group('channel')
            ->all()
            ->combine('channel', 'count')
            ->toArray();
    }

    /**
     * Calculate response rate percentage
     *
     * @param string $tableName Table name
     * @param \Cake\ORM\Query|null $baseQuery Optional pre-filtered query
     * @return float Response rate percentage
     */
    private function calculateResponseRate(string $tableName, $baseQuery = null): float
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
    private function calculateResolutionRate(string $tableName, $baseQuery = null): float
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

    // ==================== END INLINED METHODS ====================

    /**
     * Get sidebar counts for a given entity table
     *
     * Centralizes the GROUP BY status + role-based count queries
     * used by all sidebar cells.
     *
     * @param string $tableName Table name ('Tickets', 'Compras', 'Pqrs')
     * @param string|null $userRole Current user role
     * @param int|null $userId Current user ID
     * @return array{statusCounts: array, unassigned: int, myItems: int|null}
     */
    public function getSidebarCounts(string $tableName, ?string $userRole = null, ?int $userId = null): array
    {
        $table = $this->fetchTable($tableName);

        $resolvedStatuses = match ($tableName) {
            'Tickets' => ['resuelto', 'convertido'],
            'Compras' => ['completado', 'rechazado', 'convertido'],
            'Pqrs' => ['resuelto', 'cerrado'],
            default => [],
        };

        // Status counts in a single GROUP BY query
        $statusCounts = $table->find()
            ->select(['status', 'count' => $table->find()->func()->count('*')])
            ->group(['status'])
            ->all()
            ->combine('status', 'count')
            ->toArray();

        // Unassigned count
        $unassigned = $table->find()
            ->where(['assignee_id IS' => null, 'status NOT IN' => $resolvedStatuses])
            ->count();

        // "My items" count (role-dependent)
        $myItems = null;
        $canHaveMyItems = match ($tableName) {
            'Tickets' => $userRole === ValidationConstants::ROLE_AGENT,
            'Compras' => in_array($userRole, [ValidationConstants::ROLE_COMPRAS, ValidationConstants::ROLE_ADMIN]),
            'Pqrs' => in_array($userRole, ValidationConstants::STAFF_ROLES),
            default => false,
        };

        if ($canHaveMyItems && $userId) {
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
