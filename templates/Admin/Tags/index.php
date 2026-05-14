<?php
/**
 * @var \App\View\AppView $this
 * @var array $settings
 */
$this->assign('title', 'Gestión de Etiquetas');
?>

<?= $this->Html->css('admin/tags', ['block' => 'css']) ?>

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
                ['controller' => 'Tags', 'action' => 'add'],
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
                            ['controller' => 'Tags', 'action' => 'edit', $tag->id],
                            ['class' => 'btn-action edit', 'escape' => false]
                        ) ?>
                        <?= $this->Form->postLink(
                            '<i class="bi bi-trash"></i> Eliminar',
                            ['controller' => 'Tags', 'action' => 'delete', $tag->id],
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
</div>
