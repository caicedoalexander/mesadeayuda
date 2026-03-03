<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Organization $organization
 */
$this->assign('title', 'Nueva Organización');
?>

<style>
:root {
    --admin-green: #00A85E;
    --admin-orange: #CD6A15;
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
    --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    --gradient-celebrate: linear-gradient(135deg, #00A85E 0%, #CD6A15 100%);
}

.add-organization-page {
    padding: 2rem;
    max-width: 700px;
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
    border: 2px solid var(--admin-green);
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.header-icon i {
    font-size: 28px;
    color: var(--admin-green);
}

.header-text h3 {
    font-size: 2rem;
    font-weight: 700;
    color: var(--gray-900);
    margin: 0;
    line-height: 1.2;
}

/* Form Card */
.organization-card {
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-lg);
    overflow: hidden;
    position: relative;
}

.organization-card::before {
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

/* Form Group */
.form-group {
    margin-bottom: 2rem;
}

.form-group label {
    display: block;
    font-size: 0.95rem;
    font-weight: 600;
    color: var(--gray-700);
    margin-bottom: 0.625rem;
}

.form-group input[type="text"] {
    width: 100%;
    padding: 0.875rem 1.125rem;
    border: 2px solid var(--gray-200);
    border-radius: var(--radius-md);
    font-size: 1rem;
    color: var(--gray-900);
    background: white;
    transition: var(--transition);
    font-family: inherit;
}

.form-group input[type="text"]:focus {
    outline: none;
    border-color: var(--admin-green);
    box-shadow: 0 0 0 3px rgba(0, 168, 94, 0.1);
}

.form-group input::placeholder {
    color: var(--gray-400);
}

.form-group .error-message {
    color: #dc3545;
    font-size: 0.875rem;
    margin-top: 0.5rem;
}

/* Form Actions */
.form-actions {
    display: flex;
    gap: 1rem;
    padding-top: 2rem;
    border-top: 1px solid var(--gray-200);
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
    flex: 1;
    justify-content: center;
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
    flex: 1;
    justify-content: center;
}

.btn-cancel:hover {
    background: var(--gray-100);
    border-color: var(--gray-400);
    color: var(--gray-900);
}

/* Info Text */
.info-text {
    background: linear-gradient(135deg, #E6F7F0 0%, #CCF0E1 100%);
    border-left: 4px solid var(--admin-green);
    padding: 1rem 1.25rem;
    border-radius: var(--radius-md);
    margin-bottom: 2rem;
}

.info-text p {
    margin: 0;
    font-size: 0.95rem;
    color: var(--gray-700);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.info-text i {
    color: var(--admin-green);
    font-size: 1.25rem;
}

/* Responsive */
@media (max-width: 768px) {
    .add-organization-page {
        padding: 1rem;
    }

    .form-content {
        padding: 1.5rem;
    }

    .form-actions {
        flex-direction: column;
    }

    .btn-submit,
    .btn-cancel {
        width: 100%;
    }
}
</style>

<div class="add-organization-page">
    <!-- Page Header -->
    <div class="page-header">
        <div class="header-icon">
            <i class="bi bi-building-add"></i>
        </div>
        <div class="header-text">
            <h3>Nueva Organización</h3>
        </div>
    </div>

    <?= $this->Flash->render() ?>

    <?= $this->Form->create($organization) ?>
    <div class="organization-card">
        <div class="form-content">

            <!-- Info Text -->
            <div class="info-text">
                <p>
                    <i class="bi bi-info-circle"></i>
                    Las organizaciones permiten agrupar usuarios y gestionar permisos de manera centralizada.
                </p>
            </div>

            <!-- Form Field -->
            <div class="form-group">
                <?= $this->Form->label('name', 'Nombre de la Organización') ?>
                <?= $this->Form->text('name', [
                    'placeholder' => 'Ej: Acme Corporation',
                    'required' => true
                ]) ?>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <?= $this->Html->link(
                    'Cancelar',
                    ['controller' => 'Organizations', 'action' => 'index'],
                    ['class' => 'btn-cancel']
                ) ?>
                <?= $this->Form->button(
                    '<i class="bi bi-check-circle"></i> Guardar Organización',
                    ['class' => 'btn-submit', 'escapeTitle' => false]
                ) ?>
            </div>

        </div>
    </div>
    <?= $this->Form->end() ?>
</div>
