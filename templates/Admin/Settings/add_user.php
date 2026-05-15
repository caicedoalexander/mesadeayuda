<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 */
$this->assign('title', 'Nuevo usuario');
$this->assign('active_workspace', 'users');
?>

<header class="app-page-header">
    <nav class="app-breadcrumb" aria-label="breadcrumb">
        <i class="bi bi-grid-1x2"></i>
        <span>Workspace</span>
        <i class="bi bi-chevron-right separator"></i>
        <?= $this->Html->link('Usuarios', ['action' => 'users']) ?>
        <i class="bi bi-chevron-right separator"></i>
        <span class="current">Nuevo usuario</span>
    </nav>

    <div class="app-page-header-row">
        <div class="app-page-header-text">
            <h1 class="app-page-title">Nuevo usuario</h1>
        </div>
        <div class="app-page-actions">
            <?= $this->Html->link(
                '<i class="bi bi-arrow-left"></i> Volver',
                ['action' => 'users'],
                ['class' => 'btn-brand-secondary', 'escape' => false]
            ) ?>
        </div>
    </div>
</header>

<?= $this->Form->create($user) ?>

<div class="app-card">
    <div class="app-card-header">
        <div class="app-card-header-icon"><i class="bi bi-person"></i></div>
        <div class="app-card-header-text">
            <h3 class="app-card-header-title">Información personal</h3>
            <div class="app-card-header-subtitle">Nombre y correo electrónico</div>
        </div>
    </div>
    <div class="app-card-body">
        <div class="app-form-row">
            <div class="app-form-group">
                <?= $this->Form->label('first_name', 'Nombre *') ?>
                <?= $this->Form->text('first_name', ['placeholder' => 'Ej: Juan', 'required' => true]) ?>
            </div>
            <div class="app-form-group">
                <?= $this->Form->label('last_name', 'Apellido *') ?>
                <?= $this->Form->text('last_name', ['placeholder' => 'Ej: Pérez', 'required' => true]) ?>
            </div>
        </div>
        <div class="app-form-group">
            <?= $this->Form->label('email', 'Correo electrónico *') ?>
            <?= $this->Form->email('email', ['placeholder' => 'ejemplo@correo.com', 'required' => true]) ?>
        </div>
    </div>
</div>

<div class="app-card">
    <div class="app-card-header">
        <div class="app-card-header-icon orange"><i class="bi bi-gear"></i></div>
        <div class="app-card-header-text">
            <h3 class="app-card-header-title">Configuración de cuenta</h3>
            <div class="app-card-header-subtitle">Rol y estado inicial</div>
        </div>
    </div>
    <div class="app-card-body">
        <div class="app-form-row">
            <div class="app-form-group">
                <?= $this->Form->label('role', 'Rol *') ?>
                <?= $this->Form->select('role', [
                    'admin' => 'Administrador',
                    'asesor_tic' => 'Asesor TIC',
                ], ['required' => true]) ?>
                <small>Define los permisos del usuario en el sistema.</small>
            </div>
            <div class="app-form-group">
                <span class="app-form-label">Estado</span>
                <label class="app-toggle">
                    <?= $this->Form->checkbox('is_active', ['id' => 'is_active', 'checked' => true, 'hiddenField' => false]) ?>
                    <span class="app-toggle-label">Cuenta activa</span>
                </label>
                <small>Los usuarios inactivos no pueden iniciar sesión.</small>
            </div>
        </div>
    </div>
</div>

<div class="app-card">
    <div class="app-card-header">
        <div class="app-card-header-icon danger"><i class="bi bi-shield-lock"></i></div>
        <div class="app-card-header-text">
            <h3 class="app-card-header-title">Contraseña</h3>
            <div class="app-card-header-subtitle">Mínimo 6 caracteres</div>
        </div>
    </div>
    <div class="app-card-body">
        <div class="app-form-row">
            <div class="app-form-group">
                <?= $this->Form->label('password', 'Contraseña *') ?>
                <?= $this->Form->password('password', [
                    'required' => true,
                    'autocomplete' => 'new-password',
                    'placeholder' => '••••••••',
                ]) ?>
            </div>
            <div class="app-form-group">
                <?= $this->Form->label('confirm_password', 'Confirmar contraseña *') ?>
                <?= $this->Form->password('confirm_password', [
                    'required' => true,
                    'autocomplete' => 'new-password',
                    'placeholder' => '••••••••',
                ]) ?>
            </div>
        </div>
    </div>
    <div class="app-card-footer">
        <?= $this->Html->link('Cancelar', ['action' => 'users'], ['class' => 'btn-brand-ghost']) ?>
        <?= $this->Form->button(
            '<i class="bi bi-check-lg"></i> Crear usuario',
            ['class' => 'btn-brand-primary', 'escapeTitle' => false]
        ) ?>
    </div>
</div>

<?= $this->Form->end() ?>
