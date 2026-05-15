<?php
/**
 * @var \App\View\AppView $this
 * @var \Cake\Datasource\Paging\PaginatedInterface<\App\Model\Entity\User> $users
 */
$this->assign('title', 'Usuarios');
$this->assign('active_workspace', 'users');

$roleLabels = [
    'admin' => 'Administrador',
    'asesor_tic' => 'Asesor TIC',
    'external' => 'Externo',
];
?>

<header class="app-page-header">
    <nav class="app-breadcrumb" aria-label="breadcrumb">
        <i class="bi bi-grid-1x2"></i>
        <span>Workspace</span>
        <i class="bi bi-chevron-right separator"></i>
        <span class="current">Usuarios</span>
    </nav>

    <div class="app-page-header-row">
        <div class="app-page-header-text">
            <h1 class="app-page-title">Usuarios</h1>
            <div class="app-page-stats">
                <span class="stat-inline">
                    <span class="dot" style="background: var(--admin-green);"></span>
                    <span class="value emphasis"><?= $this->Paginator->counter('{{count}}') ?: $users->count() ?></span>
                    <span class="label">en total</span>
                </span>
            </div>
        </div>
        <div class="app-page-actions">
            <?= $this->Html->link(
                '<i class="bi bi-person-plus"></i> Nuevo usuario',
                ['action' => 'addUser'],
                ['class' => 'btn-brand-primary', 'escape' => false]
            ) ?>
        </div>
    </div>
</header>

<?php if ($users->count() > 0) : ?>
    <div class="app-table-card">
        <div style="overflow-x: auto;">
            <table class="app-table">
                <thead>
                    <tr>
                        <th>Usuario</th>
                        <th>Email</th>
                        <th>Rol</th>
                        <th>Estado</th>
                        <th>Registro</th>
                        <th style="width: 1%; text-align: right;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user) : ?>
                        <tr>
                            <td>
                                <div class="user-cell">
                                    <?= $this->User->profileImageTag($user, ['width' => '32', 'height' => '32']) ?>
                                    <span class="user-cell-name"><?= h($user->name) ?></span>
                                </div>
                            </td>
                            <td style="color: var(--gray-600);"><?= h($user->email) ?></td>
                            <td>
                                <span class="role-badge <?= h($user->role) ?>">
                                    <?= h($roleLabels[$user->role] ?? $user->role) ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-dot-pill <?= $user->is_active ? 'active' : 'inactive' ?>">
                                    <?= $user->is_active ? 'Activo' : 'Inactivo' ?>
                                </span>
                            </td>
                            <td class="mono" style="color: var(--gray-500); font-size: 12px;">
                                <?= $user->created ? $user->created->format('d/m/Y') : '—' ?>
                            </td>
                            <td style="text-align: right;">
                                <div class="action-buttons">
                                    <?= $this->Html->link(
                                        '<i class="bi bi-pencil"></i>',
                                        ['action' => 'editUser', $user->id],
                                        ['class' => 'app-icon-btn', 'data-tip' => 'Editar', 'escape' => false]
                                    ) ?>
                                    <?php if ($user->is_active) : ?>
                                        <?= $this->Form->postLink(
                                            '<i class="bi bi-person-x"></i>',
                                            ['action' => 'deactivateUser', $user->id],
                                            [
                                                'class' => 'app-icon-btn danger',
                                                'data-tip' => 'Desactivar',
                                                'confirm' => '¿Desactivar a ' . $user->name . '?',
                                                'escape' => false,
                                            ]
                                        ) ?>
                                    <?php else : ?>
                                        <?= $this->Form->postLink(
                                            '<i class="bi bi-person-check"></i>',
                                            ['action' => 'activateUser', $user->id],
                                            [
                                                'class' => 'app-icon-btn success',
                                                'data-tip' => 'Activar',
                                                'escape' => false,
                                            ]
                                        ) ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="app-card-footer between">
            <span style="font-size: 12px; color: var(--gray-500);">
                <?= $this->Paginator->counter(__('Mostrando {{start}}–{{end}} de {{count}}')) ?>
            </span>
            <?= $this->element('pagination') ?>
        </div>
    </div>
<?php else : ?>
    <?= $this->element('empty_state', [
        'icon'    => 'people',
        'tone'    => 'neutral',
        'title'   => 'No hay usuarios registrados',
        'message' => 'Crea el primer usuario para empezar a operar el sistema.',
        'action'  => $this->Html->link(
            '<i class="bi bi-person-plus"></i> Crear usuario',
            ['action' => 'addUser'],
            ['class' => 'btn-brand-primary btn-brand-sm', 'escape' => false]
        ),
    ]) ?>
<?php endif; ?>
