<!DOCTYPE html>
<html lang="es">
<?= $this->element('head') ?>

<body>
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