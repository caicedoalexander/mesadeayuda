<?php
/**
 * @var \App\View\AppView $this
 * @var array $settings
 */
$this->assign('title', 'Añadir Etiqueta');
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

.add-tag-page {
    padding: 2rem;
    max-width: 800px;
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
.tag-card {
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-lg);
    overflow: hidden;
    position: relative;
}

.tag-card::before {
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
    border: 2px solid var(--admin-green);
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.section-icon i {
    font-size: 20px;
    color: var(--admin-green);
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
.form-group textarea {
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
.form-group textarea:focus {
    outline: none;
    border-color: var(--admin-green);
    box-shadow: 0 0 0 3px rgba(0, 168, 94, 0.1);
}

.form-group textarea {
    resize: vertical;
    min-height: 100px;
}

.form-group small {
    font-size: 0.85rem;
    color: var(--gray-600);
    margin-top: 0.375rem;
}

/* Color Picker */
.color-input-wrapper {
    display: flex;
    gap: 1rem;
    align-items: stretch;
}

.color-picker {
    width: 80px;
    height: 48px;
    border: 2px solid var(--gray-200);
    border-radius: var(--radius-md);
    cursor: pointer;
    padding: 4px;
    transition: var(--transition);
}

.color-picker:hover {
    border-color: var(--admin-green);
}

.color-hex-input {
    flex: 1;
    font-family: 'Courier New', monospace;
    text-transform: uppercase;
    font-weight: 600;
    letter-spacing: 0.5px;
}

/* Preview Section */
.tag-preview {
    background: linear-gradient(135deg, var(--gray-50) 0%, var(--gray-100) 100%);
    border: 2px dashed var(--gray-300);
    border-radius: var(--radius-lg);
    padding: 3rem 2rem;
    text-align: center;
    min-height: 150px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.preview-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.75rem 1.5rem;
    border-radius: var(--radius-md);
    color: white;
    font-size: 1.125rem;
    font-weight: 700;
    background-color: #0066cc;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
    transition: var(--transition);
    animation: previewPulse 2s ease-in-out infinite;
}

@keyframes previewPulse {
    0%, 100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.05);
    }
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

/* Responsive */
@media (max-width: 768px) {
    .add-tag-page {
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
    }
}
</style>

<div class="add-tag-page">
    <!-- Page Header -->
    <div class="page-header">
        <div class="header-icon">
            <i class="bi bi-tag"></i>
        </div>
        <div class="header-text">
            <h1>Nueva Etiqueta</h1>
            <p>Crear una nueva etiqueta para organizar tickets</p>
        </div>
    </div>

    <?= $this->Flash->render() ?>

    <?= $this->Form->create($tag) ?>
    <div class="tag-card">
        <div class="form-content">

            <!-- Información de la Etiqueta -->
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="bi bi-info-circle"></i>
                    </div>
                    <h3>Información de la Etiqueta</h3>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <?= $this->Form->label('name', 'Nombre *') ?>
                        <?= $this->Form->text('name', [
                            'placeholder' => 'Ej: Urgente, Bug, Pregunta',
                            'required' => true
                        ]) ?>
                        <small>Nombre corto y descriptivo para la etiqueta</small>
                    </div>

                    <div class="form-group">
                        <?= $this->Form->label('color', 'Color *') ?>
                        <div class="color-input-wrapper">
                            <?= $this->Form->color('color', [
                                'class' => 'color-picker',
                                'id' => 'tag-color',
                                'value' => '#0066cc',
                                'required' => true
                            ]) ?>
                            <input type="text" id="color-hex" class="color-hex-input"
                                   value="#0066CC" readonly>
                        </div>
                        <small>Color para identificar visualmente la etiqueta</small>
                    </div>
                </div>

                <div class="form-group">
                    <?= $this->Form->label('description', 'Descripción') ?>
                    <?= $this->Form->textarea('description', [
                        'rows' => 3,
                        'placeholder' => 'Describe cuándo usar esta etiqueta...'
                    ]) ?>
                    <small>Ayuda a otros usuarios a entender cuándo aplicar esta etiqueta</small>
                </div>
            </div>

            <!-- Vista Previa -->
            <div class="form-section">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="bi bi-eye"></i>
                    </div>
                    <h3>Vista Previa</h3>
                </div>

                <div class="tag-preview">
                    <span class="preview-badge" id="preview-badge" style="background-color: #0066cc">
                        <span id="preview-text">Nombre de etiqueta</span>
                    </span>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <?= $this->Html->link(
                    'Cancelar',
                    ['action' => 'tags'],
                    ['class' => 'btn-cancel']
                ) ?>
                <?= $this->Form->button(
                    '<i class="bi bi-check-circle"></i> Crear Etiqueta',
                    ['class' => 'btn-submit', 'escapeTitle' => false]
                ) ?>
            </div>

        </div>
    </div>
    <?= $this->Form->end() ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const colorPicker = document.getElementById('tag-color');
    const colorHex = document.getElementById('color-hex');
    const previewBadge = document.getElementById('preview-badge');
    const previewText = document.getElementById('preview-text');
    const nameInput = document.querySelector('input[name="name"]');

    // Update preview when color changes
    colorPicker.addEventListener('input', function() {
        const color = this.value;
        colorHex.value = color.toUpperCase();
        previewBadge.style.backgroundColor = color;
    });

    // Update preview when name changes
    nameInput.addEventListener('input', function() {
        previewText.textContent = this.value || 'Nombre de etiqueta';
    });
});
</script>
