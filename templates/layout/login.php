<!DOCTYPE html>
<html lang="es">
<?= $this->element('head') ?>
<body class="login-body">
    <div class="login-wrapper d-flex align-items-center justify-content-center" style="min-height: 100dvh;">
        <?= $this->Flash->render() ?>
        <?= $this->fetch('content') ?>
    </div>
</body>
</html>
