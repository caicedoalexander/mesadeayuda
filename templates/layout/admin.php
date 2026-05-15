<?php
/**
 * Admin layout — provee el shell completo (rail + main).
 *
 * Las vistas SOLO renderizan su contenido (header de página + cards/forms).
 * Para resaltar un item del rail, la vista hace:
 *
 *   $this->assign('active_workspace', 'tags'); // users|tags|templates|settings
 *   $this->assign('current_view', 'todos_sin_resolver'); // sólo Tickets
 *
 * @var \App\View\AppView $this
 */
$identity        = $this->getRequest()->getAttribute('identity');
$userRole        = $identity ? $identity->get('role') : null;
$userId          = $identity ? $identity->get('id') : null;
$currentView     = $this->fetch('current_view') ?: '';
$activeWorkspace = $this->fetch('active_workspace') ?: null;
$shellModifier   = $this->fetch('shell_modifier') ?: '';
?>
<!DOCTYPE html>
<html lang="es">
<?= $this->element('head') ?>
<body>
    <div class="app-shell">
        <?= $this->cell('TicketsSidebar::display', [$currentView, $userRole, $userId, $activeWorkspace]) ?>
        <main class="app-shell-main <?= h($shellModifier) ?>">
            <?= $this->Flash->render() ?>
            <?= $this->element('loading_spinner') ?>
            <?= $this->fetch('content') ?>
        </main>
    </div>
</body>
</html>
