<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\EmailTemplate $template
 */
$this->assign('title', 'Editar plantilla');
$this->assign('active_workspace', 'templates');
$vars = json_decode($template->available_variables ?? '[]', true) ?: [];
?>

<header class="app-page-header">
    <nav class="app-breadcrumb" aria-label="breadcrumb">
        <i class="bi bi-grid-1x2"></i>
        <span>Workspace</span>
        <i class="bi bi-chevron-right separator"></i>
        <?= $this->Html->link('Plantillas', ['controller' => 'EmailTemplates', 'action' => 'index']) ?>
        <i class="bi bi-chevron-right separator"></i>
        <span class="current mono"><?= h($template->template_key) ?></span>
    </nav>

    <div class="app-page-header-row">
        <div class="app-page-header-text">
            <h1 class="app-page-title">Editar plantilla</h1>
            <div class="app-page-stats">
                <span class="stat-inline">
                    <span class="dot" style="background: var(--admin-blue);"></span>
                    <span class="value mono"><?= h($template->template_key) ?></span>
                </span>
            </div>
        </div>
        <div class="app-page-actions">
            <?= $this->Html->link(
                '<i class="bi bi-eye"></i> Vista previa',
                ['controller' => 'EmailTemplates', 'action' => 'preview', $template->id],
                ['class' => 'btn-brand-secondary', 'target' => '_blank', 'escape' => false]
            ) ?>
            <?= $this->Html->link(
                '<i class="bi bi-arrow-left"></i> Volver',
                ['controller' => 'EmailTemplates', 'action' => 'index'],
                ['class' => 'btn-brand-ghost', 'escape' => false]
            ) ?>
        </div>
    </div>
</header>

<?= $this->Form->create($template) ?>
<div class="app-card">
    <div class="app-card-header">
        <div class="app-card-header-icon"><i class="bi bi-info-circle"></i></div>
        <div class="app-card-header-text">
            <h3 class="app-card-header-title">Información general</h3>
            <div class="app-card-header-subtitle">Clave, asunto y estado</div>
        </div>
    </div>
    <div class="app-card-body">
        <div class="app-form-group">
            <?= $this->Form->label('template_key', 'Clave de la plantilla') ?>
            <?= $this->Form->text('template_key', [
                'disabled' => true,
                'title' => 'La clave no se puede modificar',
            ]) ?>
            <small>La clave identifica la plantilla y no se puede modificar.</small>
        </div>

        <div class="app-form-group">
            <?= $this->Form->label('subject', 'Asunto del email') ?>
            <?= $this->Form->text('subject', [
                'placeholder' => 'Ej: [Ticket #{{ticket_number}}] {{subject}}',
            ]) ?>
            <small>Variables disponibles: <code>{{ticket_number}}</code>, <code>{{subject}}</code>, etc.</small>
        </div>

        <div class="app-form-group">
            <span class="app-form-label">Estado</span>
            <label class="app-toggle">
                <?= $this->Form->checkbox('is_active', ['id' => 'is_active', 'hiddenField' => false]) ?>
                <span class="app-toggle-label">Plantilla activa</span>
            </label>
            <small>Si está desactivada, no se enviará este tipo de notificación.</small>
        </div>
    </div>
</div>

<div class="app-card">
    <div class="app-card-header">
        <div class="app-card-header-icon blue"><i class="bi bi-code-square"></i></div>
        <div class="app-card-header-text">
            <h3 class="app-card-header-title">Contenido HTML</h3>
            <div class="app-card-header-subtitle">Cuerpo del email con variables interpoladas</div>
        </div>
    </div>
    <div class="app-card-body">
        <div class="app-form-group">
            <?= $this->Form->label('body_html', 'Cuerpo del email (HTML)') ?>
            <?= $this->Form->textarea('body_html', [
                'class' => 'mono',
                'rows' => 20,
                'style' => 'font-family: "Geist Mono", ui-monospace, monospace; font-size: 12px; line-height: 1.55; min-height: 320px;',
            ]) ?>
            <small>Escribe el HTML del email. Usa las variables del bloque siguiente.</small>
        </div>

        <?php if (!empty($vars)): ?>
        <div class="app-form-group">
            <span class="app-form-label">
                <i class="bi bi-braces"></i> Variables disponibles
            </span>
            <div class="email-variables">
                <?php foreach ($vars as $var): ?>
                    <code class="email-var-chip copyable"
                          data-tip="Copiar"
                          onclick="navigator.clipboard.writeText('<?= '{{' . h($var) . '}}' ?>')">
                        <?= '{{' . h($var) . '}}' ?>
                    </code>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <div class="app-card-footer between">
        <?= $this->Html->link('Cancelar',
            ['controller' => 'EmailTemplates', 'action' => 'index'],
            ['class' => 'btn-brand-ghost']
        ) ?>
        <?= $this->Form->button(
            '<i class="bi bi-check-lg"></i> Guardar cambios',
            ['class' => 'btn-brand-primary', 'escapeTitle' => false]
        ) ?>
    </div>
</div>
<?= $this->Form->end() ?>
