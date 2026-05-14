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
        <?php if ($canPend): ?>
            <?= $this->Form->postLink(
                'Marcar pendiente',
                ['action' => 'changeStatus', $entity->id],
                [
                    'class' => 'btn-brand-secondary',
                    'data' => ['status' => TicketConstants::STATUS_PENDIENTE],
                ]
            ) ?>
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
