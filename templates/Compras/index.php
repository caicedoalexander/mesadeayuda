<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Compra> $compras
 */
$this->assign('title', 'Compras - Gestión');

// Get user info for sidebar
$user = $this->getRequest()->getAttribute('identity');
$userRole = $user ? $user->get('role') : null;
$userId = $user ? $user->get('id') : null;
?>

<!-- Load CSS and JS -->
<?= $this->Html->css('bulk-actions') ?>
<?= $this->Html->script('bulk-actions-module') ?>

<div class="d-flex">
    <?= $this->cell('ComprasSidebar::display', [$view, $userRole, $userId]) ?>
</div>

<div class="pt-3 pb-1 ps-5 pe-4 w-100 d-flex flex-column">
    <!-- Page Header -->
    <div class="page-header">
        <div class="header-icon">
            <i class="bi bi-cart-check"></i>
        </div>
        <div class="header-text">
            <h3>
                <?php
                $titles = [
                    'sin_asignar' => 'Compras sin asignar',
                    'todos_sin_resolver' => 'Compras sin resolver',
                    'mis_compras' => 'Mis compras',
                    'nuevos' => 'Compras nuevas',
                    'en_revision' => 'En revisión',
                    'aprobados' => 'Aprobadas',
                    'en_proceso' => 'En proceso',
                    'completados' => 'Completadas',
                    'rechazados' => 'Rechazadas',
                    'convertidos' => 'Convertidas',
                    'vencidos_sla' => 'SLA Vencidos',
                ];
                echo $titles[$view] ?? 'Compras';
                ?>
            </h3>
        </div>
    </div>

    <div class="d-flex align-items-center mb-1 gap-2">
        <!-- Search Bar -->
        <?= $this->element('shared/search_bar', [
            'searchValue' => $this->request->getQuery('search') ?? '',
            'placeholder' => 'Buscar compras...',
            'entityType' => 'compra',
            'view' => $view
        ]) ?>

        <!-- Bulk Actions Bar -->
        <?= $this->element('shared/bulk_actions_bar', [
            'entityType' => 'compra',
            'showTagAction' => false  // Compras no tiene tags
        ]) ?>
    </div>

    <div id="entity-list-content" class="d-flex flex-column flex-grow-1" style="min-height: 0;">
        <div class="mb-2 fs-6 d-flex align-items-center">
            <small class="me-1"> <?= $compras->count() ?> Compras </small>
            <small class="m-0 text-muted">(<?= $this->Paginator->counter(__('Pagina {{page}} de {{pages}}')) ?>)</small>
        </div>

        <?php if ($compras->count() > 0): ?>
            <div class="table-responsive table-scroll mb-auto">
                <table class="table table-hover">
                    <thead class="bg-white" style="position: sticky; top: 0; z-index: 10;">
                        <tr>
                            <th class="w-fit pe-4 align-middle" style="width:36px">
                                <input type="checkbox" id="checkAll" class="form-check-input border border-dark rounded" />
                            </th>
                            <th class="w-fit fw-semibold align-middle" style="font-size: 14px;">Estado</th>
                            <th class="w-fit fw-semibold align-middle" style="font-size: 14px;">Asunto</th>
                            <th class="w-fit fw-semibold align-middle" style="font-size: 14px;">Solicitante</th>
                            <th class="w-fit fw-semibold align-middle" style="font-size: 14px;">Asignado a</th>
                            <th class="w-fit fw-semibold align-middle" style="font-size: 14px;">SLA</th>
                            <?php if ($view === 'completados'): ?>
                                <th class="w-fit fw-semibold align-middle" style="font-size: 14px;">
                                    <?= $this->Paginator->sort('resolved_at', 'Completado') ?>
                                </th>
                            <?php endif; ?>
                            <th class="w-fit fw-semibold align-middle" style="font-size: 14px;">
                                <?= $this->Paginator->sort('created', 'Solicitado') ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($compras as $compra): ?>
                            <?php
                            // Get SLA status
                            $slaStatus = $this->Sla->getSlaDisplayStatus(
                                $compra->resolution_sla_due ?? $compra->sla_due_date,
                                $compra->resolved_at,
                                $compra->created,
                                $compra->status,
                                ['completado', 'rechazado', 'convertido']
                            );
                            $rowClass = $slaStatus['status'] === 'breached' ? 'table-danger' : '';
                            ?>
                            <tr class="<?= $rowClass ?>">
                                <td class="py-0 align-middle">
                                    <input type="checkbox" class="form-check-input row-check rounded border border-dark"
                                           value="<?= (int)$compra->id ?>" />
                                </td>

                                <td class="py-0 align-middle" style="width: 120px; font-size: 14px;">
                                    <?= $this->Status->statusBadge($compra->status, 'compra') ?>
                                </td>

                                <td class="py-0 fw-light align-middle text-truncate"
                                    style="min-width: 300px; max-width: 300px;">
                                    <?= $this->Html->link(
                                        h($compra->subject),
                                        $this->Compras->getViewUrl($compra),
                                        ['style' => 'text-decoration: none; color: var(--gray-900); font-size: 14px;']
                                    ) ?>
                                </td>

                                <td class="py-0 text-truncate align-middle" style="min-width: 150px; max-width: 150px;">
                                    <strong style="font-size: 14px;"><?= h($compra->requester->name ?? 'N/A') ?></strong>
                                    <?php if ($compra->requester): ?>
                                        <span class="text-muted" style="font-size: 14px;">
                                            (<?= h($compra->requester->email) ?>)
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td class="py-1 align-middle" style="max-width: 150px;">
                                    <?php
                                    $isLocked = in_array($compra->status, ['completado', 'rechazado', 'convertido']);
                                    $isDisabled = $isAssignmentDisabled || $isLocked;
                                    ?>
                                    <?= $this->Form->create(null, ['url' => ['action' => 'assign', $compra->id], 'class' => 'table-assign-form']) ?>
                                    <?= $this->Form->select('assignee_id', $comprasUsers, [
                                        'value' => $compra->assignee_id,
                                        'empty' => 'Sin asignar',
                                        'class' => 'table-agent-select form-select form-select-sm',
                                        'disabled' => $isDisabled,
                                        'data-compra-id' => $compra->id
                                    ]) ?>
                                    <?= $this->Form->end() ?>
                                </td>

                                <td class="py-0 align-middle text-center">
                                    <?= $this->Sla->slaIcon($slaStatus) ?>
                                </td>

                                <?php if ($view === 'completados'): ?>
                                    <td class="py-1 align-middle lh-1" style="font-size: 14px;">
                                        <?= $compra->resolved_at ? $this->TimeHuman->short($compra->resolved_at) : '-' ?>
                                    </td>
                                <?php endif; ?>

                                <td class="py-1 align-middle lh-1" style="font-size: 14px;">
                                    <?= $this->TimeHuman->short($compra->created) ?>
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
                    <p style="font-size: 18px;">No hay compras en esta vista</p>
                </div>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- Modales para acciones rápidas -->
<?= $this->element('shared/bulk_modals', [
    'entityType' => 'compra',
    'users' => $comprasUsers ?? [],
    'showTagModal' => false
]) ?>

<?= $this->Html->script('ajax-refresh') ?>
<script>
    // Inicializar bulk actions module
    initBulkActions('compra');

    // Inicializar AJAX refresh (solo manual, sin auto-refresh)
    AjaxRefresh.init({
        entityType: 'compra',
        autoRefreshSeconds: 0
    });

    // Spinner: Mostrar en carga inicial (primera vez en la sesión)
    <?php if ($this->request->getSession()->check('show_loading_spinner')): ?>
        LoadingSpinner.showFor(800, 'Cargando compras...');
        <?php $this->request->getSession()->delete('show_loading_spinner'); ?>
    <?php endif; ?>
</script>
