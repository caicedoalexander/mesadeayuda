<!DOCTYPE html>
<html lang="es">
<?= $this->element('head') ?>
<body>
    <nav class="top-navbar" style="max-height: 55px; z-index: 1000;">
        <div class="d-flex justify-content-between align-items-center px-3 w-100">
            <div class="d-flex align-items-center gap-2">
                <div class="bg-white d-flex justify-content-center aling-items-center bg-white rounded-circle shadow-sm" style="width: 42px; height: 42px;">
                    <img class="my-auto" src="<?= $this->Url->build('img/logo.png') ?>" alt="Logo" height="45">
                </div>
                <div class="gap-0 d-flex flex-column">
                    <h2 class="fs-5 m-0">
                        <?= h($systemTitle) ?>
                    </h2>
                </div>
            </div>
            <div class="nav-menu d-flex align-items-center gap-3 py-3">
                <?= $this->Html->link('<i class="bi bi-ticket"></i> Tickets', ['prefix' => false, 'controller' => 'Tickets', 'action' => 'index'], ['escape' => false]) ?>
                <?= $this->Html->link(
                    '<i class="bi bi-gear"></i> Mi Perfil',
                    ['prefix' => 'Admin', 'controller' => 'Settings', 'action' => 'editUser', $currentUser->id],
                    ['escape' => false]
                ) ?>
                <div class="nav-user d-flex align-items-center gap-2">
                    <?= $this->User->profileImageTag($currentUser, ['width' => '32', 'height' => '32', 'class' => 'rounded-circle']) ?>
                    <span class="small"><?= h($currentUser->name) ?></span>
                    <?= $this->Html->link('<i class="bi bi-box-arrow-right"></i> Salir', ['prefix' => false, 'controller' => 'Users', 'action' => 'logout'], ['class' => 'btn-logout', 'escape' => false]) ?>
                </div>
            </div>
        </div>
    </nav>

    <div style="max-height: calc(100vh - 55px);">
        <?= $this->Flash->render() ?>
        <!-- Loading Spinner -->
        <?= $this->element('loading_spinner') ?>
        <div class="d-flex" style="height: calc(100vh - 55px);">
            <?= $this->fetch('content') ?>
        </div>
    </div>
</body>
</html>
