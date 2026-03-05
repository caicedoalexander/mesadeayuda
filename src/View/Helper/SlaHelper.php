<?php
declare(strict_types=1);

namespace App\View\Helper;

use App\Service\SlaManagementService;
use Cake\I18n\DateTime;
use Cake\View\Helper;

/**
 * SLA Helper
 *
 * Entity-agnostic SLA display logic. Receives pure data (dates, statuses)
 * instead of entity objects, so it works with Tickets, PQRS, and Compras.
 */
class SlaHelper extends Helper
{
    private SlaManagementService $slaService;

    /**
     * @param array $config Configuration
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->slaService = new SlaManagementService();
    }

    /**
     * Calculate SLA display status from raw data
     *
     * @param \Cake\I18n\DateTime|null $slaDue SLA deadline
     * @param \Cake\I18n\DateTime|null $completedAt When entity was completed/resolved
     * @param \Cake\I18n\DateTime $created Entity creation time
     * @param string $status Current entity status
     * @param array $terminalStatuses Statuses considered terminal (no SLA tracking)
     * @param string $type 'resolution' or 'first_response'
     * @return array SLA data with color, percentage, status, icon, label, etc.
     */
    public function getSlaDisplayStatus(
        ?DateTime $slaDue,
        ?DateTime $completedAt,
        DateTime $created,
        string $status,
        array $terminalStatuses,
        string $type = 'resolution'
    ): array {
        if (!$slaDue || in_array($status, $terminalStatuses)) {
            return [
                'color' => 'secondary',
                'textColor' => 'text-muted',
                'bgColor' => 'bg-secondary',
                'percentage' => 0,
                'status' => 'completed',
                'label' => 'N/A',
                'icon' => 'bi-check-circle',
                'type' => $type,
            ];
        }

        $slaServiceStatus = $this->slaService->getSlaStatus($slaDue, $completedAt, $status);

        $now = new DateTime();

        $totalSeconds = $slaDue->diffInSeconds($created);
        $elapsedSeconds = $now->diffInSeconds($created);
        $remainingSeconds = $slaDue->diffInSeconds($now);

        $percentageUsed = $totalSeconds > 0 ? ($elapsedSeconds / $totalSeconds) * 100 : 100;
        $percentageUsed = min(100, max(0, $percentageUsed));

        $statusMap = [
            'met' => [
                'color' => 'success',
                'textColor' => 'text-success',
                'bgColor' => 'bg-success',
                'icon' => 'bi-check-circle-fill',
                'label' => 'Cumplido',
            ],
            'breached' => [
                'color' => 'danger',
                'textColor' => 'text-danger',
                'bgColor' => 'bg-danger',
                'icon' => 'bi-exclamation-triangle-fill',
                'label' => 'Vencido',
                'hoursOver' => ceil($remainingSeconds / 3600),
            ],
            'breached_resolved' => [
                'color' => 'warning',
                'textColor' => 'text-warning',
                'bgColor' => 'bg-warning',
                'icon' => 'bi-exclamation-circle',
                'label' => 'Vencido (Resuelto)',
            ],
            'approaching' => [
                'color' => 'warning',
                'textColor' => 'text-warning',
                'bgColor' => 'bg-warning',
                'icon' => 'bi-exclamation-circle-fill',
                'label' => 'Próximo a vencer',
                'hoursLeft' => ceil($remainingSeconds / 3600),
            ],
            'on_track' => [
                'color' => 'success',
                'textColor' => 'text-success',
                'bgColor' => 'bg-success',
                'icon' => 'bi-check-circle-fill',
                'label' => 'En tiempo',
                'hoursLeft' => ceil($remainingSeconds / 3600),
            ],
            'none' => [
                'color' => 'secondary',
                'textColor' => 'text-muted',
                'bgColor' => 'bg-secondary',
                'icon' => 'bi-dash-circle',
                'label' => 'N/A',
            ],
        ];

        $statusInfo = $statusMap[$slaServiceStatus['status']] ?? $statusMap['none'];
        $statusInfo['percentage'] = round($percentageUsed, 1);
        $statusInfo['status'] = $slaServiceStatus['status'];
        $statusInfo['type'] = $type;
        $statusInfo['sla_due'] = $slaDue;

        return $statusInfo;
    }

