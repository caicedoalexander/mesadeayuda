<?php
/**
 * @var \App\View\AppView $this
 * @var array $settings
 */
$this->assign('title', 'Plantillas de Email');
?>

<?= $this->Html->css('admin/email-templates', ['block' => 'css']) ?>

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
                            ['controller' => 'EmailTemplates', 'action' => 'edit', $template->id],
                            ['class' => 'btn-action edit', 'escape' => false]
                        ) ?>
                        <?= $this->Html->link(
                            '<i class="bi bi-eye"></i> Previsualizar',
                            ['controller' => 'EmailTemplates', 'action' => 'preview', $template->id],
                            ['class' => 'btn-action preview', 'target' => '_blank', 'escape' => false]
                        ) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <?= $this->element('empty_state', [
            'icon'    => 'envelope-x',
            'tone'    => 'neutral',
            'title'   => 'No hay plantillas configuradas',
            'message' => 'Las plantillas de email se configuran automáticamente al inicializar el sistema.',
        ]) ?>
    <?php endif; ?>
</div>
