<?php
/**
 * @var \App\View\AppView $this
 * @var array $settings
 * @var \App\Model\Entity\User $user
 */
$this->assign('title', 'Editar usuario');
?>

<style>
/**
 * Edit User Form - Modern Minimalist Design
 */

:root {
    --edit-user-green: #00A85E;
    --edit-user-orange: #CD6A15;
    --gradient-primary: linear-gradient(135deg, #00A85E 0%, #00D477 100%);
    --gradient-accent: linear-gradient(135deg, #CD6A15 0%, #F07D2D 100%);
    --gradient-celebrate: linear-gradient(135deg, #00A85E 0%, #CD6A15 100%);

    /* Neutrals */
    --gray-50: #F9FAFB;
    --gray-100: #F3F4F6;
    --gray-200: #E5E7EB;
    --gray-300: #D1D5DB;
    --gray-400: #9CA3AF;
    --gray-500: #6B7280;
    --gray-600: #4B5563;
    --gray-700: #374151;
    --gray-800: #1F2937;
    --gray-900: #111827;

    /* Shadows */
    --shadow-sm: 0 1px 3px 0 rgba(0, 0, 0, 0.08);
    --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.08);
    --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.08);
    --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
    --shadow-green: 0 10px 30px -10px rgba(0, 168, 94, 0.25);
    --shadow-orange: 0 10px 30px -10px rgba(205, 106, 21, 0.25);

    /* Transitions */
    --transition-fast: 150ms cubic-bezier(0.4, 0, 0.2, 1);
    --transition-base: 250ms cubic-bezier(0.4, 0, 0.2, 1);
    --transition-smooth: 350ms cubic-bezier(0.4, 0, 0.2, 1);

    /* Border Radius */
    --radius-sm: 6px;
    --radius-md: 10px;
    --radius-lg: 14px;
    --radius-xl: 20px;
    --radius-full: 9999px;
}

/* Container */
.edit-user-container {
    max-width: 900px;
    width: 100%;
    margin: 0 auto;
    padding: 2rem 1.5rem;
    font-family: 'Manrope', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    position: relative;
}

/* Page Header */
.page-header {
    margin-bottom: 2rem;
    opacity: 0;
    transform: translateY(20px);
    animation: fadeUpIn 0.6s ease-out 0.1s forwards;
}

@keyframes fadeUpIn {
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.page-header h3 {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--gray-900);
    margin-bottom: 0.5rem;
    letter-spacing: -0.02em;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.page-header h3 i {
    color: var(--edit-user-green);
    font-size: 1.5rem;
}

.page-header p {
    font-size: 0.9375rem;
    color: var(--gray-600);
    margin: 0;
    font-weight: 400;
}

.page-header p strong {
    color: var(--gray-900);
    font-weight: 600;
}

/* Main Form Card */
.edit-user-card {
    background: white;
    border-radius: var(--radius-xl);
    border: 1px solid var(--gray-200);
    box-shadow: var(--shadow-lg);
    padding: 3rem;
    position: relative;
    overflow: hidden;
    opacity: 0;
    transform: translateY(30px);
    animation: fadeUpIn 0.6s ease-out 0.2s forwards;
}

.edit-user-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: var(--gradient-celebrate);
    box-shadow: 0 4px 15px rgba(0, 168, 94, 0.3);
}

/* Form Section */
.form-section {
    margin-bottom: 3rem;
    padding-bottom: 2rem;
    border-bottom: 2px solid var(--gray-100);
}

.form-section:last-of-type {
    border-bottom: none;
    margin-bottom: 2rem;
}

.form-section-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 2rem;
}

.form-section-icon {
    width: 48px;
    height: 48px;
    flex-shrink: 0;
    background: linear-gradient(135deg, #E6F7F0 0%, #CCF0E1 100%);
    border: 2px solid var(--edit-user-green);
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all var(--transition-smooth);
}

.form-section-icon.orange {
    background: linear-gradient(135deg, #FEF3EC 0%, #FCE7D9 100%);
    border-color: var(--edit-user-orange);
}

.form-section-icon i {
    font-size: 1.25rem;
    color: var(--edit-user-green);
    transition: all var(--transition-smooth);
}

.form-section-icon.orange i {
    color: var(--edit-user-orange);
}

.form-section:hover .form-section-icon {
    transform: scale(1.05);
}

.form-section-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--gray-900);
    margin: 0;
    letter-spacing: -0.01em;
}

.form-section-subtitle {
    font-size: 0.875rem;
    color: var(--gray-600);
    margin: 0.5rem 0 0 0;
    font-weight: 400;
}

/* Profile Image Section */
.profile-image-section {
    display: flex;
    align-items: center;
    gap: 2rem;
    padding: 2rem;
    background: linear-gradient(135deg, var(--gray-50) 0%, white 100%);
    border-radius: var(--radius-lg);
    border: 2px solid var(--gray-200);
    transition: all var(--transition-smooth);
}

