<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Pqr> $pqrs
 * @var \App\View\Helper\PqrsHelper $Pqrs
 * @var \App\View\Helper\TimeHumanHelper $TimeHuman
 */
$this->assign('title', 'PQRS - Gestión');

// Get user info for sidebar
$user = $this->getRequest()->getAttribute('identity');
$userRole = $user ? $user->get('role') : null;
$userId = $user ? $user->get('id') : null;
?>

<!-- Load CSS and JS -->
<?= $this->Html->css('bulk-actions') ?>
<?= $this->Html->script('bulk-actions-module') ?>

<div class="d-flex">
    <?= $this->cell('PqrsSidebar::display', [$view, $userRole, $userId]) ?>
</div>

<div class="pt-3 pb-1 ps-5 pe-4 w-100 d-flex flex-column">
    <!-- Page Header -->
    <div class="page-header">
        <div class="header-icon">
            <i class="bi bi-chat-square-text"></i>
        </div>
        <div class="header-text">
            <h3>
                <?php
                $titles = [
                    'sin_asignar' => 'PQRS sin asignar',
                    'todos_sin_resolver' => 'PQRS sin resolver',
                    'nuevas' => 'PQRS nuevas',
                    'en_revision' => 'PQRS en revisión',
                    'en_proceso' => 'PQRS en proceso',
                    'resueltas' => 'PQRS resueltas',
                    'cerradas' => 'PQRS cerradas',
                    'mis_pqrs' => 'Mis PQRS',
                ];
                echo $titles[$view] ?? 'PQRS';
                ?>
            </h3>
        </div>
    </div>

    <div class="d-flex align-items-center mb-1 gap-2">
        <!-- Search Bar -->
        <?= $this->element('shared/search_bar', [
            'searchValue' => $search ?? '',
            'placeholder' => 'Buscar PQRS...',
            'entityType' => 'pqrs',
            'view' => $view
        ]) ?>

        <!-- Bulk Actions Bar -->
        <?= $this->element('shared/bulk_actions_bar', [
            'entityType' => 'pqrs',
            'showTagAction' => false  // PQRS no tiene tags
        ]) ?>
    </div>

    <div id="entity-list-content" class="d-flex flex-column flex-grow-1" style="min-height: 0;">
        <div class="mb-1 fs-6 d-flex align-items-center">
            <small class="me-1"> <?= $pqrs->count() ?> PQRS </small>
            <small class="m-0 text-muted">(<?= $this->Paginator->counter(__('Pagina {{page}} de {{pages}}')) ?>)</small>
        </div>

        <?php if ($pqrs->count() > 0): ?>
            <div class="table-responsive table-scroll mb-auto">
                <table class="table table-hover">
                    <thead class="bg-white" style="position: sticky; top: 0; z-index: 5;">
                        <tr>
                            <th class="w-fit pe-4 align-middle" style="width:36px">
                                <input type="checkbox" id="checkAll" class="form-check-input border border-dark rounded" />
                            </th>
                            <th class="w-fit fw-semibold align-middle" style="font-size: 14px;">Tipo</th>
                            <th class="w-fit fw-semibold align-middle" style="font-size: 14px;">Estado</th>
                            <th class="w-fit fw-semibold align-middle" style="font-size: 14px;">Asunto</th>
                            <th class="w-fit fw-semibold align-middle" style="font-size: 14px;">Solicitante</th>
                            <th class="w-fit fw-semibold align-middle" style="font-size: 14px;">Asignado a</th>
                            <th class="w-fit fw-semibold align-middle" style="font-size: 14px;">SLA</th>
                            <?php if ($view === 'resueltas'): ?>
                                <th class="w-fit fw-semibold align-middle" style="font-size: 14px;">
                                    <?= $this->Paginator->sort('resolved_at', 'Resuelto') ?>
                                </th>
                            <?php endif; ?>
                            <th class="w-fit fw-semibold align-middle" style="font-size: 14px;">
                                <?= $this->Paginator->sort('created', 'Solicitado') ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pqrs as $item): ?>
                            <tr>
                                <td class="py-1 align-middle">
                                    <input type="checkbox" class="form-check-input row-check rounded border border-dark"
                                        value="<?= $item->id ?>" />
                                </td>

                                <td class="py-1 align-middle" style="font-size: 14px; width: 100px;">
                                    <?= $this->Status->typeBadge($item->type) ?>
                                </td>
                                <td class="py-1 align-middle" style="font-size: 14px; width: 80px;">
                                    <?= $this->Status->statusBadge($item->status, 'pqrs') ?>
                                </td>
                                <td class="py-1 align-middle text-truncate" style="min-width: 200px; max-width: 200px;">
                                    <?= $this->Html->link(
                                        h($item->subject),
                                        ['action' => 'view', $item->id],
                                        ['class' => 'text-decoration-none', 'style' => 'color: var(--gray-900); font-size: 14px;']
                                    ) ?>
                                </td>
                                <td class="py-1 align-middle small text-truncate" style="min-width: 150px; max-width: 150px;">
                                    <strong class=" " style="font-size: 14px;"><?= h($item->requester_email) ?></strong>
                                </td>
                                <td class="py-1 align-middle" style="max-width: 150px;">
                                    <?php $isLocked = in_array($item->status, ['resuelto', 'cerrado']); ?>
                                    <?= $this->Form->create(null, ['url' => ['action' => 'assign', $item->id], 'type' => 'post', 'class' => 'table-assign-form']) ?>
                                    <?= $this->Form->select('assignee_id', $users, [
                                        'value' => $item->assignee_id,
                                        'empty' => 'Sin asignar',
                                        'class' => 'select2 table-agent-select',
                                        'disabled' => $isLocked,
                                        'data-pqrs-id' => $item->id
                                    ]) ?>
                                    <?= $this->Form->end() ?>
                                </td>

                                <td class="py-0 align-middle text-center">
                                    <?php
                                    $slaStatus = $this->Sla->getSlaDisplayStatus(
                                        $item->resolution_sla_due,
                                        $item->resolved_at,
                                        $item->created,
                                        $item->status,
                                        ['completado', 'cerrado', 'resuelto']
                                    );
                                    ?>
                                    <?= $this->Sla->slaIcon($slaStatus) ?>
                                </td>

                                <?php if ($view === 'resueltas'): ?>
                                    <td class="py-1 align-middle small lh-1" style="font-size: 14px;">
                                        <?= $item->resolved_at ? $this->TimeHuman->short($item->resolved_at) : '-' ?>
                                    </td>
                                <?php endif; ?>
                                <td class="py-1 align-middle small lh-1" style="font-size: 14px;">
                                    <?= $this->TimeHuman->short($item->created) ?><br>
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
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i>
                No se encontraron PQRS con los criterios de búsqueda.
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modales para acciones rápidas -->
<?= $this->element('shared/bulk_modals', [
    'entityType' => 'pqrs',
    'agents' => $agents ?? [],
    'showTagModal' => false  // PQRS no tiene tags
]) ?>

<?= $this->Html->script('ajax-refresh') ?>
<script>
    // Inicializar bulk actions module
    initBulkActions('pqrs');

    // Inicializar AJAX refresh (solo manual, sin auto-refresh)
    AjaxRefresh.init({
        entityType: 'pqrs',
        autoRefreshSeconds: 0
    });
</script>
