<?php
declare(strict_types=1);

namespace App\View\Cell;

use App\Service\StatisticsService;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\View\Cell;

/**
 * PQRS Sidebar Cell
 *
 * Displays sidebar navigation for PQRS management with counts
 */
class PqrsSidebarCell extends Cell
{
    use LocatorAwareTrait;

    /**
     * Display method
     *
     * @param string $currentView Current active view
     * @param string|null $userRole User role (admin, agent, servicio_cliente, compras, requester)
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
        $data = $service->getSidebarCounts('Pqrs', $userRole, $userId);
        $statusCounts = $data['statusCounts'];

        $counts = [
            'sin_asignar' => $data['unassigned'],
            'todos_sin_resolver' => ($statusCounts['nuevo'] ?? 0) + ($statusCounts['en_revision'] ?? 0) + ($statusCounts['en_proceso'] ?? 0),
            'nuevas' => $statusCounts['nuevo'] ?? 0,
            'en_revision' => $statusCounts['en_revision'] ?? 0,
            'en_proceso' => $statusCounts['en_proceso'] ?? 0,
            'resueltas' => $statusCounts['resuelto'] ?? 0,
            'cerradas' => $statusCounts['cerrado'] ?? 0,
        ];

        if ($data['myItems'] !== null) {
            $counts['mis_pqrs'] = $data['myItems'];
        }

        // Type counts (unresolved only)
        $pqrsTable = $this->fetchTable('Pqrs');
        $typeCountsRaw = $pqrsTable->find()
            ->select(['type', 'count' => $pqrsTable->find()->func()->count('*')])
            ->where(['status NOT IN' => ['resuelto', 'cerrado']])
            ->group(['type'])
            ->all()
            ->combine('type', 'count')
            ->toArray();

        $typeCounts = [
            'peticion' => $typeCountsRaw['peticion'] ?? 0,
            'queja' => $typeCountsRaw['queja'] ?? 0,
            'reclamo' => $typeCountsRaw['reclamo'] ?? 0,
            'sugerencia' => $typeCountsRaw['sugerencia'] ?? 0,
        ];

        $this->set('counts', $counts);
        $this->set('typeCounts', $typeCounts);
        $this->set('view', $currentView);
        $this->set('userRole', $userRole);
        $this->set('currentUser', $currentUser);
    }
}
