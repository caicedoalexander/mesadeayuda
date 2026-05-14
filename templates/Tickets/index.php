<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Ticket> $tickets
 * @var \App\View\Helper\StatusHelper $Status
 * @var \App\View\Helper\TimeHumanHelper $TimeHuman
 * @var string $view
 * @var array $filters
 * @var array $agents
 * @var bool $isAssignmentDisabled
 */

use App\Constants\TicketConstants;

$this->assign('title', 'Tickets');

$user = $this->getRequest()->getAttribute('identity');
$userRole = $user ? $user->get('role') : null;
$userId = $user ? $user->get('id') : null;

$titles = [
    'sin_asignar'        => 'Tickets sin asignar',
    'todos_sin_resolver' => 'Tickets sin resolver',
    'nuevos'             => 'Tickets nuevos',
    'abiertos'           => 'Tickets abiertos',
    'pendientes'         => 'Tickets pendientes',
    'resueltos'          => 'Tickets resueltos',
    'mis_tickets'        => 'Mis tickets',
];

// Visible-page stats (paginated slice). Total comes from Paginator counter.
$pageHighPriority = 0;
$pageUnassigned   = 0;
$pageResolved     = 0;
foreach ($tickets as $t) {
    if (in_array($t->priority, [TicketConstants::PRIORITY_ALTA, TicketConstants::PRIORITY_URGENTE], true)) {
        $pageHighPriority++;
    }
    if (!$t->hasAssignee()) {
        $pageUnassigned++;
    }
    if ($t->isResolved()) {
        $pageResolved++;
    }
}

$hasActiveFilters = !empty($filters['filterPriority'])
    || !empty($filters['filterAssignee'])
    || !empty($filters['filterDateFrom'])
    || !empty($filters['filterDateTo']);
$activeFiltersCount = (int)!empty($filters['filterPriority'])
    + (int)!empty($filters['filterAssignee'])
    + (int)!empty($filters['filterDateFrom'])
    + (int)!empty($filters['filterDateTo']);
?>

<?= $this->Html->css('bulk-actions') ?>
<?= $this->Html->script('bulk-actions-module') ?>

<?= $this->cell('TicketsSidebar::display', [$view, $userRole, $userId]) ?>

