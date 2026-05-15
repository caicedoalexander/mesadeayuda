<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Tag $tag
 */
$this->assign('title', 'Editar etiqueta');
$this->assign('active_workspace', 'tags');
$initialColor = strtoupper((string)$tag->color);
?>

<header class="app-page-header">
    <nav class="app-breadcrumb" aria-label="breadcrumb">
        <i class="bi bi-grid-1x2"></i>
        <span>Workspace</span>
        <i class="bi bi-chevron-right separator"></i>
        <?= $this->Html->link('Etiquetas', ['controller' => 'Tags', 'action' => 'index']) ?>
        <i class="bi bi-chevron-right separator"></i>
        <span class="current">Editar</span>
    </nav>

    <div class="app-page-header-row">
        <div class="app-page-header-text">
            <h1 class="app-page-title">Editar <span style="color: var(--gray-500); font-weight: 500;"><?= h($tag->name) ?></span></h1>
        </div>
        <div class="app-page-actions">
            <?= $this->Html->link(
                '<i class="bi bi-arrow-left"></i> Volver',
                ['controller' => 'Tags', 'action' => 'index'],
                ['class' => 'btn-brand-secondary', 'escape' => false]
            ) ?>
        </div>
    </div>
</header>

<?= $this->Form->create($tag) ?>
<div class="app-form-row" style="grid-template-columns: 1.4fr 1fr; align-items: start;">
    <div class="app-card">
        <div class="app-card-header">
            <div class="app-card-header-icon"><i class="bi bi-pencil-square"></i></div>
            <div class="app-card-header-text">
                <h3 class="app-card-header-title">Información de la etiqueta</h3>
                <div class="app-card-header-subtitle">Modifica nombre, color o descripción</div>
            </div>
        </div>
        <div class="app-card-body">
            <div class="app-form-row">
                <div class="app-form-group">
                    <?= $this->Form->label('name', 'Nombre *') ?>
                    <?= $this->Form->text('name', [
                        'placeholder' => 'Ej: Urgente, Bug, Pregunta',
                        'required' => true,
                    ]) ?>
                    <small>Nombre corto y descriptivo.</small>
                </div>

                <div class="app-form-group">
                    <?= $this->Form->label('color', 'Color *') ?>
                    <div class="app-color-picker">
                        <?= $this->Form->color('color', [
                            'id' => 'tag-color',
                            'required' => true,
                        ]) ?>
                        <input type="text" id="color-hex" class="app-color-hex"
                               value="<?= h($initialColor) ?>" readonly>
                    </div>
                    <small>Color de la pill en listados.</small>
                </div>
            </div>

            <div class="app-form-group">
                <?= $this->Form->label('description', 'Descripción') ?>
                <?= $this->Form->textarea('description', [
                    'rows' => 3,
                    'placeholder' => 'Describe cuándo usar esta etiqueta...',
                ]) ?>
                <small>Ayuda a otros usuarios a entender cuándo aplicarla.</small>
            </div>
        </div>
        <div class="app-card-footer">
            <?= $this->Html->link('Cancelar',
                ['controller' => 'Tags', 'action' => 'index'],
                ['class' => 'btn-brand-ghost']
            ) ?>
            <?= $this->Form->button(
                '<i class="bi bi-check-lg"></i> Actualizar etiqueta',
                ['class' => 'btn-brand-primary', 'escapeTitle' => false]
            ) ?>
        </div>
    </div>

    <div class="app-card">
        <div class="app-card-header">
            <div class="app-card-header-icon orange"><i class="bi bi-eye"></i></div>
            <div class="app-card-header-text">
                <h3 class="app-card-header-title">Vista previa</h3>
                <div class="app-card-header-subtitle">Cómo se verá al aplicar</div>
            </div>
        </div>
        <div class="app-card-body">
            <div class="app-tag-preview" id="preview-wrapper" style="--swatch: <?= h($initialColor) ?>">
                <span class="app-tag-preview-chip" id="preview-badge">
                    <span id="preview-text"><?= h($tag->name) ?></span>
                </span>
            </div>
        </div>
    </div>
</div>
<?= $this->Form->end() ?>

<?= $this->Html->script('admin/tag-form', ['block' => 'script']) ?>
