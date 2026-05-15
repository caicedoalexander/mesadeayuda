<?php
/**
 * Default layout — para usuarios no-admin (agentes).
 *
 * Estructura idéntica al admin layout: provee el shell (rail + main).
 * Las vistas no renderizan sidebar, sólo su contenido. Para resaltar
 * un item del rail:
 *
 *   $this->assign('current_view', 'mis_tickets');
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
