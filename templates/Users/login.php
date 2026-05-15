<?php
/**
 * @var \App\View\AppView $this
 */
$this->assign('title', 'Iniciar sesión');
?>

<div class="app-login-shell">
    <section class="app-login-card">
        <aside class="app-login-brand">
            <div class="app-login-brand-mark">
                <?= $this->Html->image('logos/logo-mesa-ayuda.svg', ['alt' => '']) ?>
            </div>
            <div class="app-login-brand-text">
                <h1>Mesa de <span class="serif-italic">Ayuda</span></h1>
                <p>Soporte interno · COPC S.A.</p>
            </div>

            <div class="app-login-brand-glow" aria-hidden="true">
                <span class="glow glow-1"></span>
                <span class="glow glow-2"></span>
                <span class="glow glow-3"></span>
            </div>

            <div class="app-login-brand-meta">
                <div class="meta-row">
                    <span class="meta-dot" style="background: var(--admin-green);"></span>
                    Sistema operativo
                </div>
                <div class="meta-row mono">v<?= h(date('Y.m.d')) ?></div>
            </div>
        </aside>

        <div class="app-login-form">
            <div class="app-login-form-head">
                <h2>Bienvenido de vuelta</h2>
                <p>Inicia sesión para continuar.</p>
            </div>

            <?= $this->element('loading_spinner', ['message' => 'Iniciando sesión...']) ?>

            <?= $this->Form->create(null, ['class' => 'app-login-form-body']) ?>
                <div class="app-form-group">
                    <?= $this->Form->label('email', 'Correo electrónico') ?>
                    <?= $this->Form->email('email', [
                        'id' => 'email',
                        'placeholder' => 'ejemplo@correo.com',
                        'required' => true,
                        'autofocus' => true,
                    ]) ?>
                </div>

                <div class="app-form-group">
                    <?= $this->Form->label('password', 'Contraseña') ?>
                    <?= $this->Form->password('password', [
                        'id' => 'password',
                        'placeholder' => '••••••••',
                        'required' => true,
                    ]) ?>
                </div>

                <?= $this->Form->button(
                    '<i class="bi bi-box-arrow-in-right"></i> Iniciar sesión',
                    ['class' => 'btn-brand-primary btn-brand-lg app-login-submit', 'escapeTitle' => false]
                ) ?>
            <?= $this->Form->end() ?>

            <?= $this->Html->script('login', ['block' => 'script']) ?>

            <div class="app-login-foot">
                &copy; <?= date('Y') ?> Compañía Operadora Portuaria Cafetera S.A.
                <span>·</span>
                <span class="mono">Todos los derechos reservados</span>
            </div>
        </div>
    </section>
</div>
