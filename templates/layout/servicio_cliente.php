<!DOCTYPE html>
<html lang="es">
<?= $this->element('head') ?>
<body>
    <nav class="top-navbar app-topbar" >
        <div class="d-flex justify-content-between align-items-center px-3 w-100">
            <img src="<?= $this->Url->image('logos/servicioalcliente.svg') ?>">
            <div class="nav-menu d-flex align-items-center gap-3 py-3">
                <?= $this->Html->link(
                    '<i class="bi bi-person"></i> Mi Perfil',
                    ['prefix' => 'Admin', 'controller' => 'Settings', 'action' => 'editUser', $currentUser->id],
                    ['escape' => false]
                ) ?>

            </div>
            <div class="nav-user d-flex align-items-center gap-2">
                <?= $this->Html->link(
                    '<i class="bi bi-box-arrow-right"></i> Salir',
                    ['prefix' => false, 'controller' => 'Users', 'action' => 'logout'],
                    ['class' => 'btn-logout', 'escape' => false]
                ) ?>
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