<section class="tickets-content-area flex-grow-1 d-flex flex-column">

    <!-- Header: breadcrumb + title + inline stats + primary actions -->
    <header class="tickets-header">
        <nav class="tickets-breadcrumb" aria-label="breadcrumb">
            <i class="bi bi-ticket"></i>
            <span>Tickets</span>
            <i class="bi bi-chevron-right separator"></i>
            <span class="current"><?= h($titles[$view] ?? 'Tickets') ?></span>
        </nav>

        <div class="tickets-header-row">
            <div class="tickets-header-text">
                <h1 class="tickets-title"><?= h($titles[$view] ?? 'Tickets') ?></h1>

                <div class="tickets-stats">
                    <span class="stat-inline">
                        <span class="dot" style="background: var(--gray-400);"></span>
                        <span class="value"><?= $this->Paginator->counter('{{count}}') ?: $tickets->count() ?></span>
                        <span class="label">en esta vista</span>
                    </span>
                    <?php if ($pageHighPriority > 0): ?>
                    <span class="stat-inline emphasis">
                        <span class="dot" style="background: var(--danger-color);"></span>
                        <span class="value"><?= $pageHighPriority ?></span>
                        <span class="label">alta prioridad</span>
                    </span>
                    <?php endif; ?>
                    <?php if ($pageUnassigned > 0): ?>
                    <span class="stat-inline emphasis">
                        <span class="dot" style="background: var(--admin-orange);"></span>
                        <span class="value"><?= $pageUnassigned ?></span>
                        <span class="label">sin asignar</span>
                    </span>
                    <?php endif; ?>
                    <?php if ($view === 'resueltos' && $pageResolved > 0): ?>
                    <span class="stat-inline">
                        <span class="dot" style="background: var(--admin-green);"></span>
                        <span class="value"><?= $pageResolved ?></span>
                        <span class="label">resueltos</span>
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tickets-header-actions">
                <button type="button" id="btn-refresh-list" class="btn-brand-secondary" title="Actualizar">
                    <i class="bi bi-arrow-clockwise"></i>
                    <span class="d-none d-md-inline">Actualizar</span>
                </button>
            </div>
        </div>
    </header>

    <!-- Filter bar: search + Filtros + Ordenar + bulk actions -->
    <div class="tickets-toolbar">
        <?= $this->element('tickets/search_bar', [
            'searchValue' => $filters['search'] ?? '',
            'placeholder' => 'Buscar por asunto, #ticket, solicitante…',
            'view' => $view,
        ]) ?>

        <button type="button"
                class="btn-brand-secondary toolbar-filter-btn<?= $hasActiveFilters ? ' has-filters' : '' ?>"
                data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-funnel"></i>
            Filtros
            <?php if ($activeFiltersCount > 0): ?>
                <span class="filter-badge mono"><?= $activeFiltersCount ?></span>
            <?php endif; ?>
            <i class="bi bi-chevron-down chev"></i>
        </button>

        <?= $this->element('tickets/bulk_actions_bar', [
            'showTagAction' => true,
        ]) ?>
    </div>

    <!-- Table -->
    <div id="entity-list-content" class="d-flex flex-column flex-grow-1" style="min-height: 0;">
        <?php if ($tickets->count() > 0): ?>
            <div class="tickets-table-card">
                <div class="table-responsive table-scroll mb-0">
                    <table class="table table-hover mb-0 tickets-table">
                        <thead style="position: sticky; top: 0; z-index: 10;">
                            <tr>
                                <th style="width: 36px;">
                                    <input type="checkbox" id="checkAll" class="form-check-input" />
                                </th>
                                <th style="width: 80px;">#</th>
                                <th style="width: 110px;">Estado</th>
                                <th>Asunto · Solicitante</th>
                                <th style="width: 190px;">Asignado a</th>
                                <?php if ($view === 'resueltos'): ?>
                                    <th style="width: 110px;"><?= $this->Paginator->sort('resolved_at', 'Resuelto') ?></th>
                                <?php endif; ?>
                                <th style="width: 110px;"><?= $this->Paginator->sort('created', 'Solicitado') ?></th>
                                <th style="width: 96px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tickets as $ticket): ?>
                                <?php
                                $isHighPriority = in_array(
                                    $ticket->priority,
                                    [TicketConstants::PRIORITY_ALTA, TicketConstants::PRIORITY_URGENTE],
                                    true
                                );
                                $isLocked = $ticket->isLocked();
                                $isDisabled = $isAssignmentDisabled || $isLocked;
                                ?>
                                <tr class="<?= $isHighPriority ? 'row-critical' : '' ?>">
                                    <td>
                                        <input type="checkbox" class="form-check-input row-check"
                                               value="<?= (int)$ticket->id ?>" />
                                    </td>

                                    <td>
                                        <span class="mono ticket-id">#<?= h($ticket->ticket_number ?? $ticket->id) ?></span>
                                    </td>

                                    <td>
                                        <?= $this->Status->statusBadge($ticket->status) ?>
                                    </td>

                                    <td class="cell-subject">
                                        <div class="subject-row">
                                            <?= $this->Html->link(
                                                h($ticket->subject),
                                                ['action' => 'view', $ticket->id],
                                                ['class' => 'ticket-subject-link', 'escape' => false]
                                            ) ?>
                                            <?php if ($isHighPriority): ?>
                                                <span class="priority-flag alta">
                                                    <span class="glyph">↑</span><?= h($this->Status->priorityLabel($ticket->priority)) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="meta-row">
                                            <span class="requester-name"><?= h($ticket->requester->name ?? '—') ?></span>
                                            <?php if (!empty($ticket->requester->email)): ?>
                                                <span class="sep">·</span>
                                                <span class="requester-email"><?= h($ticket->requester->email) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <td>
                                        <?= $this->Form->create(null, [
                                            'url' => ['action' => 'assign', $ticket->id],
                                            'class' => 'table-assign-form',
                                        ]) ?>
                                        <?= $this->Form->select('assignee_id', $agents, [
                                            'value'          => $ticket->assignee_id,
                                            'empty'          => 'Sin asignar',
                                            'class'          => 'table-agent-select form-select form-select-sm',
                                            'disabled'       => $isDisabled,
                                            'data-ticket-id' => $ticket->id,
                                        ]) ?>
                                        <?= $this->Form->end() ?>
                                    </td>

                                    <?php if ($view === 'resueltos'): ?>
                                        <td class="mono cell-time">
                                            <?= $ticket->resolved_at ? $this->TimeHuman->short($ticket->resolved_at) : '—' ?>
                                        </td>
                                    <?php endif; ?>

                                    <td class="mono cell-time">
                                        <?= $this->TimeHuman->short($ticket->created) ?>
                                    </td>

                                    <td class="cell-actions">
                                        <?= $this->Html->link(
                                            'Abrir <i class="bi bi-chevron-right"></i>',
                                            ['action' => 'view', $ticket->id],
                                            ['class' => 'btn-row-open', 'escape' => false]
                                        ) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Table footer: pagination summary + density toggle -->
                <div class="tickets-table-footer">
                    <span class="footer-summary">
                        <?= $this->Paginator->counter('Mostrando <strong>{{start}}–{{end}}</strong> de <strong>{{count}}</strong>') ?>
                    </span>
                    <div class="footer-spacer"></div>
                    <nav aria-label="Paginación" class="footer-pagination">
                        <?= $this->element('pagination') ?>
                    </nav>
                </div>
            </div>

        <?php else: ?>
            <div class="tickets-empty-state">
                <i class="bi bi-ticket-detailed"></i>
                <p>No hay tickets en esta vista</p>
            </div>
        <?php endif; ?>
    </div>
</section>

<?= $this->element('tickets/bulk_modals', [
    'agents'       => $agents,
    'tags'         => $tags,
    'showTagModal' => true,
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
