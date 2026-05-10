<?php
/**
 * Response and Resolution Metrics (Tickets-specific)
 *
 * @var float $responseRate Response rate percentage
 * @var float $resolutionRate Resolution rate percentage
 * @var object|null $avgResponseTime Average response time
 * @var object|null $avgResolutionTime Average resolution time
 */
?>

<div class="modern-card chart-card h-100" data-animate="fade-up" data-delay="600">
    <div class="chart-header">
        <h5 class="chart-title">
            <i class="bi bi-speedometer"></i>
            Rendimiento
        </h5>
    </div>
    <div class="p-3">
        <!-- Response Rate -->
        <div class="metric-card mb-3" style="background: linear-gradient(135deg, rgba(0, 168, 94, 0.05) 0%, rgba(0, 168, 94, 0.02) 100%); border-radius: 12px;">
            <div class="metric-icon-small" style="background: var(--gradient-primary);">
                <i class="bi bi-reply-fill"></i>
            </div>
            <div class="metric-content">
                <div class="metric-value text-gradient-green"><?= $responseRate ?>%</div>
                <div class="metric-label">Tasa de Respuesta</div>
            </div>
        </div>

        <!-- Resolution Rate -->
        <div class="metric-card mb-3" style="background: linear-gradient(135deg, rgba(205, 106, 21, 0.05) 0%, rgba(205, 106, 21, 0.02) 100%); border-radius: 12px;">
            <div class="metric-icon-small" style="background: var(--gradient-accent);">
                <i class="bi bi-check-circle-fill"></i>
            </div>
            <div class="metric-content">
                <div class="metric-value text-gradient-orange"><?= $resolutionRate ?>%</div>
                <div class="metric-label">Tasa de Resolución</div>
            </div>
        </div>

        <!-- Avg Times -->
        <div class="row g-2">
            <div class="col-6">
                <div class="text-center p-2 surface-soft" >
                    <div class="heading-md">
                        <?php if ($avgResponseTime && $avgResponseTime->avg_hours): ?>
                            <?= round($avgResponseTime->avg_hours, 1) ?>h
                        <?php else: ?>
                            N/A
                        <?php endif; ?>
                    </div>
                    <div class="label-meta">T. Respuesta</div>
                </div>
            </div>
            <div class="col-6">
                <div class="text-center p-2 surface-soft" >
                    <div class="heading-md">
                        <?php if ($avgResolutionTime && $avgResolutionTime->avg_hours): ?>
                            <?= round($avgResolutionTime->avg_hours, 1) ?>h
                        <?php else: ?>
                            N/A
                        <?php endif; ?>
                    </div>
                    <div class="label-meta">T. Resolución</div>
                </div>
            </div>
        </div>
    </div>
</div>
