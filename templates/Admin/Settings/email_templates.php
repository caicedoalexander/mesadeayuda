<?php
/**
 * @var \App\View\AppView $this
 * @var array $settings
 */
$this->assign('title', 'Plantillas de Email');
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

.email-templates-page {
    padding: 2rem;
    max-width: 1000px;
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
    align-items: center;
    gap: 1rem;
    margin-bottom: 2rem;
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

/* Templates List */
.templates-list {
    display: grid;
    gap: 1.5rem;
}

/* Template Card */
.template-card {
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-md);
    padding: 1.75rem;
    transition: var(--transition);
    position: relative;
    overflow: hidden;
}

.template-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(135deg, var(--admin-green) 0%, #00c46e 100%);
    box-shadow: 0 2px 8px rgba(0, 168, 94, 0.3);
}

.template-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-lg);
}

.template-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.25rem;
    padding-bottom: 1.25rem;
    border-bottom: 1px solid var(--gray-200);
}

.template-header h3 {
    margin: 0;
    color: var(--gray-900);
    font-size: 1.125rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.template-status {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.375rem 0.75rem;
    border-radius: var(--radius-sm);
    font-size: 0.8rem;
    font-weight: 600;
}

.template-status.active {
    background: linear-gradient(135deg, #E6F7F0 0%, #CCF0E1 100%);
    color: var(--admin-green);
    border: 1px solid var(--admin-green);
}

.template-status.inactive {
    background: var(--gray-100);
    color: var(--gray-600);
    border: 1px solid var(--gray-300);
}

.status-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
}

.template-status.active .status-dot {
    animation: pulse 2s ease-in-out infinite;
}

@keyframes pulse {
    0%, 100% {
        opacity: 1;
    }
    50% {
        opacity: 0.5;
    }
}

.template-info {
    margin-bottom: 1.25rem;
}

.template-info p {
    margin: 0 0 0.75rem 0;
    color: var(--gray-700);
    font-size: 0.95rem;
}

.template-info strong {
    color: var(--gray-900);
    font-weight: 600;
}

.info-label {
    display: block;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--gray-600);
    margin-bottom: 0.5rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.subject-text {
    background: var(--gray-50);
    padding: 0.75rem 1rem;
    border-radius: var(--radius-md);
    border-left: 3px solid var(--admin-green);
    font-family: 'Courier New', monospace;
    font-size: 0.9rem;
    color: var(--gray-900);
    margin-bottom: 1rem;
}

.variables-list {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.variables-list code {
    background: linear-gradient(135deg, #FEF3EC 0%, #FCE7D9 100%);
    padding: 0.375rem 0.75rem;
    border-radius: var(--radius-sm);
    font-size: 0.8rem;
    color: var(--admin-orange);
    font-family: 'Courier New', monospace;
    border: 1px solid rgba(205, 106, 21, 0.2);
    font-weight: 600;
}

.template-actions {
    display: flex;
    gap: 0.75rem;
    padding-top: 1rem;
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

.btn-action.preview {
    background: white;
    color: var(--gray-700);
    border: 1.5px solid var(--gray-300);
}

.btn-action.preview:hover {
    background: var(--gray-100);
    border-color: var(--gray-400);
    color: var(--gray-900);
    transform: translateY(-2px);
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
    margin: 0;
}

/* Responsive */
@media (max-width: 768px) {
    .email-templates-page {
        padding: 1rem;
    }

    .template-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.75rem;
    }

    .template-actions {
        flex-direction: column;
    }

    .btn-action {
        width: 100%;
    }
}
</style>

<div class="email-templates-page">
    <!-- Header -->
    <div class="page-header">
        <div class="header-icon">
            <i class="bi bi-envelope"></i>
        </div>
        <div class="header-text">
            <h3>Plantillas de Email</h3>
            <p>Gestiona las plantillas de notificaciones que se envían automáticamente</p>
        </div>
    </div>

    <?= $this->Flash->render() ?>

    <!-- Templates List -->
    <?php if (!empty($templates)): ?>
        <div class="templates-list pb-3">
            <?php foreach ($templates as $template): ?>
                <div class="template-card">
                    <div class="template-header">
                        <h3>
                            <i class="bi bi-file-earmark-text"></i>
                            <?= h($template->template_key) ?>
                        </h3>
                        <span class="template-status <?= $template->is_active ? 'active' : 'inactive' ?>">
                            <span class="status-dot"></span>
                            <?= $template->is_active ? 'Activa' : 'Inactiva' ?>
                        </span>
                    </div>

                    <div class="template-info">
                        <span class="info-label">Asunto del Email</span>
                        <div class="subject-text">
                            <?= h($template->subject) ?>
                        </div>

                        <span class="info-label">Variables Disponibles</span>
                        <div class="variables-list">
                            <?php
                            $vars = json_decode($template->available_variables, true);
                            if ($vars):
                                foreach ($vars as $var):
                            ?>
                                <code>{{<?= h($var) ?>}}</code>
                            <?php
                                endforeach;
                            endif;
                            ?>
                        </div>
                    </div>

                    <div class="template-actions">
                        <?= $this->Html->link(
                            '<i class="bi bi-pencil"></i> Editar',
                            ['action' => 'editTemplate', $template->id],
                            ['class' => 'btn-action edit', 'escape' => false]
                        ) ?>
                        <?= $this->Html->link(
                            '<i class="bi bi-eye"></i> Previsualizar',
                            ['action' => 'previewTemplate', $template->id],
                            ['class' => 'btn-action preview', 'target' => '_blank', 'escape' => false]
                        ) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <i class="bi bi-envelope-x empty-state-icon"></i>
            <h3>No hay plantillas configuradas</h3>
            <p>Las plantillas de email se configuran automáticamente al inicializar el sistema.</p>
        </div>
    <?php endif; ?>
</div>
