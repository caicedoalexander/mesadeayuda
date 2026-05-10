<?php
/**
 * @var \App\View\AppView $this
 * @var array $settings
 */
$this->assign('title', 'Editar Plantilla');
?>

<?= $this->Html->css('admin/edit-template', ['block' => 'css']) ?>

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
                    ['controller' => 'EmailTemplates', 'action' => 'index'],
                    ['class' => 'btn-cancel', 'escape' => false]
                ) ?>
                <?= $this->Html->link(
                    '<i class="bi bi-eye"></i> Vista Previa',
                    ['controller' => 'EmailTemplates', 'action' => 'preview', $template->id],
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