.profile-image-section:hover {
    border-color: var(--edit-user-green);
    box-shadow: var(--shadow-md);
}

.profile-image-wrapper {
    position: relative;
    flex-shrink: 0;
}

.profile-image-wrapper img {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid white;
    box-shadow: var(--shadow-lg);
    transition: all var(--transition-smooth);
}

.profile-image-section:hover .profile-image-wrapper img {
    transform: scale(1.05);
    box-shadow: var(--shadow-xl);
}

.profile-upload-zone {
    flex: 1;
}

.profile-upload-label {
    display: block;
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--gray-700);
    margin-bottom: 0.75rem;
}

.profile-upload-input {
    width: 100%;
    padding: 0.875rem 1rem;
    font-size: 0.9375rem;
    font-weight: 500;
    color: var(--gray-900);
    background: white;
    border: 2px solid var(--gray-200);
    border-radius: var(--radius-md);
    transition: all var(--transition-smooth);
    font-family: 'Manrope', sans-serif;
}

.profile-upload-input:focus {
    outline: none;
    border-color: var(--edit-user-green);
    box-shadow: 0 0 0 4px rgba(0, 168, 94, 0.1);
}

.profile-upload-hint {
    display: block;
    margin-top: 0.5rem;
    font-size: 0.8125rem;
    color: var(--gray-500);
}

/* Form Groups */
.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-label {
    display: block;
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--gray-700);
    margin-bottom: 0.5rem;
    letter-spacing: 0.01em;
}

.form-control,
.form-select {
    width: 100%;
    padding: 0.875rem 1rem;
    font-size: 0.9375rem;
    font-weight: 500;
    color: var(--gray-900);
    background: white;
    border: 2px solid var(--gray-200);
    border-radius: var(--radius-md);
    transition: all var(--transition-smooth);
    font-family: 'Manrope', sans-serif;
}

.form-control:focus,
.form-select:focus {
    outline: none;
    border-color: var(--edit-user-green);
    box-shadow: 0 0 0 4px rgba(0, 168, 94, 0.1);
}

.form-control::placeholder {
    color: var(--gray-400);
    font-weight: 400;
}

/* Helper Text */
small.form-text {
    display: block;
    margin-top: 0.5rem;
    font-size: 0.8125rem;
    color: var(--gray-500);
    line-height: 1.4;
}

/* Checkbox Group */
.checkbox-group {
    padding: 1.25rem;
    background: var(--gray-50);
    border-radius: var(--radius-md);
    border: 2px solid var(--gray-200);
    transition: all var(--transition-smooth);
}

.checkbox-group:hover {
    border-color: var(--edit-user-green);
    background: white;
}

.checkbox-group label {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-size: 0.9375rem;
    font-weight: 600;
    color: var(--gray-900);
    margin-bottom: 0.5rem;
    cursor: pointer;
}

.checkbox-group input[type="checkbox"] {
    width: 20px;
    height: 20px;
    cursor: pointer;
    accent-color: var(--edit-user-green);
}

.checkbox-group small {
    display: block;
    margin-left: 2rem;
    font-size: 0.8125rem;
    color: var(--gray-600);
}

/* Action Buttons */
.form-actions {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding-top: 1rem;
}

.btn-success {
    padding: 1rem 2rem;
    font-size: 1.0625rem;
    font-weight: 700;
    color: white;
    background: var(--gradient-celebrate);
    border: none;
    border-radius: var(--radius-md);
    cursor: pointer;
    transition: all var(--transition-smooth);
    box-shadow: 0 4px 15px rgba(0, 168, 94, 0.3);
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-success:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0, 168, 94, 0.4);
}

.btn-success:active {
    transform: translateY(0);
}

.btn-secondary {
    padding: 1rem 2rem;
    font-size: 1.0625rem;
    font-weight: 600;
    color: var(--gray-700);
    background: white;
    border: 2px solid var(--gray-300);
    border-radius: var(--radius-md);
    text-decoration: none;
    cursor: pointer;
    transition: all var(--transition-smooth);
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-secondary:hover {
    background: var(--gray-50);
    border-color: var(--gray-400);
    color: var(--gray-900);
    text-decoration: none;
}

/* Responsive Design */
@media (max-width: 768px) {
    .edit-user-container {
        padding: 1.5rem 1rem;
    }

    .edit-user-card {
        padding: 2rem 1.5rem;
    }

    .profile-image-section {
        flex-direction: column;
        text-align: center;
    }

    .form-row {
        grid-template-columns: 1fr;
    }

    .form-actions {
        flex-direction: column;
        width: 100%;
    }

    .form-actions .btn-success,
    .form-actions .btn-secondary {
        width: 100%;
        justify-content: center;
    }
}

/* Accessibility */
@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
}
</style>

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
