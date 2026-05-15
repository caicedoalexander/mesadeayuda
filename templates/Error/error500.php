<?php
/**
 * @var \App\View\AppView $this
 * @var string $message
 */
use Cake\Core\Configure;
use Cake\Error\Debugger;

$this->layout = 'error';

if (Configure::read('debug')) :
    $this->layout = 'dev_error';

    $this->assign('title', $message);
    $this->assign('templateName', 'error500.php');

    $this->start('file');
?>
<?php if ($error instanceof Error) : ?>
    <?php $file = $error->getFile() ?>
    <?php $line = $error->getLine() ?>
    <strong>Error in: </strong>
    <?= $this->Html->link(sprintf('%s, line %s', Debugger::trimPath($file), $line), Debugger::editorUrl($file, $line)); ?>
<?php endif; ?>
<?php
    echo $this->element('auto_table_warning');

    $this->end();
endif;

$displayMessage = Configure::read('debug')
    ? $message
    : __d('cake', 'An Internal Error Has Occurred.');

$this->assign('title', __d('cake', 'An Internal Error Has Occurred.'));
?>
<div class="app-empty">
    <div class="app-empty-icon danger">
        <i class="bi bi-exclamation-octagon"></i>
    </div>
    <div class="app-empty-title"><?= __d('cake', 'An Internal Error Has Occurred.') ?></div>
    <div class="app-empty-message">
        <?= h($displayMessage) ?>
    </div>
    <div class="app-empty-action">
        <a href="javascript:history.back()" class="btn-brand-secondary btn-brand-sm">
            <i class="bi bi-arrow-left"></i> Volver
        </a>
        <a href="<?= $this->Url->build('/') ?>" class="btn-brand-primary btn-brand-sm">
            <i class="bi bi-house"></i> Ir al inicio
        </a>
    </div>
</div>
