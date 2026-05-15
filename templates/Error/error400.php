<?php
/**
 * @var \App\View\AppView $this
 * @var string $message
 * @var string $url
 */
use Cake\Core\Configure;

$this->layout = 'error';

if (Configure::read('debug')) :
    $this->layout = 'dev_error';

    $this->assign('title', $message);
    $this->assign('templateName', 'error400.php');

    $this->start('file');
    echo $this->element('auto_table_warning');
    $this->end();
endif;

$this->assign('title', $message);
?>
<div class="app-empty">
    <div class="app-empty-icon warning">
        <i class="bi bi-compass"></i>
    </div>
    <div class="app-empty-title"><?= h($message) ?></div>
    <div class="app-empty-message">
        <?= __d('cake', 'The requested address {0} was not found on this server.',
            '<span class="mono">' . h($url) . '</span>') ?>
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
