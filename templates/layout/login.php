<!DOCTYPE html>
<html lang="es">
<?php $this->Html->css('login', ['block' => true]); ?>
<?= $this->element('head') ?>
<body class="app-login-body">
    <?= $this->Flash->render() ?>
    <?= $this->fetch('content') ?>
</body>
</html>
