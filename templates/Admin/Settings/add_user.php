<?php
/**
 * @var \App\View\AppView $this
 * @var array $settings
 */
$this->assign('title', 'Añadir Usuario');
?>

<style>
:root {
    --add-user-green: #00A85E;
    --add-user-orange: #CD6A15;
    --gray-50: #F9FAFB;
    --gray-100: #F3F4F6;
    --gray-200: #E5E7EB;
    --gray-300: #D1D5DB;
    --gray-600: #4B5563;
    --gray-700: #374151;
    --gray-900: #111827;
    --radius-lg: 12px;
    --radius-md: 8px;
    --radius-sm: 6px;
    --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    --gradient-celebrate: linear-gradient(135deg, #00A85E 0%, #CD6A15 100%);
}

.add-user-page {
    padding: 2rem;
    max-width: 900px;
    margin: 0 auto;
    animation: fadeIn 0.4s ease-out;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Page Header */
.page-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 2rem;
}

.header-icon {
    width: 56px;
    height: 56px;
    background: linear-gradient(135deg, #E6F7F0 0%, #CCF0E1 100%);
    border: 2px solid var(--add-user-green);
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.header-icon i {
    font-size: 28px;
    color: var(--add-user-green);
}

.header-text h1 {
    font-size: 2rem;
    font-weight: 700;
    color: var(--gray-900);
    margin: 0;
    line-height: 1.2;
}

.header-text p {
    font-size: 1rem;
    color: var(--gray-600);
    margin: 0.25rem 0 0 0;
}

/* Form Card */
.add-user-card {
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-lg);
    overflow: hidden;
    position: relative;
}

.add-user-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: var(--gradient-celebrate);
    box-shadow: 0 4px 15px rgba(0, 168, 94, 0.3);
}

.form-content {
    padding: 2.5rem;
}

/* Form Sections */
.form-section {
    margin-bottom: 2.5rem;
    padding-bottom: 2.5rem;
    border-bottom: 1px solid var(--gray-200);
}

.form-section:last-of-type {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}

.section-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1.75rem;
}

.section-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #E6F7F0 0%, #CCF0E1 100%);
    border: 2px solid var(--add-user-green);
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.section-icon.orange {
    background: linear-gradient(135deg, #FEF3EC 0%, #FCE7D9 100%);
    border-color: var(--add-user-orange);
}

.section-icon i {
    font-size: 20px;
    color: var(--add-user-green);
}

.section-icon.orange i {
    color: var(--add-user-orange);
}

.section-header h3 {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--gray-900);
    margin: 0;
}

/* Form Grid */
.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

.form-row:last-child {
    margin-bottom: 0;
}

/* Form Groups */
.form-group {
    display: flex;
    flex-direction: column;
}

.form-group label {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--gray-700);
    margin-bottom: 0.5rem;
}

.form-group input[type="text"],
.form-group input[type="email"],
.form-group input[type="password"],
.form-group select {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 2px solid var(--gray-200);
    border-radius: var(--radius-md);
    font-size: 0.95rem;
    color: var(--gray-900);
    background: white;
    transition: var(--transition);
    font-family: inherit;
}

.form-group input[type="text"]:focus,
.form-group input[type="email"]:focus,
.form-group input[type="password"]:focus,
.form-group select:focus {
    outline: none;
    border-color: var(--add-user-green);
    box-shadow: 0 0 0 3px rgba(0, 168, 94, 0.1);
}

.form-group input::placeholder {
    color: var(--gray-400);
}

.form-group small {
    font-size: 0.85rem;
    color: var(--gray-600);
    margin-top: 0.375rem;
}

/* Checkbox Group */
.checkbox-group {
    padding: 1.25rem;
    background: var(--gray-50);
    border-radius: var(--radius-md);
    border: 2px solid var(--gray-200);
    transition: var(--transition);
}

.checkbox-group:hover {
    border-color: var(--add-user-green);
    background: #F0FAF5;
}

.checkbox-group label {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    cursor: pointer;
    font-size: 0.95rem;
    font-weight: 600;
    color: var(--gray-900);
    margin: 0;
}

.checkbox-group input[type="checkbox"] {
    width: 20px;
    height: 20px;
    cursor: pointer;
    accent-color: var(--add-user-green);
}

.checkbox-group small {
    display: block;
    margin-top: 0.5rem;
    margin-left: 1.875rem;
    color: var(--gray-600);
    font-size: 0.85rem;
}

/* Form Actions */
.form-actions {
    display: flex;
    gap: 1rem;
    padding-top: 2rem;
    border-top: 1px solid var(--gray-200);
    margin-top: 2rem;
}

.btn-submit {
    background: var(--gradient-celebrate);
    color: white;
    border: none;
    padding: 0.875rem 2rem;
    border-radius: var(--radius-md);
    font-weight: 600;
    font-size: 1rem;
    cursor: pointer;
    transition: var(--transition);
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    box-shadow: 0 4px 15px rgba(0, 168, 94, 0.3);
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0, 168, 94, 0.4);
}

.btn-submit i {
    font-size: 1.1rem;
}

.btn-cancel {
    background: white;
    color: var(--gray-700);
    border: 2px solid var(--gray-300);
    padding: 0.875rem 2rem;
    border-radius: var(--radius-md);
    font-weight: 600;
    font-size: 1rem;
    cursor: pointer;
    transition: var(--transition);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
}

.btn-cancel:hover {
    background: var(--gray-100);
    border-color: var(--gray-400);
    color: var(--gray-900);
}

/* Responsive */
@media (max-width: 768px) {
    .add-user-page {
        padding: 1rem;
    }

    .form-content {
        padding: 1.5rem;
    }

    .form-row {
        grid-template-columns: 1fr;
    }

    .form-actions {
        flex-direction: column;
    }

    .btn-submit,
    .btn-cancel {
        width: 100%;
        justify-content: center;
    }
}
</style>

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
