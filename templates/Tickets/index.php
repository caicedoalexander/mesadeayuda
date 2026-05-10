<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Ticket> $tickets
 * @var \App\View\Helper\StatusHelper $Status
 * @var \App\View\Helper\TimeHumanHelper $TimeHuman
 */
$this->assign('title', 'Tickets');

// Get user info for sidebar
$user = $this->getRequest()->getAttribute('identity');
$userRole = $user ? $user->get('role') : null;
$userId = $user ? $user->get('id') : null;
?>

<!-- Load CSS and JS -->
<?= $this->Html->css('bulk-actions') ?>
<?= $this->Html->script('bulk-actions-module') ?>

<div class="d-flex">
    <?= $this->cell('TicketsSidebar::display', [$view, $userRole, $userId]) ?>
</div>

<div class="content-shell d-flex flex-column">
    <!-- Page Header -->
    <div class="page-header">
        <div class="header-icon">
            <i class="bi bi-ticket"></i>
        </div>
        <div class="header-text">
            <h3>
                <?php
                $titles = [
                    'sin_asignar' => 'Tickets sin asignar',
                    'todos_sin_resolver' => 'Tickets sin resolver',
                    'nuevos' => 'Tickets nuevos',
                    'abiertos' => 'Tickets abiertos',
                    'pendientes' => 'Tickets pendientes',
                    'resueltos' => 'Tickets resueltos',
                    'mis_tickets' => 'Mis tickets',
                ];
                echo $titles[$view] ?? 'Tickets';
                ?>
            </h3>
        </div>
    </div>

    <div class="content-toolbar d-flex align-items-center gap-2">
        <!-- Search Bar -->
        <?= $this->element('tickets/search_bar', [
            'searchValue' => $filters['search'] ?? '',
            'placeholder' => 'Buscar tickets...',
            'view' => $view
        ]) ?>

        <!-- Bulk Actions Bar -->
        <?= $this->element('tickets/bulk_actions_bar', [
            'showTagAction' => true
        ]) ?>
    </div>

    <div id="entity-list-content" class="d-flex flex-column flex-grow-1" style="min-height: 0;">
        <div class="content-meta">
            <span class="meta-count"><?= $tickets->count() ?></span>
            <span class="meta-label">tickets</span>
            <span class="meta-sep">·</span>
            <span class="meta-page"><?= $this->Paginator->counter(__('Página {{page}} de {{pages}}')) ?></span>
        </div>

        <?php if ($tickets->count() > 0): ?>
            <div class="table-responsive table-scroll tickets-table-wrap mb-auto">
                <table class="table tickets-table align-middle">
                    <thead>
                        <tr>
                            <th class="col-check">
                                <input type="checkbox" id="checkAll" class="form-check-input" />
                            </th>
                            <th class="col-status">Estado</th>
                            <th class="col-subject">Asunto</th>
                            <th class="col-requester">Solicitante</th>
                            <th class="col-assignee">Asignado a</th>
                            <?php if ($view === 'resueltos'): ?>
                                <th class="col-date">
                                    <?= $this->Paginator->sort('resolved_at', 'Resuelto') ?>
                                </th>
                            <?php endif; ?>
                            <th class="col-date">
                                <?= $this->Paginator->sort('created', 'Solicitado') ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tickets as $ticket): ?>
                            <tr>
                                <td class="col-check">
                                    <input type="checkbox" class="form-check-input row-check"
                                        value="<?= (int) $ticket->id ?>" />
                                </td>

                                <td class="col-status">
                                    <?= $this->Status->statusBadge($ticket->status) ?>
                                </td>

                                <td class="col-subject text-truncate">
                                    <?= $this->Html->link(
                                        h($ticket->subject),
                                        ['action' => 'view', $ticket->id],
                                        ['class' => 'subject-link']
                                    ) ?>
                                </td>

                                <td class="col-requester text-truncate">
                                    <span class="requester-name"><?= h($ticket->requester->name) ?></span>
                                    <span class="requester-email"><?= h($ticket->requester->email) ?></span>
                                </td>

                                <td class="col-assignee">
                                    <?php
                                    $isLocked = $ticket->isLocked();
                                    $isDisabled = $isAssignmentDisabled || $isLocked;
                                    ?>
                                    <?= $this->Form->create(null, ['url' => ['action' => 'assign', $ticket->id], 'class' => 'table-assign-form']) ?>
                                    <?= $this->Form->select('assignee_id', $agents, [
                                        'value' => $ticket->assignee_id,
                                        'empty' => 'Sin asignar',
                                        'class' => 'table-agent-select form-select form-select-sm',
                                        'disabled' => $isDisabled,
                                        'data-ticket-id' => $ticket->id
                                    ]) ?>
                                    <?= $this->Form->end() ?>
                                </td>

                                <?php if ($view === 'resueltos'): ?>
                                    <td class="col-date">
                                        <?= $ticket->resolved_at ? $this->TimeHuman->short($ticket->resolved_at) : '—' ?>
                                    </td>
                                <?php endif; ?>

                                <td class="col-date">
                                    <?= $this->TimeHuman->short($ticket->created) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <nav aria-label="Paginación" class="content-pagination">
                <?= $this->element('pagination') ?>
            </nav>

        <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="bi bi-inbox"></i>
                </div>
                <p class="empty-state-title">No hay tickets en esta vista</p>
                <p class="empty-state-hint">Cuando lleguen, aparecerán aquí.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modales para acciones rápidas -->
<?= $this->element('tickets/bulk_modals', [
    'agents' => $agents,
    'tags' => $tags,
    'showTagModal' => true
]) ?>

<?php
$showInitialSpinner = $this->request->getSession()->check('show_loading_spinner');
if ($showInitialSpinner) {
    $this->request->getSession()->delete('show_loading_spinner');
}
?>
<?= $this->Html->script('ajax-refresh', ['block' => 'script']) ?>
<script>
    window.ticketsIndexData = { showInitialSpinner: <?= $showInitialSpinner ? 'true' : 'false' ?> };
</script>
<?= $this->Html->script('tickets-index', ['block' => 'script']) ?>
