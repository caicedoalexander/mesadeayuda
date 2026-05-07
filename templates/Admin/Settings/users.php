<?php
/**
 * @var \App\View\AppView $this
 * @var array $settings
 */
$this->assign('title', 'Usuarios');
?>

<style>
:root {
    --admin-green: #00A85E;
    --admin-orange: #CD6A15;
    --gray-50: #F9FAFB;
    --gray-100: #F3F4F6;
    --gray-200: #E5E7EB;
    --gray-300: #D1D5DB;
    --gray-600: #4B5563;
    --gray-700: #374151;
    --gray-900: #111827;
    --radius-lg: 12px;
    --radius-md: 8px;
    --radius-sm: 6px;
    --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
    --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

.admin-users-page {
    padding: 2rem;
    max-width: 1400px;
    margin: 0 auto;
    animation: fadeIn 0.4s ease-out;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Header Section */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 2rem;
    gap: 2rem;
}

.header-content {
    display: flex;
    gap: 1rem;
    align-items: center;
}

.header-icon {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, #E6F7F0 0%, #CCF0E1 100%);
    border: 2px solid var(--admin-green);
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.header-icon i {
    font-size: 24px;
    color: var(--admin-green);
}

.header-text h3 {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--gray-900);
    margin: 0;
    line-height: 1.2;
}

.header-text p {
    font-size: 0.95rem;
    color: var(--gray-600);
    margin: 0.25rem 0 0 0;
}

.btn-add-user {
    background: linear-gradient(135deg, var(--admin-green) 0%, #00c46e 100%);
    color: white;
    border: none;
    padding: 0.75rem 1.5rem;
    border-radius: var(--radius-md);
    font-weight: 600;
    font-size: 0.95rem;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    box-shadow: 0 4px 12px rgba(0, 168, 94, 0.25);
    transition: var(--transition);
}

.btn-add-user:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0, 168, 94, 0.35);
    color: white;
}

.btn-add-user i {
    font-size: 1.1rem;
}

/* Table Card */
.table-card {
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-md);
    overflow: hidden;
}

.modern-table {
    width: 100%;
    margin: 0;
    border-collapse: separate;
    border-spacing: 0;
}

.modern-table thead {
    background: linear-gradient(135deg, var(--gray-50) 0%, var(--gray-100) 100%);
}

.modern-table thead th {
    padding: 1rem 1.25rem;
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--gray-700);
    border-bottom: 2px solid var(--gray-200);
    text-align: left;
}

.modern-table tbody tr {
    transition: var(--transition);
    border-bottom: 1px solid var(--gray-100);
}

.modern-table tbody tr:hover {
    background: var(--gray-50);
}

.modern-table tbody tr:last-child {
    border-bottom: none;
}

.modern-table tbody td {
    padding: 1.25rem;
    vertical-align: middle;
    font-size: 0.95rem;
    color: var(--gray-700);
}

/* User Cell */
.user-cell {
    display: flex;
    gap: 0.875rem;
    align-items: center;
}

.user-cell img {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid var(--gray-200);
    transition: var(--transition);
}

.modern-table tbody tr:hover .user-cell img {
    border-color: var(--admin-green);
    transform: scale(1.05);
}

.user-cell strong {
    font-weight: 600;
    color: var(--gray-900);
}

/* Role Badge */
.role-badge {
    display: inline-block;
    padding: 0.375rem 0.75rem;
    border-radius: var(--radius-sm);
    font-size: 0.85rem;
    font-weight: 600;
    background: var(--gray-100);
    color: var(--gray-700);
    border: 1px solid var(--gray-200);
}

