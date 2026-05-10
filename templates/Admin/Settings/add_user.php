<?php
/**
 * @var \App\View\AppView $this
 * @var array $settings
 */
$this->assign('title', 'Añadir Usuario');
?>

<?= $this->Html->css('admin/add-user', ['block' => 'css']) ?>

<div class="add-user-page">
    <!-- Page Header -->
    <div class="page-header">
        <div class="header-icon">
            <i class="bi bi-person-add"></i>
        </div>
        <div class="header-text">
            <h1>Nuevo Usuario</h1>
            <p>Crear un nuevo usuario en el sistema</p>
        </div>
    </div>

    <?= $this->Flash->render() ?>

    <?= $this->Form->create($user) ?>
    <div class="add-user-card">
        <div class="form-content">

            <!-- Información Personal -->
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="bi bi-person"></i>
                    </div>
                    <h3>Información Personal</h3>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <?= $this->Form->label('first_name', 'Nombre *') ?>
                        <?= $this->Form->text('first_name', [
                            'placeholder' => 'Ej: Juan',
                            'required' => true
                        ]) ?>
                    </div>

                    <div class="form-group">
                        <?= $this->Form->label('last_name', 'Apellido *') ?>
                        <?= $this->Form->text('last_name', [
                            'placeholder' => 'Ej: Pérez',
                            'required' => true
                        ]) ?>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <?= $this->Form->label('email', 'Correo Electrónico *') ?>
                        <?= $this->Form->email('email', [
                            'placeholder' => 'ejemplo@correo.com',
                            'required' => true
                        ]) ?>
                    </div>

                </div>
            </div>

            <!-- Configuración de Cuenta -->
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon orange">
                        <i class="bi bi-gear"></i>
                    </div>
                    <h3>Configuración de Cuenta</h3>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <?= $this->Form->label('role', 'Rol *') ?>
                        <?= $this->Form->select('role', [
                            'admin' => 'Administrador',
                            'agent' => 'Agente',
                            'servicio_cliente' => 'Servicio al Cliente',
                            'requester' => 'Solicitante'
                        ], [
                            'required' => true
                        ]) ?>
                        <small>Define los permisos del usuario en el sistema</small>
                    </div>

                    <div class="form-group">
                        <div class="checkbox-group">
                            <label>
                                <?= $this->Form->checkbox('is_active', [
                                    'id' => 'is_active',
                                    'checked' => true
                                ]) ?>
                                Cuenta activa
                            </label>
                            <small>Los usuarios inactivos no pueden iniciar sesión</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contraseña -->
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="bi bi-shield-lock"></i>
                    </div>
                    <h3>Contraseña</h3>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <?= $this->Form->label('password', 'Contraseña *') ?>
                        <?= $this->Form->password('password', [
                            'required' => true,
                            'autocomplete' => 'new-password',
                            'placeholder' => '••••••••'
                        ]) ?>
                        <small>Mínimo 6 caracteres</small>
                    </div>

                    <div class="form-group">
                        <?= $this->Form->label('confirm_password', 'Confirmar Contraseña *') ?>
                        <?= $this->Form->password('confirm_password', [
                            'required' => true,
                            'autocomplete' => 'new-password',
                            'placeholder' => '••••••••'
                        ]) ?>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <?= $this->Form->button('<i class="bi bi-check-circle"></i> Crear Usuario', [
                    'class' => 'btn-submit',
                    'escapeTitle' => false
                ]) ?>
                <?= $this->Html->link('Cancelar', ['action' => 'users'], [
                    'class' => 'btn-cancel'
                ]) ?>
            </div>

        </div>
    </div>
    <?= $this->Form->end() ?>
</div>
