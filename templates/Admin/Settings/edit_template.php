<?php
/**
 * @var \App\View\AppView $this
 * @var array $settings
 */
$this->assign('title', 'Editar Plantilla');
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
    --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    --gradient-celebrate: linear-gradient(135deg, #00A85E 0%, #CD6A15 100%);
}

.edit-template-page {
    padding: 2rem;
    max-width: 900px;
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

/* Page Header */
.page-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 2rem;
}

.header-icon {
    width: 56px;
    height: 56px;
    background: linear-gradient(135deg, #FEF3EC 0%, #FCE7D9 100%);
    border: 2px solid var(--admin-orange);
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.header-icon i {
    font-size: 28px;
    color: var(--admin-orange);
}

.header-text h1 {
    font-size: 2rem;
    font-weight: 700;
    color: var(--gray-900);
    margin: 0;
    line-height: 1.2;
}

.header-text p {
    font-size: 1rem;
    color: var(--gray-600);
    margin: 0.25rem 0 0 0;
}

.header-text strong {
    color: var(--admin-orange);
}

/* Form Card */
.template-card {
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-lg);
    overflow: hidden;
    position: relative;
}

.template-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: var(--gradient-celebrate);
    box-shadow: 0 4px 15px rgba(0, 168, 94, 0.3);
}

.form-content {
    padding: 2.5rem;
}

/* Form Sections */
.form-section {
    margin-bottom: 2.5rem;
    padding-bottom: 2.5rem;
    border-bottom: 1px solid var(--gray-200);
}

.form-section:last-of-type {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}

.section-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1.75rem;
}

.section-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #FEF3EC 0%, #FCE7D9 100%);
    border: 2px solid var(--admin-orange);
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.section-icon i {
    font-size: 20px;
    color: var(--admin-orange);
}

.section-header h3 {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--gray-900);
    margin: 0;
}

/* Form Groups */
.form-group {
    margin-bottom: 1.5rem;
}

.form-group:last-child {
    margin-bottom: 0;
}

.form-group label {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--gray-700);
    margin-bottom: 0.5rem;
    display: block;
}

.form-group input[type="text"],
.form-group textarea {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 2px solid var(--gray-200);
    border-radius: var(--radius-md);
    font-size: 0.95rem;
    color: var(--gray-900);
    background: white;
    transition: var(--transition);
    font-family: inherit;
}

.form-group input[type="text"]:focus,
.form-group textarea:focus {
    outline: none;
    border-color: var(--admin-orange);
    box-shadow: 0 0 0 3px rgba(205, 106, 21, 0.1);
}

.form-group input[type="text"]:disabled {
    background: var(--gray-100);
    color: var(--gray-600);
    cursor: not-allowed;
}

.form-group textarea {
    resize: vertical;
    min-height: 100px;
}

.form-group small {
    font-size: 0.85rem;
    color: var(--gray-600);
    margin-top: 0.375rem;
    display: block;
}

/* Code Editor */
.code-editor {
    font-family: 'Courier New', monospace;
    background-color: #1e1e1e;
    color: #d4d4d4;
    border: 2px solid var(--gray-300);
    padding: 1rem;
    font-size: 0.875rem;
    line-height: 1.6;
}

.code-editor:focus {
    background-color: #1e1e1e;
    color: #d4d4d4;
    border-color: var(--admin-orange);
    box-shadow: 0 0 0 3px rgba(205, 106, 21, 0.1);
}

