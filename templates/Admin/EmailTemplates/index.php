<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\EmailTemplate> $templates
 */
$this->assign('title', 'Plantillas de email');
$this->assign('active_workspace', 'templates');
?>

<header class="app-page-header">
    <nav class="app-breadcrumb" aria-label="breadcrumb">
        <i class="bi bi-grid-1x2"></i>
        <span>Workspace</span>
        <i class="bi bi-chevron-right separator"></i>
        <span class="current">Plantillas de email</span>
    </nav>

    <div class="app-page-header-row">
        <div class="app-page-header-text">
            <h1 class="app-page-title">Plantillas de email</h1>
            <div class="app-page-stats">
                <span class="stat-inline">
                    <span class="dot" style="background: var(--admin-green);"></span>
                    <span class="value emphasis"><?= count($templates) ?></span>
                    <span class="label">plantillas</span>
                </span>
            </div>
        </div>
    </div>
</header>

<?php if (!empty($templates)): ?>
    <div class="app-grid wide">
        <?php foreach ($templates as $template): ?>
            <?php $vars = json_decode($template->available_variables ?? '[]', true) ?: []; ?>
            <article class="app-card">
                <div class="app-card-header">
                    <div class="app-card-header-icon <?= $template->is_active ? '' : 'neutral' ?>">
                        <i class="bi bi-envelope-paper"></i>
                    </div>
                    <div class="app-card-header-text">
                        <h3 class="app-card-header-title mono"><?= h($template->template_key) ?></h3>
                        <div class="app-card-header-subtitle">
                            <span class="status-dot-pill <?= $template->is_active ? 'active' : 'inactive' ?>">
                                <?= $template->is_active ? 'Activa' : 'Inactiva' ?>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="app-card-body">
                    <div class="app-form-group" style="margin-bottom: 12px;">
                        <span class="app-form-label">Asunto</span>
                        <div class="email-subject-preview"><?= h($template->subject) ?></div>
                    </div>
                    <?php if (!empty($vars)): ?>
                    <div class="app-form-group" style="margin-bottom: 0;">
                        <span class="app-form-label">Variables</span>
                        <div class="email-variables">
                            <?php foreach ($vars as $var): ?>
                                <code class="email-var-chip"><?= '{{' . h($var) . '}}' ?></code>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="app-card-footer between">
                    <?= $this->Html->link(
                        '<i class="bi bi-eye"></i> Previsualizar',
                        ['controller' => 'EmailTemplates', 'action' => 'preview', $template->id],
                        ['class' => 'btn-brand-ghost btn-brand-sm', 'target' => '_blank', 'escape' => false]
                    ) ?>
                    <?= $this->Html->link(
                        '<i class="bi bi-pencil"></i> Editar',
                        ['controller' => 'EmailTemplates', 'action' => 'edit', $template->id],
                        ['class' => 'btn-brand-primary btn-brand-sm', 'escape' => false]
                    ) ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <?= $this->element('empty_state', [
        'icon'    => 'envelope-x',
        'tone'    => 'neutral',
        'title'   => 'No hay plantillas configuradas',
        'message' => 'Las plantillas se configuran automáticamente al inicializar el sistema.',
    ]) ?>
<?php endif; ?>
