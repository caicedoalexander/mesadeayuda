<?php
/**
 * @var \App\View\AppView $this
 * @var array $settings
 */
$this->assign('title', 'Usuarios');
?>

<?= $this->Html->css('admin/users', ['block' => 'css']) ?>

<div class="admin-users-page">
    <!-- Header -->
    <div class="page-header">
        <div class="header-content">
            <div class="header-icon">
                <i class="bi bi-people"></i>
            </div>
            <div class="header-text">
                <h3>Gestión de Usuarios</h3>
                <p>Administra los usuarios del sistema</p>
            </div>
        </div>
        <div>
            <?= $this->Html->link(
                '<i class="bi bi-person-add"></i> Nuevo Usuario',
                ['action' => 'addUser'],
                ['class' => 'btn-add-user', 'escapeTitle' => false],
            ) ?>
        </div>
    </div>

    <?= $this->Flash->render() ?>

    <!-- Table Card -->
    <div class="table-card">
        <table class="modern-table">
            <thead>
                <tr>
                    <th>Usuario</th>
                    <th>Email</th>
                    <th>Rol</th>
                    <th>Estado</th>
                    <th>Registro</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($users)) : ?>
                    <?php foreach ($users as $user) : ?>
                        <tr>
                            <td>
                                <div class="user-cell">
                                    <?= $this->User->profileImageTag($user, ['width' => '48', 'height' => '48', 'class' => '']) ?>
                                    <strong><?= h($user->name) ?></strong>
                                </div>
                            </td>
                            <td><?= h($user->email) ?></td>
                            <td>
                                <?php
                                $roles = [
                                    'admin' => 'Administrador',
                                    'asesor_tic' => 'Asesor TIC',
                                    'external' => 'Externo',
                                ];
                                $roleKey = $user->role;
                                $roleName = $roles[$roleKey] ?? $roleKey;
                                ?>
                                <span class="role-badge <?= h($roleKey) ?>">
                                    <?= h($roleName) ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge <?= $user->is_active ? 'active' : 'inactive' ?>">
                                    <?= $user->is_active ? 'Activo' : 'Inactivo' ?>
                                </span>
                            </td>
                            <td><?= $user->created ? $user->created->format('d/m/Y') : '—' ?></td>
                            <td>
                                <div class="action-buttons">
                                    <?= $this->Html->link(
                                        '<i class="bi bi-pencil"></i>',
                                        ['action' => 'editUser', $user->id],
                                        ['class' => 'btn-action edit', 'title' => 'Editar', 'escape' => false],
                                    ) ?>
                                    <?php if ($user->is_active) : ?>
                                        <?= $this->Form->postLink(
                                            '<i class="bi bi-person-x"></i>',
                                            ['action' => 'deactivateUser', $user->id],
                                            [
                                                'class' => 'btn-action deactivate',
                                                'title' => 'Desactivar',
                                                'confirm' => '¿Desactivar a ' . $user->name . '?',
                                                'escape' => false,
                                            ],
                                        ) ?>
                                    <?php else : ?>
                                        <?= $this->Form->postLink(
                                            '<i class="bi bi-person-check"></i>',
                                            ['action' => 'activateUser', $user->id],
                                            [
                                                'class' => 'btn-action activate',
                                                'title' => 'Activar',
                                                'escape' => false,
                                            ],
                                        ) ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="7">
                            <?= $this->element('empty_state', [
                                'inline' => true,
                                'icon'   => 'people',
                                'title'  => 'No hay usuarios registrados.',
                            ]) ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($users->count() > 0) : ?>
        <div class="pagination-wrapper">
            <?= $this->element('pagination') ?>
        </div>
    <?php endif; ?>
</div>