    /**
     * Render simple SLA icon indicator (for index views)
     *
     * @param array $sla SLA status array from getSlaDisplayStatus()
     * @return string HTML icon
     */
    public function slaIcon(array $sla): string
    {
        if ($sla['status'] === 'completed' || !isset($sla['sla_due'])) {
            return '<i class="bi bi-dash-circle text-muted" title="N/A"></i>';
        }

        $dateFormatted = $sla['sla_due']->format('h:i a, d M');

        $tooltip = $sla['label'] . ' (Resolución) - ' . $dateFormatted;
        if (isset($sla['hoursLeft'])) {
            $tooltip .= ' (' . $sla['hoursLeft'] . 'h restantes)';
        } elseif (isset($sla['hoursOver'])) {
            $tooltip .= ' (+' . $sla['hoursOver'] . 'h de retraso)';
        }

        return sprintf(
            '<i class="%s %s" style="font-size: 1.2rem;" title="%s"></i>',
            h($sla['icon']),
            h($sla['textColor']),
            h($tooltip)
        );
    }

    /**
     * Render dual SLA indicator showing both first response and resolution
     *
     * @param array|null $firstResponseSla First response SLA from getSlaDisplayStatus()
     * @param array|null $resolutionSla Resolution SLA from getSlaDisplayStatus()
     * @return string HTML
     */
    public function dualSlaIndicator(?array $firstResponseSla, ?array $resolutionSla): string
    {
        $html = '<div class="d-flex flex-column gap-2">';

        // First Response SLA
        $html .= $this->renderSlaBlock('Primera Respuesta', $firstResponseSla);

        // Resolution SLA
        $html .= $this->renderSlaBlock('Resolución', $resolutionSla);

        $html .= '</div>';

        return $html;
    }

    /**
     * Render SLA badge with traffic light colors
     *
     * @param array $sla SLA status array from getSlaDisplayStatus()
     * @param bool $showPercentage Show time remaining in badge
     * @return string HTML badge
     */
    public function slaBadge(array $sla, bool $showPercentage = false): string
    {
        if ($sla['status'] === 'completed') {
            return '<span class="text-muted">N/A</span>';
        }

        $label = $sla['label'];
        if ($showPercentage && isset($sla['hoursLeft'])) {
            $label .= ' (' . $sla['hoursLeft'] . 'h)';
        } elseif ($showPercentage && isset($sla['hoursOver'])) {
            $label .= ' (+' . $sla['hoursOver'] . 'h)';
        }

        return sprintf(
            '<span class="badge %s"><i class="%s"></i> %s</span>',
            h($sla['bgColor']),
            h($sla['icon']),
            h($label)
        );
    }

    /**
     * Render a single SLA block for the dual indicator
     *
     * @param string $title Block title
     * @param array|null $sla SLA status data
     * @return string HTML
     */
    private function renderSlaBlock(string $title, ?array $sla): string
    {
        $html = '<div class="p-2 border rounded" style="background-color: #f8f9fa;">';
        $html .= '<div class="d-flex align-items-center justify-content-between mb-1">';
        $html .= sprintf('<small class="text-muted fw-semibold">%s</small>', h($title));

        if (!$sla || $sla['status'] === 'completed') {
            $html .= '<span class="badge bg-secondary">N/A</span>';
        } else {
            $html .= sprintf(
                '<i class="%s %s" style="font-size: 1.1rem;"></i>',
                h($sla['icon']),
                h($sla['textColor'])
            );
        }
        $html .= '</div>';

        if ($sla && $sla['status'] !== 'completed') {
            $html .= sprintf(
                '<div class="%s fw-semibold small">%s</div>',
                h($sla['textColor']),
                h($sla['label'])
            );
            if (isset($sla['sla_due'])) {
                $html .= sprintf(
                    '<div class="small text-muted mt-1">%s</div>',
                    h($sla['sla_due']->format('d M Y, h:i a'))
                );
            }
        }
        $html .= '</div>';

        return $html;
    }
}
