<?php
/**
 * @var \App\View\AppView $this
 * @var array $settings
 * @var \App\Model\Entity\User $user
 */
$this->assign('title', 'Editar usuario');
?>

<?= $this->Html->css('admin/edit-user', ['block' => 'css']) ?>

<div class="edit-user-container">
    <!-- Page Header -->
    <div class="page-header">
        <h3><i class="bi bi-person-gear"></i> Editar Usuario</h3>
        <p>Modificar información de: <strong><?= h($user->name) ?></strong></p>
    </div>

    <?= $this->Flash->render() ?>

    <!-- Form Card -->
    <div class="edit-user-card">
        <?= $this->Form->create($user, ['type' => 'file']) ?>

        <!-- Profile Image Section -->
        <div class="form-section">
            <div class="form-section-header">
                <div class="form-section-icon">
                    <i class="bi bi-camera-fill"></i>
                </div>
                <h3 class="form-section-title">Foto de Perfil</h3>
            </div>

            <div class="profile-image-section">
                <div class="profile-image-wrapper">
                    <?= $this->User->profileImageTag($user, [
                        'width' => '120',
                        'height' => '120',
                        'class' => ''
                    ]) ?>
                </div>
                <div class="profile-upload-zone">
                    <label for="profile-image-upload" class="profile-upload-label">
                        Cambiar foto de perfil
                    </label>
                    <?= $this->Form->file('profile_image_upload', [
                        'accept' => 'image/jpeg,image/png,image/gif,image/webp',
                        'class' => 'profile-upload-input',
                        'id' => 'profile-image-upload'
                    ]) ?>
                    <small class="profile-upload-hint">
                        Formatos permitidos: JPG, PNG, GIF, WEBP. Tamaño máximo: 2MB
                    </small>
                </div>
            </div>
        </div>

        <!-- Personal Information Section -->
        <div class="form-section">
            <div class="form-section-header">
                <div class="form-section-icon">
                    <i class="bi bi-person-fill"></i>
                </div>
                <h3 class="form-section-title">Información Personal</h3>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="first-name" class="form-label">Nombre *</label>
                    <?= $this->Form->text('first_name', [
                        'class' => 'form-control',
                        'id' => 'first-name',
                        'placeholder' => 'Ej: Juan',
                        'required' => true
                    ]) ?>
                </div>

                <div class="form-group">
                    <label for="last-name" class="form-label">Apellido *</label>
                    <?= $this->Form->text('last_name', [
                        'class' => 'form-control',
                        'id' => 'last-name',
                        'placeholder' => 'Ej: Pérez',
                        'required' => true
                    ]) ?>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="email" class="form-label">Correo Electrónico *</label>
                    <?= $this->Form->email('email', [
                        'class' => 'form-control',
                        'id' => 'email',
                        'placeholder' => 'ejemplo@correo.com',
                        'required' => true
                    ]) ?>
                </div>

            </div>
        </div>

        <!-- Account Configuration Section -->
        <div class="form-section">
            <div class="form-section-header">
                <div class="form-section-icon orange">
                    <i class="bi bi-gear-fill"></i>
                </div>
                <h3 class="form-section-title">Configuración de Cuenta</h3>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="role" class="form-label">Rol *</label>
                    <?= $this->Form->select('role', [
                        'admin' => 'Administrador',
                        'agent' => 'Agente',
                        'servicio_cliente' => 'Servicio al Cliente',
                        'requester' => 'Solicitante'
                    ], [
                        'value' => h($user->role),
                        'class' => 'form-select',
                        'id' => 'role'
                    ]) ?>
                    <small class="form-text">Define los permisos del usuario</small>
                </div>

                <div class="form-group">
                    <div class="checkbox-group">
                        <label for="is-active">
                            <?= $this->Form->checkbox('is_active', ['id' => 'is-active']) ?>
                            Cuenta activa
                        </label>
                        <small>Los usuarios inactivos no pueden iniciar sesión</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Change Password Section -->
        <div class="form-section">
            <div class="form-section-header">
                <div class="form-section-icon">
                    <i class="bi bi-shield-lock-fill"></i>
                </div>
                <div>
                    <h3 class="form-section-title">Cambiar Contraseña</h3>
                    <p class="form-section-subtitle">Deja en blanco si no deseas cambiar la contraseña</p>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="new-password" class="form-label">Nueva Contraseña</label>
                    <?= $this->Form->password('new_password', [
                        'class' => 'form-control',
                        'id' => 'new-password',
                        'value' => '',
                        'autocomplete' => 'new-password',
                        'placeholder' => '••••••••'
                    ]) ?>
                </div>

                <div class="form-group">
                    <label for="confirm-password" class="form-label">Confirmar Contraseña</label>
                    <?= $this->Form->password('confirm_password', [
                        'class' => 'form-control',
                        'id' => 'confirm-password',
                        'value' => '',
                        'autocomplete' => 'new-password',
                        'placeholder' => '••••••••'
                    ]) ?>
                </div>
            </div>
            <small class="form-text">Mínimo 6 caracteres</small>
        </div>

        <!-- Form Actions -->
        <div class="form-actions">
            <?= $this->Form->button('<i class="bi bi-check-circle-fill"></i> Guardar Cambios', [
                'class' => 'btn-success',
                'escapeTitle' => false,
                'type' => 'submit'
            ]) ?>
            <?= $this->Html->link('Cancelar', ['action' => 'users'], [
                'class' => 'btn-secondary'
            ]) ?>
        </div>

        <?= $this->Form->end() ?>
    </div>
</div>
