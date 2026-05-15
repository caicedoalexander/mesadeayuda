<?php
/**
 * Element: Bulk Actions Bar para Tickets.
 *
 * @var bool $showTagAction Mostrar acción de tags (default: false)
 */

$showTagAction = $showTagAction ?? false;
?>

<!-- Bulk Actions Bar -->
<div class="btn-group bulk-bar">
    <button type="button" id="bulkActionsTrigger" class="btn-brand-secondary dropdown-toggle"
        data-bs-toggle="dropdown" aria-expanded="false" disabled>
        <i class="bi bi-lightning-charge"></i>
        Acciones rápidas
    </button>
    <ul class="dropdown-menu dropdown-menu-end app-popover">
        <li>
            <button class="app-popover-item" onclick="bulkAction('assign'); return false;">
                <i class="bi bi-person-fill-add"></i> Asignar a agente
            </button>
        </li>
        <li>
            <button class="app-popover-item" onclick="bulkAction('changePriority'); return false;">
                <i class="bi bi-exclamation-triangle"></i> Cambiar prioridad
            </button>
        </li>
        <?php if ($showTagAction): ?>
        <li class="app-popover-divider"></li>
        <li>
            <button class="app-popover-item" onclick="bulkAction('addTag'); return false;">
                <i class="bi bi-tag"></i> Agregar etiqueta
            </button>
        </li>
        <?php endif; ?>
        <li class="app-popover-divider"></li>
        <li>
            <button class="app-popover-item danger" onclick="bulkAction('delete'); return false;">
                <i class="bi bi-trash"></i> Eliminar
            </button>
        </li>
    </ul>
</div>