.role-badge.admin {
    background: linear-gradient(135deg, #FEF3EC 0%, #FCE7D9 100%);
    color: var(--admin-orange);
    border-color: var(--admin-orange);
}

.role-badge.agent,
.role-badge.servicio_cliente {
    background: linear-gradient(135deg, #E6F7F0 0%, #CCF0E1 100%);
    color: var(--admin-green);
    border-color: var(--admin-green);
}

/* Status Badge */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.375rem 0.75rem;
    border-radius: var(--radius-sm);
    font-size: 0.85rem;
    font-weight: 600;
}

.status-badge::before {
    content: '';
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
}

.status-badge.active {
    background: linear-gradient(135deg, #E6F7F0 0%, #CCF0E1 100%);
    color: var(--admin-green);
    border: 1px solid var(--admin-green);
}

.status-badge.active::before {
    background: var(--admin-green);
    box-shadow: 0 0 8px rgba(0, 168, 94, 0.6);
}

.status-badge.inactive {
    background: var(--gray-100);
    color: var(--gray-600);
    border: 1px solid var(--gray-300);
}

.status-badge.inactive::before {
    background: var(--gray-400);
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 0.5rem;
}

.btn-action {
    width: 36px;
    height: 36px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: var(--radius-sm);
    border: 1.5px solid;
    background: white;
    transition: var(--transition);
    cursor: pointer;
    font-size: 1rem;
}

.btn-action.edit {
    color: var(--admin-orange);
    border-color: var(--admin-orange);
}

.btn-action.edit:hover {
    background: var(--admin-orange);
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(205, 106, 21, 0.3);
}

.btn-action.deactivate {
    color: #dc3545;
    border-color: #dc3545;
}

.btn-action.deactivate:hover {
    background: #dc3545;
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
}

.btn-action.activate {
    color: var(--admin-green);
    border-color: var(--admin-green);
}

.btn-action.activate:hover {
    background: var(--admin-green);
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 168, 94, 0.3);
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: var(--gray-600);
    font-size: 1rem;
}

.empty-state i {
    font-size: 3rem;
    color: var(--gray-300);
    margin-bottom: 1rem;
    display: block;
}

/* Pagination */
.pagination-wrapper {
    margin-top: 2rem;
}

/* Responsive */
@media (max-width: 1200px) {
    .modern-table {
        font-size: 0.9rem;
    }

    .modern-table tbody td {
        padding: 1rem;
    }
}

@media (max-width: 768px) {
    .admin-users-page {
        padding: 1rem;
    }

    .page-header {
        flex-direction: column;
        gap: 1rem;
    }

    .header-content {
        width: 100%;
    }

    .btn-add-user {
        width: 100%;
        justify-content: center;
    }

    .table-card {
        overflow-x: auto;
    }

    .modern-table {
        min-width: 900px;
    }
}

/* Custom Scrollbar for table overflow */
.table-card::-webkit-scrollbar {
    height: 8px;
}

.table-card::-webkit-scrollbar-track {
    background: var(--gray-100);
    border-radius: var(--radius-sm);
}

.table-card::-webkit-scrollbar-thumb {
    background: rgba(0, 168, 94, 0.3);
    border-radius: var(--radius-sm);
}

.table-card::-webkit-scrollbar-thumb:hover {
    background: rgba(0, 168, 94, 0.5);
}
</style>

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
                ['class' => 'btn-add-user', 'escapeTitle' => false]
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
                <?php if (!empty($users)): ?>
                    <?php foreach ($users as $user): ?>
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
                                    'agent' => 'Agente',
                                    'servicio_cliente' => 'Servicio al Cliente',
                                    'requester' => 'Solicitante'
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
                            <td><?= $user->created->format('d/m/Y') ?></td>
                            <td>
                                <div class="action-buttons">
                                    <?= $this->Html->link(
                                        '<i class="bi bi-pencil"></i>',
                                        ['action' => 'editUser', $user->id],
                                        ['class' => 'btn-action edit', 'title' => 'Editar', 'escape' => false]
                                    ) ?>
                                    <?php if ($user->is_active): ?>
                                        <?= $this->Form->postLink(
                                            '<i class="bi bi-person-x"></i>',
                                            ['action' => 'deactivateUser', $user->id],
                                            [
                                                'class' => 'btn-action deactivate',
                                                'title' => 'Desactivar',
                                                'confirm' => '¿Desactivar a ' . $user->name . '?',
                                                'escape' => false
                                            ]
                                        ) ?>
                                    <?php else: ?>
                                        <?= $this->Form->postLink(
                                            '<i class="bi bi-person-check"></i>',
                                            ['action' => 'activateUser', $user->id],
                                            [
                                                'class' => 'btn-action activate',
                                                'title' => 'Activar',
                                                'escape' => false
                                            ]
                                        ) ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="empty-state">
                            <i class="bi bi-people"></i>
                            <div>No hay usuarios registrados.</div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($users->count() > 0): ?>
        <div class="pagination-wrapper">
            <?= $this->element('pagination') ?>
        </div>
    <?php endif; ?>
</div>