/* Checkbox Toggle */
.checkbox-wrapper {
    background: linear-gradient(135deg, #FEF3EC 0%, #FCE7D9 100%);
    padding: 1rem 1.25rem;
    border-radius: var(--radius-md);
    border: 2px solid rgba(205, 106, 21, 0.2);
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.checkbox-wrapper label {
    margin: 0 !important;
    font-weight: 600;
    color: var(--gray-900);
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.checkbox-wrapper input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
}

/* Variables Help Panel */
.variables-help {
    background: linear-gradient(135deg, var(--gray-50) 0%, var(--gray-100) 100%);
    padding: 1.5rem;
    border-radius: var(--radius-lg);
    border: 2px dashed var(--gray-300);
    margin-top: 1.5rem;
}

.variables-help h4 {
    margin: 0 0 1rem 0;
    color: var(--gray-900);
    font-size: 0.95rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.variables-help h4 i {
    color: var(--admin-orange);
}

.variables-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.variables-grid code {
    background: white;
    padding: 0.375rem 0.75rem;
    border-radius: var(--radius-sm);
    font-size: 0.8rem;
    color: var(--admin-orange);
    border: 1px solid rgba(205, 106, 21, 0.2);
    font-family: 'Courier New', monospace;
    font-weight: 600;
}

/* Form Actions */
.form-actions {
    display: flex;
    gap: 1rem;
    padding-top: 2rem;
    border-top: 1px solid var(--gray-200);
    margin-top: 2rem;
}

.btn-submit {
    background: var(--gradient-celebrate);
    color: white;
    border: none;
    padding: 0.875rem 2rem;
    border-radius: var(--radius-md);
    font-weight: 600;
    font-size: 1rem;
    cursor: pointer;
    transition: var(--transition);
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    box-shadow: 0 4px 15px rgba(0, 168, 94, 0.3);
    justify-content: center;
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0, 168, 94, 0.4);
}

.btn-submit i {
    font-size: 1.1rem;
}

.btn-cancel {
    background: white;
    color: #dc3545;
    border: 2px solid #dc3545;
    padding: 0.875rem 2rem;
    border-radius: var(--radius-md);
    font-weight: 600;
    font-size: 1rem;
    cursor: pointer;
    transition: var(--transition);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    justify-content: center;
}

.btn-cancel:hover {
    background: #dc3545;
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
}

.btn-preview {
    background: white;
    color: var(--gray-700);
    border: 2px solid var(--gray-300);
    padding: 0.875rem 2rem;
    border-radius: var(--radius-md);
    font-weight: 600;
    font-size: 1rem;
    cursor: pointer;
    transition: var(--transition);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    justify-content: center;
}

.btn-preview:hover {
    background: var(--gray-100);
    border-color: var(--gray-400);
    color: var(--gray-900);
    transform: translateY(-2px);
}

/* Responsive */
@media (max-width: 768px) {
    .edit-template-page {
        padding: 1rem;
    }

    .form-content {
        padding: 1.5rem;
    }

    .form-actions {
        flex-direction: column;
    }

    .btn-submit,
    .btn-cancel,
    .btn-preview {
        width: 100%;
    }
}
</style>

<div class="edit-template-page">
    <!-- Page Header -->
    <div class="page-header">
        <div class="header-icon">
            <i class="bi bi-pencil-square"></i>
        </div>
        <div class="header-text">
            <h1>Editar Plantilla de Email</h1>
            <p>Modificar plantilla: <strong><?= h($template->template_key) ?></strong></p>
        </div>
    </div>

    <?= $this->Flash->render() ?>

    <?= $this->Form->create($template) ?>
    <div class="template-card">
        <div class="form-content">

            <!-- Información General -->
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="bi bi-info-circle"></i>
                    </div>
                    <h3>Información General</h3>
                </div>

                <div class="form-group">
                    <?= $this->Form->label('template_key', 'Clave de la Plantilla') ?>
                    <?= $this->Form->text('template_key', [
                        'disabled' => true,
                        'title' => 'La clave no se puede modificar'
                    ]) ?>
                    <small>La clave identifica la plantilla y no se puede cambiar</small>
                </div>

                <div class="form-group">
                    <?= $this->Form->label('subject', 'Asunto del Email') ?>
                    <?= $this->Form->text('subject', [
                        'placeholder' => 'Ej: [Ticket #{{ticket_number}}] {{subject}}'
                    ]) ?>
                    <small>Puedes usar variables como {{ticket_number}}, {{subject}}, etc.</small>
                </div>

                <div class="checkbox-wrapper">
                    <label>
                        <?= $this->Form->checkbox('is_active', ['id' => 'is_active']) ?>
                        Plantilla activa
                    </label>
                    <small style="margin: 0; color: var(--gray-600);">(Si está desactivada, no se enviará este tipo de notificación)</small>
                </div>
            </div>

            <!-- Contenido HTML -->
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="bi bi-code-square"></i>
                    </div>
                    <h3>Contenido HTML</h3>
                </div>

                <div class="form-group">
                    <?= $this->Form->label('body_html', 'Cuerpo del email (HTML)') ?>
                    <?= $this->Form->textarea('body_html', [
                        'class' => 'code-editor',
                        'rows' => 20
                    ]) ?>
                    <small>Escribe el HTML del email. Usa las variables disponibles abajo.</small>
                </div>

                <div class="variables-help">
                    <h4>
                        <i class="bi bi-braces"></i>
                        Variables Disponibles
                    </h4>
                    <div class="variables-grid">
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
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <?= $this->Html->link(
                    '<i class="bi bi-x-circle"></i> Cancelar',
                    ['action' => 'emailTemplates'],
                    ['class' => 'btn-cancel', 'escape' => false]
                ) ?>
                <?= $this->Html->link(
                    '<i class="bi bi-eye"></i> Vista Previa',
                    ['action' => 'previewTemplate', $template->id],
                    ['class' => 'btn-preview', 'target' => '_blank', 'escape' => false]
                ) ?>
                <?= $this->Form->button(
                    '<i class="bi bi-check-circle"></i> Guardar Cambios',
                    ['class' => 'btn-submit', 'escapeTitle' => false]
                ) ?>
            </div>

        </div>
    </div>
    <?= $this->Form->end() ?>
</div>
