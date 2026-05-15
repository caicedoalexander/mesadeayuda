<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Tag> $tags
 */
$this->assign('title', 'Etiquetas');
$this->assign('active_workspace', 'tags');
?>

<header class="app-page-header">
    <nav class="app-breadcrumb" aria-label="breadcrumb">
        <i class="bi bi-grid-1x2"></i>
        <span>Workspace</span>
        <i class="bi bi-chevron-right separator"></i>
        <span class="current">Etiquetas</span>
    </nav>

    <div class="app-page-header-row">
        <div class="app-page-header-text">
            <h1 class="app-page-title">Etiquetas</h1>
            <div class="app-page-stats">
                <span class="stat-inline">
                    <span class="dot" style="background: var(--gray-400);"></span>
                    <span class="value emphasis"><?= count($tags) ?></span>
                    <span class="label">en total</span>
                </span>
            </div>
        </div>
        <div class="app-page-actions">
            <?= $this->Html->link(
                '<i class="bi bi-plus-lg"></i> Nueva etiqueta',
                ['controller' => 'Tags', 'action' => 'add'],
                ['class' => 'btn-brand-primary', 'escape' => false]
            ) ?>
        </div>
    </div>
</header>

<?php if (!empty($tags)): ?>
    <div class="app-grid">
        <?php foreach ($tags as $tag): ?>
            <article class="app-card tag-swatch-card" style="--swatch: <?= h($tag->color) ?>">
                <div class="tag-swatch-bar"></div>
                <div class="app-card-body">
                    <div class="tag-swatch-head">
                        <span class="tag-swatch-chip" title="<?= h($tag->name) ?>">
                            <?= h($tag->name) ?>
                        </span>
                        <span class="tag-swatch-count">
                            <i class="bi bi-ticket"></i>
                            <?= (int)($tag->ticket_count ?? 0) ?>
                        </span>
                    </div>
                    <?php if (!empty($tag->description)): ?>
                        <p class="tag-swatch-desc"><?= h($tag->description) ?></p>
                    <?php endif; ?>
                    <div class="tag-swatch-actions">
                        <?= $this->Html->link(
                            '<i class="bi bi-pencil"></i> Editar',
                            ['controller' => 'Tags', 'action' => 'edit', $tag->id],
                            ['class' => 'btn-brand-secondary btn-brand-sm', 'escape' => false]
                        ) ?>
                        <?= $this->Form->postLink(
                            '<i class="bi bi-trash"></i>',
                            ['controller' => 'Tags', 'action' => 'delete', $tag->id],
                            [
                                'class' => 'btn-brand-ghost btn-brand-sm tag-swatch-delete',
                                'confirm' => '¿Eliminar la etiqueta "' . $tag->name . '"?',
                                'escape' => false,
                                'data-tip' => 'Eliminar',
                            ]
                        ) ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <?= $this->element('empty_state', [
        'icon'    => 'tags',
        'tone'    => 'success',
        'title'   => 'No hay etiquetas creadas',
        'message' => 'Las etiquetas te ayudan a organizar y categorizar tus tickets.',
        'action'  => $this->Html->link(
            '<i class="bi bi-plus-lg"></i> Crear primera etiqueta',
            ['controller' => 'Tags', 'action' => 'add'],
            ['class' => 'btn-brand-primary btn-brand-sm', 'escape' => false]
        ),
    ]) ?>
<?php endif; ?>
