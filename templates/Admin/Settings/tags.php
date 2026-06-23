<?php
/**
 * @var \App\View\AppView $this
 * @var array $settings
 */
$this->assign('title', 'Gestión de Etiquetas');
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

.tags-page {
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

.btn-add-tag {
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

.btn-add-tag:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0, 168, 94, 0.35);
    color: white;
}

.btn-add-tag i {
    font-size: 1.1rem;
}

/* Tags Grid */
.tags-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(225px, 1fr));
    gap: 1.5rem;
}

/* Tag Card */
.tag-card {
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-md);
    padding: 1.75rem;
    transition: var(--transition);
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
    position: relative;
    overflow: hidden;
}

.tag-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: var(--tag-color);
    box-shadow: 0 2px 8px var(--tag-color);
}

.tag-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-lg);
}

.tag-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
}

.tag-name {
    display: inline-block;
    padding: 0.5rem 1rem;
    border-radius: var(--radius-md);
    font-size: 0.95rem;
    font-weight: 700;
    color: white;
    background: var(--tag-color);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.tag-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 70px;
    padding: 0.375rem 0.75rem;
    background: var(--gray-100);
    color: var(--gray-700);
    border: 1px solid var(--gray-300);
    border-radius: var(--radius-sm);
    font-size: 0.85rem;
    font-weight: 600;
    white-space: nowrap;
}

.tag-actions {
    display: flex;
    gap: 0.625rem;
    justify-content: center;
    padding-top: 0.5rem;
    border-top: 1px solid var(--gray-100);
}

.btn-action {
    flex: 1;
    padding: 0.625rem 1rem;
    border-radius: var(--radius-md);
    font-weight: 600;
    font-size: 0.875rem;
    cursor: pointer;
    transition: var(--transition);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.375rem;
    text-decoration: none;
}

.btn-action.edit {
    background: white;
    color: var(--admin-orange);
    border: 1.5px solid var(--admin-orange);
}

.btn-action.edit:hover {
    background: var(--admin-orange);
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(205, 106, 21, 0.3);
}

.btn-action.delete {
    background: white;
    color: #dc3545;
    border: 1.5px solid #dc3545;
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
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-md);
}

.empty-state-icon {
    font-size: 4rem;
    color: var(--gray-300);
    margin-bottom: 1rem;
}

.empty-state h3 {
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--gray-900);
    margin-bottom: 0.75rem;
}

.empty-state p {
    font-size: 1rem;
    color: var(--gray-600);
    margin-bottom: 2rem;
}

.btn-empty-state {
    background: linear-gradient(135deg, var(--admin-green) 0%, #00c46e 100%);
    color: white;
    border: none;
    padding: 0.875rem 2rem;
    border-radius: var(--radius-md);
    font-weight: 600;
    font-size: 1rem;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    box-shadow: 0 4px 12px rgba(0, 168, 94, 0.25);
    transition: var(--transition);
}

.btn-empty-state:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0, 168, 94, 0.35);
    color: white;
}

/* Responsive */
@media (max-width: 768px) {
    .tags-page {
        padding: 1rem;
    }

    .page-header {
        flex-direction: column;
        gap: 1rem;
    }

    .header-content {
        width: 100%;
    }

    .btn-add-tag {
        width: 100%;
        justify-content: center;
    }

    .tags-grid {
        grid-template-columns: 1fr;
    }

    .tag-actions {
        flex-direction: column;
    }

    .btn-action {
        width: 100%;
    }
}
</style>

<div class="tags-page">
    <!-- Header -->
    <div class="page-header">
        <div class="header-content">
            <div class="header-icon">
                <i class="bi bi-tags"></i>
            </div>
            <div class="header-text">
                <h3>Gestión de Etiquetas</h3>
                <p>Administra las etiquetas para organizar tickets</p>
            </div>
        </div>
        <div>
            <?= $this->Html->link(
                '<i class="bi bi-tag"></i> Nueva Etiqueta',
                ['action' => 'addTag'],
                ['class' => 'btn-add-tag', 'escapeTitle' => false]
            ) ?>
        </div>
    </div>

    <?= $this->Flash->render() ?>

    <!-- Tags Grid -->
    <?php if (!empty($tags)): ?>
        <div class="tags-grid pb-3">
            <?php foreach ($tags as $tag): ?>
                <div class="tag-card" style="--tag-color: <?= h($tag->color) ?>">
                    <div class="tag-header">
                        <span class="tag-name" title="<?= h($tag->name) ?>">
                            <?= h($tag->name) ?>
                        </span>
                        <span class="tag-count">
                            <?= $tag->ticket_count ?? 0 ?> tickets
                        </span>
                    </div>

                    <div class="tag-actions">
                        <?= $this->Html->link(
                            '<i class="bi bi-pencil"></i> Editar',
                            ['action' => 'editTag', $tag->id],
                            ['class' => 'btn-action edit', 'escape' => false]
                        ) ?>
                        <?= $this->Form->postLink(
                            '<i class="bi bi-trash"></i> Eliminar',
                            ['action' => 'deleteTag', $tag->id],
                            [
                                'class' => 'btn-action delete',
                                'confirm' => '¿Estás seguro de eliminar la etiqueta "' . $tag->name . '"?',
                                'escape' => false
                            ]
                        ) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <i class="bi bi-tags empty-state-icon"></i>
            <h3>No hay etiquetas creadas</h3>
            <p>Las etiquetas te ayudan a organizar y categorizar tus tickets.</p>
            <?= $this->Html->link(
                '<i class="bi bi-plus-lg"></i> Crear primera etiqueta',
                ['action' => 'addTag'],
                ['class' => 'btn-empty-state', 'escape' => false]
            ) ?>
        </div>
    <?php endif; ?>
</div>
