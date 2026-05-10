<?php
/**
 * @var \App\View\AppView $this
 * @var array $settings
 */
$this->assign('title', 'Añadir Etiqueta');
?>

<?= $this->Html->css('admin/add-tag', ['block' => 'css']) ?>

<div class="add-tag-page">
    <!-- Page Header -->
    <div class="page-header">
        <div class="header-icon">
            <i class="bi bi-tag"></i>
        </div>
        <div class="header-text">
            <h1>Nueva Etiqueta</h1>
            <p>Crear una nueva etiqueta para organizar tickets</p>
        </div>
    </div>

    <?= $this->Flash->render() ?>

    <?= $this->Form->create($tag) ?>
    <div class="tag-card">
        <div class="form-content">

            <!-- Información de la Etiqueta -->
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="bi bi-info-circle"></i>
                    </div>
                    <h3>Información de la Etiqueta</h3>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <?= $this->Form->label('name', 'Nombre *') ?>
                        <?= $this->Form->text('name', [
                            'placeholder' => 'Ej: Urgente, Bug, Pregunta',
                            'required' => true
                        ]) ?>
                        <small>Nombre corto y descriptivo para la etiqueta</small>
                    </div>

                    <div class="form-group">
                        <?= $this->Form->label('color', 'Color *') ?>
                        <div class="color-input-wrapper">
                            <?= $this->Form->color('color', [
                                'class' => 'color-picker',
                                'id' => 'tag-color',
                                'value' => '#0066cc',
                                'required' => true
                            ]) ?>
                            <input type="text" id="color-hex" class="color-hex-input"
                                   value="#0066CC" readonly>
                        </div>
                        <small>Color para identificar visualmente la etiqueta</small>
                    </div>
                </div>

                <div class="form-group">
                    <?= $this->Form->label('description', 'Descripción') ?>
                    <?= $this->Form->textarea('description', [
                        'rows' => 3,
                        'placeholder' => 'Describe cuándo usar esta etiqueta...'
                    ]) ?>
                    <small>Ayuda a otros usuarios a entender cuándo aplicar esta etiqueta</small>
                </div>
            </div>

            <!-- Vista Previa -->
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="bi bi-eye"></i>
                    </div>
                    <h3>Vista Previa</h3>
                </div>

                <div class="tag-preview">
                    <span class="preview-badge" id="preview-badge" style="background-color: #0066cc">
                        <span id="preview-text">Nombre de etiqueta</span>
                    </span>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <?= $this->Html->link(
                    'Cancelar',
                    ['controller' => 'Tags', 'action' => 'index'],
                    ['class' => 'btn-cancel']
                ) ?>
                <?= $this->Form->button(
                    '<i class="bi bi-check-circle"></i> Crear Etiqueta',
                    ['class' => 'btn-submit', 'escapeTitle' => false]
                ) ?>
            </div>

        </div>
    </div>
    <?= $this->Form->end() ?>
</div>

<?= $this->Html->script('admin/tag-form', ['block' => 'script']) ?>
