<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Organization> $organizations
 */
$this->assign('title', 'Gestión de Organizaciones');
?>

<style>
:root {
    --admin-green: #00A85E;
    --admin-orange: #CD6A15;
    --gray-50: #F9FAFB;
    --gray-100: #F3F4F6;
    --gray-200: #E5E7EB;
    --gray-300: #D1D5DB;
    --gray-400: #9CA3AF;
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

.admin-organizations-page {
    padding: 2rem;
    max-width: 1200px;
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

.btn-add-org {
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

.btn-add-org:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0, 168, 94, 0.35);
    color: white;
}

.btn-add-org i {
    font-size: 1.1rem;
}

/* Table Card */
.table-card {
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-md);
    overflow: hidden;
    margin-bottom: 1.5rem;
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

.modern-table thead th.text-center {
    text-align: center;
}

.modern-table thead th.text-end {
    text-align: right;
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

.modern-table tbody td.text-center {
    text-align: center;
}

.modern-table tbody td.text-end {
    text-align: right;
}

/* Organization Name */
.org-name {
    font-weight: 600;
    color: var(--gray-900);
    font-size: 1rem;
}

/* User Count Badge */
.count-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 28px;
    padding: 0 0.625rem;
    background: linear-gradient(135deg, #E6F7F0 0%, #CCF0E1 100%);
    color: var(--admin-green);
    border: 1px solid var(--admin-green);
    border-radius: var(--radius-sm);
    font-size: 0.875rem;
    font-weight: 700;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 0.5rem;
    justify-content: flex-end;
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

.btn-action.delete {
    color: #dc3545;
    border-color: #dc3545;
}

.btn-action.delete:hover {
    background: #dc3545;
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: var(--gray-600);
}

.empty-state-icon {
    font-size: 4rem;
    color: var(--gray-300);
    margin-bottom: 1rem;
}

.empty-state p {
    font-size: 1.1rem;
    margin: 1rem 0;
}

.btn-empty-state {
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
    margin-top: 1rem;
}

.btn-empty-state:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0, 168, 94, 0.35);
    color: white;
}

/* Back Button */
.btn-back {
    background: white;
    color: var(--gray-700);
    border: 2px solid var(--gray-300);
    padding: 0.75rem 1.5rem;
    border-radius: var(--radius-md);
    font-weight: 600;
    font-size: 0.95rem;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: var(--transition);
}

.btn-back:hover {
    background: var(--gray-100);
    border-color: var(--gray-400);
    color: var(--gray-900);
}

/* Pagination */
.pagination-wrapper {
    padding: 1.5rem;
    background: var(--gray-50);
    border-top: 1px solid var(--gray-200);
}

.pagination {
    display: flex;
    justify-content: center;
    gap: 0.5rem;
    list-style: none;
    margin: 0;
    padding: 0;
}

.pagination li a,
.pagination li span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 36px;
    padding: 0 0.75rem;
    border-radius: var(--radius-sm);
    font-size: 0.9rem;
    font-weight: 600;
    text-decoration: none;
    transition: var(--transition);
}

.pagination li a {
    color: var(--gray-700);
    background: white;
    border: 1px solid var(--gray-300);
}

.pagination li a:hover {
    background: var(--admin-green);
    color: white;
    border-color: var(--admin-green);
}

.pagination li.active span {
    background: var(--admin-green);
    color: white;
    border: 1px solid var(--admin-green);
}

.pagination li.disabled span {
    color: var(--gray-400);
    background: var(--gray-100);
    border: 1px solid var(--gray-200);
    cursor: not-allowed;
}

/* Responsive */
@media (max-width: 768px) {
    .admin-organizations-page {
        padding: 1rem;
    }

    .page-header {
        flex-direction: column;
        gap: 1rem;
    }

    .header-content {
        width: 100%;
    }

    .btn-add-org {
        width: 100%;
        justify-content: center;
    }

    .table-card {
        overflow-x: auto;
    }

    .modern-table {
        min-width: 600px;
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

<div class="admin-organizations-page">
    <!-- Header -->
    <div class="page-header">
        <div class="header-content">
            <div class="header-icon">
                <i class="bi bi-building"></i>
            </div>
            <div class="header-text">
                <h3>Organizaciones</h3>
            </div>
        </div>
        <div>
            <?= $this->Html->link(
                '<i class="bi bi-plus-lg"></i> Nueva Organización',
                ['controller' => 'Organizations', 'action' => 'add'],
                ['class' => 'btn-add-org', 'escapeTitle' => false]
            ) ?>
        </div>
    </div>

    <?= $this->Flash->render() ?>

    <!-- Table Card -->
    <div class="table-card">
        <?php if (count($organizations) === 0): ?>
            <div class="empty-state">
                <i class="bi bi-building empty-state-icon"></i>
                <p>No hay organizaciones registradas.</p>
                <?= $this->Html->link(
                    '<i class="bi bi-plus-lg"></i> Crear la primera organización',
                    ['controller' => 'Organizations', 'action' => 'add'],
                    ['class' => 'btn-empty-state', 'escape' => false]
                ) ?>
            </div>
        <?php else: ?>
            <table class="modern-table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th class="text-center">Usuarios</th>
                        <th>Creado</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($organizations as $organization): ?>
                        <tr>
                            <td>
                                <span class="org-name"><?= h($organization->name) ?></span>
                            </td>
                            <td class="text-center">
                                <span class="count-badge">
                                    <?= $organization->user_count ?>
                                </span>
                            </td>
                            <td><?= h($organization->created->format('d/m/Y')) ?></td>
                            <td class="text-end">
                                <div class="action-buttons">
                                    <?= $this->Html->link(
                                        '<i class="bi bi-pencil"></i>',
                                        ['controller' => 'Organizations', 'action' => 'edit', $organization->id],
                                        ['class' => 'btn-action edit', 'title' => 'Editar', 'escape' => false]
                                    ) ?>
                                    <?= $this->Form->postLink(
                                        '<i class="bi bi-trash"></i>',
                                        ['controller' => 'Organizations', 'action' => 'delete', $organization->id],
                                        [
                                            'confirm' => '¿Estás seguro de eliminar esta organización?',
                                            'class' => 'btn-action delete',
                                            'title' => 'Eliminar',
                                            'escape' => false
                                        ]
                                    ) ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($this->Paginator->hasPrev() || $this->Paginator->hasNext()): ?>
                <div class="pagination-wrapper">
                    <ul class="pagination">
                        <?= $this->Paginator->prev('‹ Anterior') ?>
                        <?= $this->Paginator->numbers() ?>
                        <?= $this->Paginator->next('Siguiente ›') ?>
                    </ul>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Back Button -->
    <div>
        <?= $this->Html->link(
            '<i class="bi bi-arrow-left"></i> Volver a Configuración',
            ['action' => 'index'],
            ['class' => 'btn-back', 'escape' => false]
        ) ?>
    </div>
</div>
