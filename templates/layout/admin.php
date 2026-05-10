<!DOCTYPE html>
<html lang="es">
<?= $this->element('head') ?>

<body>
    <!-- Admin Navigation -->
    <nav class="top-navbar app-topbar" >
        <div class="d-flex justify-content-between align-items-center px-3 w-100">
            <img src="<?= $this->Url->image('logos/soporte-interno.svg') ?>">
            <div class="nav-menu d-flex align-items-center gap-3 py-3">
                <?= $this->Html->link('<i class="bi bi-ticket"></i> Tickets', ['prefix' => false, 'controller' => 'Tickets', 'action' => 'index'], ['escape' => false]) ?>
                <?= $this->Html->link('<i class="bi bi-people"></i> Usuarios', ['prefix' => 'Admin', 'controller' => 'Settings', 'action' => 'users'], ['escape' => false]) ?>
                <?= $this->Html->link('<i class="bi bi-tags"></i> Etiquetas', ['prefix' => 'Admin', 'controller' => 'Tags', 'action' => 'index'], ['escape' => false]) ?>
                <?= $this->Html->link('<i class="bi bi-envelope"></i> Plantillas', ['prefix' => 'Admin', 'controller' => 'EmailTemplates', 'action' => 'index'], ['escape' => false]) ?>
                <?= $this->Html->link('<i class="bi bi-gear"></i> Configuración', ['prefix' => 'Admin', 'controller' => 'Settings', 'action' => 'index'], ['escape' => false]) ?>
            </div>
            <div class="nav-user d-flex align-items-center">
                <!--
                <?= $this->User->profileImageTag($currentUser, ['width' => '32', 'height' => '32', 'class' => 'rounded-circle object-fit-cover shadow-sm']) ?>
                <span class="small"><?= h($currentUser->name) ?></span>
                -->
                <?= $this->Html->link('<i class="bi bi-box-arrow-right"></i> Salir', ['prefix' => false, 'controller' => 'Users', 'action' => 'logout'], ['class' => 'btn-logout', 'escape' => false]) ?>
            </div>
        </div>
    </nav>

    <div class="overflow-auto sidebar-scroll below-topbar-mh" >
        <?= $this->Flash->render() ?>
        <!-- Loading Spinner -->
        <?= $this->element('loading_spinner') ?>
        <div class="d-flex below-topbar-h" >
            <?= $this->fetch('content') ?>
        </div>
    </div>
</body>

</html>