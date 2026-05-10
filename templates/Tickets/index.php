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

<div class="pt-3 pb-1 ps-5 pe-4 w-100 d-flex flex-column">
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

    <div class="d-flex align-items-center mb-1 gap-2">
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
        <div class="mb-2 fs-6 d-flex align-items-center">
            <small class="me-1"> <?= $tickets->count() ?> Tickets </small>
            <small class="m-0 text-muted">(<?= $this->Paginator->counter(__('Pagina {{page}} de {{pages}}')) ?>)</small>
        </div>

        <?php if ($tickets->count() > 0): ?>
            <div class="table-responsive table-scroll mb-auto">
                <table class="table table-hover">
                    <thead class="bg-white" style="position: sticky; top: 0; z-index: 10;">
                        <tr>
                            <th class="w-fit pe-4 align-middle" style="width:36px">
                                <input type="checkbox" id="checkAll" class="form-check-input border border-dark rounded" />
                            </th>
                            <th class="w-fit fw-semibold align-middle fs-sm" >Estado</th>
                            <th class="w-fit fw-semibold align-middle fs-sm" >Asunto</th>
                            <th class="w-fit fw-semibold align-middle fs-sm" >Solicitante</th>
                            <th class="w-fit fw-semibold align-middle fs-sm" >Asignado a</th>
                            <?php if ($view === 'resueltos'): ?>
                                <th class="w-fit fw-semibold align-middle fs-sm" >
                                    <?= $this->Paginator->sort('resolved_at', 'Resuelto') ?>
                                </th>
                            <?php endif; ?>
                            <th class="w-fit fw-semibold align-middle fs-sm" >
                                <?= $this->Paginator->sort('created', 'Solicitado') ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="">
                        <?php foreach ($tickets as $ticket): ?>
                            <tr>
                                <td class="py-0 align-middle ">
                                    <input type="checkbox" class="form-check-input row-check rounded border border-dark"
                                        value="<?= (int) $ticket->id ?>" />
                                </td>

                                <td class="py-0 align-middle " style="width: 100px; font-size: 14px;">
                                    <?= $this->Status->statusBadge($ticket->status) ?>
                                </td>

                                <td class="py-0 fw-light align-middle text-truncate"
                                    style="min-width: 300px; max-width: 300px;">
                                    <?= $this->Html->link(
                                        h($ticket->subject),
                                        ['action' => 'view', $ticket->id],
                                        ['style' => 'text-decoration: none; color: var(--gray-900); font-size: 14px;']
                                    ) ?>
                                </td>

                                <td class="py-0 text-truncate align-middle" style="min-width: 150px; max-width: 150px;">
                                    <strong class=" fs-sm" ><?= h($ticket->requester->name) ?></strong>
                                    <span class="text-muted fs-sm" >
                                        (<?= h($ticket->requester->email) ?>)
                                    </span>
                                </td>

                                <td class="py-1 align-middle" style="max-width: 150px;">
                                    <?php
                                    $isLocked = in_array($ticket->status, \App\Constants\TicketConstants::RESOLVED_STATUSES, true);
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
                                    <td class="py-1 align-middle lh-1 fs-sm" >
                                        <?= $ticket->resolved_at ? $this->TimeHuman->short($ticket->resolved_at) : '-' ?>
                                    </td>
                                <?php endif; ?>

                                <td class="py-1 align-middle lh-1  fs-sm" >
                                    <?= $this->TimeHuman->short($ticket->created) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <nav aria-label="Paginación">
                <?= $this->element('pagination') ?>
            </nav>

        <?php else: ?>
            <div class="table-container">
                <div style="padding: 40px; text-align: center; color: var(--gray-600);">
                    <p style="font-size: 18px;">No hay tickets en esta vista</p>
                </div>
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
