<?php
/**
 * @var \App\View\AppView $this
 * @var list<\App\Notification\Email\Admin\TemplateDescriptor> $descriptors
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
                    <span class="value emphasis"><?= count($descriptors) ?></span>
                    <span class="label">plantillas</span>
                </span>
                <span class="stat-inline">
                    <span class="label">Sólo lectura — viven en el código</span>
                </span>
            </div>
        </div>
    </div>
</header>

<?php if (!empty($descriptors)): ?>
    <div class="app-grid wide">
        <?php foreach ($descriptors as $d): ?>
            <article class="app-card">
                <div class="app-card-header">
                    <div class="app-card-header-icon" style="background: <?= h($d->accentSoftColor) ?>; color: <?= h($d->accentColor) ?>;">
                        <i class="bi bi-envelope-paper"></i>
                    </div>
                    <div class="app-card-header-text">
                        <h3 class="app-card-header-title mono"><?= h($d->key) ?></h3>
                        <div class="app-card-header-subtitle"><?= h($d->tag) ?></div>
                    </div>
                </div>
                <div class="app-card-body">
                    <p class="app-card-body-text"><?= h($d->description) ?></p>
                </div>
                <div class="app-card-footer between">
                    <span class="muted">Edición deshabilitada</span>
                    <?= $this->Html->link(
                        '<i class="bi bi-eye"></i> Previsualizar',
                        ['controller' => 'EmailTemplates', 'action' => 'preview', $d->key],
                        ['class' => 'btn-brand-primary btn-brand-sm', 'target' => '_blank', 'escape' => false],
                    ) ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <?= $this->element('empty_state', [
        'icon' => 'envelope-x',
        'tone' => 'neutral',
        'title' => 'No hay plantillas registradas',
        'message' => 'Define plantillas implementando App\\Notification\\Email\\EmailTemplate.',
    ]) ?>
<?php endif; ?>
