<?php
/**
 * Element: Header for Tickets view.
 *
 * @var object $entity Ticket entity
 * @var array $resolvedStatuses Array of statuses considered "resolved"
 */

$isResolved = in_array($entity->status, $resolvedStatuses);
?>

<!-- Fixed Header -->
<div class="py-3 px-4 shadow-sm bg-white rounded-md" >
    <div class="d-flex justify-content-between gap-5 small">
        <div class="d-flex flex-column justify-content-between" style="min-width: 0; flex: 1;">
            <div class="marquee-container ticket-subject-container" style="max-width: 600px;">
                <h1 class="fs-5 fw-semibold m-0 ticket-subject-text"><?= h($entity->subject) ?></h1>
            </div>
            <span><strong class="text-muted">Ticket:</strong> <?= h($entity->ticket_number) ?></span>
        </div>
        <div class="d-flex flex-column justify-content-between">
            <span class="text-muted lh-1">
                <strong class="text-muted">Creado:</strong>
                <?= $this->TimeHuman->long($entity->created) ?>
            </span>
            <?php if ($entity->resolved_at && $isResolved): ?>
                <span class="text-success lh-1">
                    <strong>Resuelto:</strong>
                    <?= $this->TimeHuman->long($entity->resolved_at) ?>
                </span>
            <?php endif; ?>
        </div>
    </div>
</div>

<?= $this->Html->script('tickets-marquee', ['block' => 'script']) ?>
