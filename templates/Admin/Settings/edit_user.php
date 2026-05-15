<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 */
$this->assign('title', 'Editar usuario');
$this->assign('active_workspace', 'users');
?>

<header class="app-page-header">
    <nav class="app-breadcrumb" aria-label="breadcrumb">
        <i class="bi bi-grid-1x2"></i>
        <span>Workspace</span>
        <i class="bi bi-chevron-right separator"></i>
        <?= $this->Html->link('Usuarios', ['action' => 'users']) ?>
        <i class="bi bi-chevron-right separator"></i>
        <span class="current">Editar</span>
    </nav>

    <div class="app-page-header-row">
        <div class="app-page-header-text">
            <h1 class="app-page-title">
                Editar <span style="color: var(--gray-500); font-weight: 500;"><?= h($user->name) ?></span>
            </h1>
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

<?= $this->Form->create($user, ['type' => 'file']) ?>

<div class="app-card">
    <div class="app-card-header">
        <div class="app-card-header-icon blue"><i class="bi bi-camera"></i></div>
        <div class="app-card-header-text">
            <h3 class="app-card-header-title">Foto de perfil</h3>
            <div class="app-card-header-subtitle">JPG, PNG, GIF o WEBP — máx. 2&nbsp;MB</div>
        </div>
    </div>
    <div class="app-card-body">
        <div class="profile-edit-row">
            <div class="profile-edit-avatar">
                <?= $this->User->profileImageTag($user, ['width' => '96', 'height' => '96', 'class' => 'rounded-circle']) ?>
            </div>
            <label class="app-dropzone" for="profile-image-upload" style="margin: 0; flex: 1;">
                <span class="app-dropzone-icon"><i class="bi bi-upload"></i></span>
                <div class="app-dropzone-title">
                    Arrastra una imagen o <span class="app-dropzone-link">selecciona del equipo</span>
                </div>
                <div class="app-dropzone-hint">JPG, PNG, GIF, WEBP · hasta 2&nbsp;MB</div>
                <?= $this->Form->file('profile_image_upload', [
                    'accept' => 'image/jpeg,image/png,image/gif,image/webp',
                    'id' => 'profile-image-upload',
                    'hidden' => true,
                    'style' => 'display:none;',
                ]) ?>
            </label>
        </div>
    </div>
</div>

<div class="app-card">
    <div class="app-card-header">
        <div class="app-card-header-icon"><i class="bi bi-person"></i></div>
        <div class="app-card-header-text">
            <h3 class="app-card-header-title">Información personal</h3>
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
            <div class="app-card-header-subtitle">Rol y estado</div>
        </div>
    </div>
    <div class="app-card-body">
        <div class="app-form-row">
            <div class="app-form-group">
                <?= $this->Form->label('role', 'Rol *') ?>
                <?= $this->Form->select('role', [
                    'admin' => 'Administrador',
                    'asesor_tic' => 'Asesor TIC',
                ], ['value' => h($user->role)]) ?>
                <small>Define los permisos del usuario.</small>
            </div>
            <div class="app-form-group">
                <span class="app-form-label">Estado</span>
                <label class="app-toggle">
                    <?= $this->Form->checkbox('is_active', ['id' => 'is_active', 'hiddenField' => false]) ?>
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
            <h3 class="app-card-header-title">Cambiar contraseña</h3>
            <div class="app-card-header-subtitle">Deja en blanco si no deseas cambiarla</div>
        </div>
    </div>
    <div class="app-card-body">
        <div class="app-form-row">
            <div class="app-form-group">
                <?= $this->Form->label('new_password', 'Nueva contraseña') ?>
                <?= $this->Form->password('new_password', [
                    'value' => '',
                    'autocomplete' => 'new-password',
                    'placeholder' => '••••••••',
                ]) ?>
            </div>
            <div class="app-form-group">
                <?= $this->Form->label('confirm_password', 'Confirmar contraseña') ?>
                <?= $this->Form->password('confirm_password', [
                    'value' => '',
                    'autocomplete' => 'new-password',
                    'placeholder' => '••••••••',
                ]) ?>
            </div>
        </div>
        <small class="app-form-help">Mínimo 6 caracteres.</small>
    </div>
    <div class="app-card-footer">
        <?= $this->Html->link('Cancelar', ['action' => 'users'], ['class' => 'btn-brand-ghost']) ?>
        <?= $this->Form->button(
            '<i class="bi bi-check-lg"></i> Guardar cambios',
            ['class' => 'btn-brand-primary', 'escapeTitle' => false]
        ) ?>
    </div>
</div>

<?= $this->Form->end() ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('profile-image-upload');
    const dropzone = document.querySelector('label[for="profile-image-upload"].app-dropzone');
    if (!input || !dropzone) return;

    ['dragenter', 'dragover'].forEach(evt => dropzone.addEventListener(evt, e => {
        e.preventDefault();
        dropzone.classList.add('is-active');
    }));
    ['dragleave', 'drop'].forEach(evt => dropzone.addEventListener(evt, e => {
        e.preventDefault();
        dropzone.classList.remove('is-active');
    }));
    dropzone.addEventListener('drop', e => {
        if (e.dataTransfer.files.length) input.files = e.dataTransfer.files;
        updateLabel();
    });
    input.addEventListener('change', updateLabel);

    function updateLabel() {
        const title = dropzone.querySelector('.app-dropzone-title');
        if (input.files && input.files[0]) {
            title.textContent = input.files[0].name;
        }
    }
});
</script>
