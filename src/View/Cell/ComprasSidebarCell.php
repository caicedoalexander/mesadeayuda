<?php
declare(strict_types=1);

namespace App\View\Cell;

use App\Service\StatisticsService;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\View\Cell;

/**
 * Compras Sidebar Cell
 *
 * Displays sidebar navigation for purchase order management with counts
 */
class ComprasSidebarCell extends Cell
{
    use LocatorAwareTrait;

    /**
     * Display method
     *
     * @param string $currentView Current active view
     * @param string|null $userRole User role (admin, compras)
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
        $data = $service->getSidebarCounts('Compras', $userRole, $userId);
        $statusCounts = $data['statusCounts'];

        $counts = [
            'sin_asignar' => $data['unassigned'],
            'todos_sin_resolver' => ($statusCounts['nuevo'] ?? 0) + ($statusCounts['en_revision'] ?? 0) + ($statusCounts['aprobado'] ?? 0) + ($statusCounts['en_proceso'] ?? 0),
            'nuevos' => $statusCounts['nuevo'] ?? 0,
            'en_revision' => $statusCounts['en_revision'] ?? 0,
            'aprobados' => $statusCounts['aprobado'] ?? 0,
            'en_proceso' => $statusCounts['en_proceso'] ?? 0,
            'completados' => $statusCounts['completado'] ?? 0,
            'rechazados' => $statusCounts['rechazado'] ?? 0,
            'convertidos' => $statusCounts['convertido'] ?? 0,
        ];

        if ($data['myItems'] !== null) {
            $counts['mis_compras'] = $data['myItems'];
        }

        // Count SLA breached
        $comprasTable = $this->fetchTable('Compras');
        $now = new DateTime();
        $counts['vencidos_sla'] = $comprasTable->find()
            ->where([
                'sla_due_date <' => $now,
                'status NOT IN' => ['completado', 'rechazado', 'convertido'],
            ])
            ->count();

        $this->set('counts', $counts);
        $this->set('view', $currentView);
        $this->set('userRole', $userRole);
        $this->set('currentUser', $currentUser);
    }
}
