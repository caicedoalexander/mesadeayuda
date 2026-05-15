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
            <div class="app-login-topbar">
                <span class="app-login-status">
                    <span class="dot"></span>
                    Todos los sistemas operativos
                </span>
            </div>

            <div class="app-login-form-inner">
                <div class="app-login-form-head">
                    <h2>Bienvenido de vuelta</h2>
                    <p>Ingresa con tu cuenta corporativa para acceder al panel.</p>
                </div>

                <?= $this->element('loading_spinner', ['message' => 'Iniciando sesión...']) ?>

                <?= $this->Form->create(null, ['class' => 'app-login-form-body', 'id' => 'login-form']) ?>

                    <button type="button" class="app-sso-btn" disabled data-tip="SSO próximamente" data-tip-side="bottom">
                        <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true">
                            <path d="M17.64 9.205c0-.639-.057-1.252-.164-1.841H9v3.481h4.844a4.14 4.14 0 0 1-1.796 2.716v2.259h2.908c1.702-1.567 2.684-3.875 2.684-6.615z" fill="#4285F4"/>
                            <path d="M9 18c2.43 0 4.467-.806 5.956-2.18l-2.908-2.259c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332A8.997 8.997 0 0 0 9 18z" fill="#34A853"/>
                            <path d="M3.964 10.71A5.41 5.41 0 0 1 3.682 9c0-.593.102-1.17.282-1.71V4.958H.957A8.996 8.996 0 0 0 0 9c0 1.452.348 2.827.957 4.042l3.007-2.332z" fill="#FBBC05"/>
                            <path d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0A8.997 8.997 0 0 0 .957 4.958L3.964 7.29C4.672 5.163 6.656 3.58 9 3.58z" fill="#EA4335"/>
                        </svg>
                        Continuar con Google Workspace
                    </button>

                    <div class="app-login-divider"><span>o con email</span></div>

                    <div>
                        <label for="email" class="app-form-label" style="margin-bottom: 6px; display: block;">
                            Correo corporativo
                        </label>
                        <div class="app-input-group" id="email-group">
                            <i class="bi bi-envelope"></i>
                            <?= $this->Form->email('email', [
                                'id' => 'email',
                                'placeholder' => 'tu@correo.com',
                                'required' => true,
                                'autofocus' => true,
                                'label' => false,
                            ]) ?>
                            <i class="bi bi-check2 app-input-icon success" id="email-valid" style="display: none; color: var(--admin-green);"></i>
                        </div>
                    </div>

                    <div>
                        <div class="app-login-label-row">
                            <label for="password">Contraseña</label>
                            <a href="mailto:soporte@operadoracafetera.com?subject=Recuperar%20contrase%C3%B1a" class="app-login-forgot">
                                ¿Olvidaste tu contraseña?
                            </a>
                        </div>
                        <div class="app-input-group">
                            <i class="bi bi-lock"></i>
                            <?= $this->Form->password('password', [
                                'id' => 'password',
                                'placeholder' => '••••••••',
                                'required' => true,
                                'label' => false,
                            ]) ?>
                            <button type="button" class="app-input-trail" id="toggle-password" data-tip="Mostrar contraseña" data-tip-side="left" aria-label="Mostrar contraseña">
                                <i class="bi bi-eye" id="toggle-password-icon"></i>
                            </button>
                        </div>
                    </div>

                    <div class="app-login-remember-row">
                        <label class="app-login-check">
                            <?= $this->Form->checkbox('remember_me', ['id' => 'remember_me', 'hiddenField' => false]) ?>
                            Mantener sesión iniciada
                        </label>
                    </div>

                    <?= $this->Form->button(
                        'Iniciar sesión <i class="bi bi-chevron-right"></i>',
                        ['class' => 'btn-brand-primary app-login-submit', 'escapeTitle' => false]
                    ) ?>
                <?= $this->Form->end() ?>

                <?= $this->Html->script('login', ['block' => 'script']) ?>

                <div class="app-login-help">
                    <div class="app-login-help-icon">
                        <i class="bi bi-question-circle"></i>
                    </div>
                    <div class="app-login-help-text">
                        <div class="app-login-help-title">¿No tienes cuenta?</div>
                        <div class="app-login-help-message">
                            Solicita acceso a tu administrador o envía un correo a
                            <a href="mailto:soporte@operadoracafetera.com">soporte@operadoracafetera.com</a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="app-login-foot">
                &copy; <?= date('Y') ?> Compañía Operadora Portuaria Cafetera S.A.
                <span>·</span>
                <span class="mono">Todos los derechos reservados</span>
            </div>
        </div>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Email validation indicator
    const email = document.getElementById('email');
    const valid = document.getElementById('email-valid');
    if (email && valid) {
        const update = () => {
            valid.style.display = (email.value && email.checkValidity()) ? 'inline-block' : 'none';
        };
        email.addEventListener('input', update);
        update();
    }

    // Password show/hide toggle
    const password = document.getElementById('password');
    const toggle = document.getElementById('toggle-password');
    const icon = document.getElementById('toggle-password-icon');
    if (password && toggle && icon) {
        toggle.addEventListener('click', function () {
            const hidden = password.type === 'password';
            password.type = hidden ? 'text' : 'password';
            icon.className = hidden ? 'bi bi-eye-slash' : 'bi bi-eye';
            toggle.setAttribute('data-tip', hidden ? 'Ocultar contraseña' : 'Mostrar contraseña');
            toggle.setAttribute('aria-label', hidden ? 'Ocultar contraseña' : 'Mostrar contraseña');
        });
    }
});
</script>
