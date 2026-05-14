<?php
/**
 * Element: Ticket detail top bar.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Ticket $entity
 * @var bool $isLocked
 */

use App\Constants\TicketConstants;

$isResolved = $entity->isResolved();
$isPending  = $entity->isPending();
$canResolve = !$isLocked && !$isResolved;
$canPend    = !$isLocked && !$isPending;
?>
<header class="ticket-topbar">
    <!-- Left: Volver + breadcrumb -->
    <?= $this->Html->link(
        '<i class="bi bi-chevron-left"></i> Volver',
        ['action' => 'index'],
        ['class' => 'btn-brand-secondary btn-brand-sm', 'escape' => false]
    ) ?>

    <nav class="ticket-breadcrumb" aria-label="breadcrumb">
        <span>Tickets</span>
        <i class="bi bi-chevron-right separator"></i>
        <span>Sin resolver</span>
        <i class="bi bi-chevron-right separator"></i>
        <span class="mono current">#<?= h($entity->ticket_number ?? $entity->id) ?></span>
    </nav>

    <div class="ticket-topbar-spacer"></div>

    <!-- Right: actions -->
    <?php if (!$isLocked): ?>
        <?php
        // States the user can switch to from this view (excludes the current
        // one and the "resolver" terminal action which has its own button).
        $switchableStates = [
            TicketConstants::STATUS_ABIERTO   => 'Marcar como abierto',
            TicketConstants::STATUS_PENDIENTE => 'Marcar como pendiente',
        ];
        $availableSwitches = array_filter(
            $switchableStates,
            fn($_, $key) => $key !== $entity->status,
            ARRAY_FILTER_USE_BOTH
        );
        ?>

        <?php if (!empty($availableSwitches)): ?>
            <div class="dropdown ticket-topbar-status-wrap">
                <button type="button"
                        class="btn-brand-secondary dropdown-toggle ticket-topbar-status-btn"
                        data-bs-toggle="dropdown"
                        aria-expanded="false">
                    Cambiar estado
                </button>
                <ul class="dropdown-menu dropdown-menu-end ticket-topbar-status-menu">
                    <?php foreach ($availableSwitches as $statusKey => $label):
                        $dotColor = TicketConstants::STATUS_COLORS[$statusKey] ?? 'var(--gray-500)';
                    ?>
                        <li>
                            <?= $this->Form->postLink(
                                '<span class="status-dot" style="background:' . h($dotColor) . '"></span> ' . h($label),
                                ['action' => 'changeStatus', $entity->id],
                                [
                                    'class' => 'dropdown-item ticket-topbar-status-item',
                                    'escape' => false,
                                    'data' => ['status' => $statusKey],
                                ]
                            ) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($canResolve): ?>
            <?= $this->Form->postLink(
                '<i class="bi bi-check-lg"></i> Resolver ticket',
                ['action' => 'changeStatus', $entity->id],
                [
                    'class' => 'btn-brand-primary',
                    'escape' => false,
                    'data' => ['status' => TicketConstants::STATUS_RESUELTO],
                ]
            ) ?>
        <?php endif; ?>
    <?php else: ?>
        <span class="ticket-locked-pill">
            <i class="bi bi-lock-fill"></i> Cerrado
        </span>
    <?php endif; ?>
</header>
