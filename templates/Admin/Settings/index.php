<?php
/**
 * @var \App\View\AppView $this
 * @var array $settings
 * @var string $webhookGmailUrl
 * @var string $webhookGmailToken
 * @var string|null $webhookGmailLastRun
 */
use App\Constants\SettingKeys;

$this->assign('title', 'Configuración');
$this->assign('active_workspace', 'settings');

$gmailAuthorized   = !empty($settings['gmail_refresh_token']);
$clientSecretSet   = !empty($settings[SettingKeys::GMAIL_CLIENT_SECRET_JSON] ?? '');
$whatsappEnabled   = ($settings['whatsapp_enabled'] ?? '0') === '1';
$n8nEnabled        = ($settings['n8n_enabled'] ?? '0') === '1';
?>

<header class="app-page-header">
    <nav class="app-breadcrumb" aria-label="breadcrumb">
        <i class="bi bi-grid-1x2"></i>
        <span>Workspace</span>
        <i class="bi bi-chevron-right separator"></i>
        <span class="current">Configuración</span>
    </nav>

    <div class="app-page-header-row">
        <div class="app-page-header-text">
            <h1 class="app-page-title">Configuración del sistema</h1>
            <div class="app-page-stats">
                <span class="stat-inline">
                    <span class="dot" style="background: <?= $gmailAuthorized ? 'var(--admin-green)' : 'var(--admin-orange)' ?>;"></span>
                    <span class="label">Gmail · <?= $gmailAuthorized ? 'conectado' : 'sin autorizar' ?></span>
                </span>
                <span class="stat-inline">
                    <span class="dot" style="background: <?= $whatsappEnabled ? 'var(--admin-green)' : 'var(--gray-400)' ?>;"></span>
                    <span class="label">WhatsApp · <?= $whatsappEnabled ? 'activo' : 'inactivo' ?></span>
                </span>
                <span class="stat-inline">
                    <span class="dot" style="background: <?= $n8nEnabled ? 'var(--admin-green)' : 'var(--gray-400)' ?>;"></span>
                    <span class="label">n8n · <?= $n8nEnabled ? 'activo' : 'inactivo' ?></span>
                </span>
            </div>
        </div>
    </div>
</header>

<!-- 1. General -->
<div class="app-card">
    <div class="app-card-header">
        <div class="app-card-header-icon"><i class="bi bi-sliders"></i></div>
        <div class="app-card-header-text">
            <h3 class="app-card-header-title">Configuración general</h3>
            <div class="app-card-header-subtitle">Título y frecuencia de ingesta</div>
        </div>
    </div>
    <?= $this->Form->create(null, ['type' => 'post']) ?>
    <div class="app-card-body">
        <div class="app-form-row">
            <div class="app-form-group">
                <?= $this->Form->label('system_title', 'Título del sistema') ?>
                <?= $this->Form->text('system_title', [
                    'value' => $settings['system_title'] ?? 'Sistema de Soporte',
                    'placeholder' => 'Sistema de Soporte',
                ]) ?>
            </div>
            <div class="app-form-group">
                <?= $this->Form->label('gmail_check_interval', 'Intervalo Gmail (min)') ?>
                <?= $this->Form->number('gmail_check_interval', [
                    'value' => $settings['gmail_check_interval'] ?? '5',
                    'placeholder' => '5',
                    'min' => 1,
                ]) ?>
                <small>Frecuencia con la que se revisan nuevos correos.</small>
            </div>
        </div>
    </div>
    <div class="app-card-footer">
        <?= $this->Form->button(
            '<i class="bi bi-check-lg"></i> Guardar configuración',
            ['class' => 'btn-brand-primary', 'escapeTitle' => false]
        ) ?>
    </div>
    <?= $this->Form->end() ?>
</div>

<!-- 2. Google OAuth -->
<div class="app-card">
    <div class="app-card-header">
        <div class="app-card-header-icon blue"><i class="bi bi-google"></i></div>
        <div class="app-card-header-text">
            <h3 class="app-card-header-title">Autorización Google OAuth 2.0</h3>
            <div class="app-card-header-subtitle">Conexión a la API de Gmail</div>
        </div>
    </div>
    <div class="app-card-body">
        <?php if ($gmailAuthorized): ?>
            <div class="app-status-row">
                <span class="dot"></span>
                Gmail está autorizado y conectado correctamente.
            </div>
        <?php else: ?>
            <div class="app-banner">
                <i class="bi bi-exclamation-triangle"></i>
                <div>
                    <div class="app-banner-title">Gmail no está autorizado.</div>
                    <div class="app-banner-message">Debes autorizar la aplicación para importar correos.</div>
                </div>
            </div>
            <ol style="margin: 14px 0 0; padding-left: 18px; color: var(--gray-700); font-size: 13px; line-height: 1.7;">
                <li>Asegúrate de tener el archivo <code class="mono">client_secret.json</code> configurado abajo.</li>
                <li>Haz clic en <strong>Autorizar Gmail</strong>.</li>
                <li>Inicia sesión y autoriza los permisos solicitados.</li>
            </ol>
        <?php endif; ?>
    </div>
    <div class="app-card-footer start">
        <?php if ($gmailAuthorized): ?>
            <?= $this->Html->link('<i class="bi bi-arrow-repeat"></i> Reconectar',
                ['action' => 'gmailAuth'],
                ['class' => 'btn-brand-secondary', 'escape' => false]
            ) ?>
            <?= $this->Html->link('<i class="bi bi-play-circle"></i> Probar conexión',
                ['action' => 'testGmail'],
                ['class' => 'btn-brand-ghost', 'escape' => false]
            ) ?>
        <?php else: ?>
            <?= $this->Html->link('<i class="bi bi-shield-check"></i> Autorizar Gmail',
                ['action' => 'gmailAuth'],
                ['class' => 'btn-brand-primary', 'escape' => false]
            ) ?>
        <?php endif; ?>
    </div>
</div>

<!-- 3. Gmail client secret JSON -->
<div class="app-card">
    <div class="app-card-header">
        <div class="app-card-header-icon orange"><i class="bi bi-key"></i></div>
        <div class="app-card-header-text">
            <h3 class="app-card-header-title">Credenciales OAuth de Gmail</h3>
            <div class="app-card-header-subtitle">
                <span class="status-dot-pill <?= $clientSecretSet ? 'active' : 'inactive' ?>">
                    <?= $clientSecretSet ? 'Configurado' : 'No configurado' ?>
                </span>
            </div>
        </div>
    </div>
    <?= $this->Form->create(null, [
        'url' => ['controller' => 'Settings', 'action' => 'gmailClientSecret', 'prefix' => 'Admin'],
    ]) ?>
    <div class="app-card-body">
        <p style="margin: 0 0 12px; font-size: 13px; color: var(--gray-600);">
            Pega el contenido del archivo <code class="mono">client_secret.json</code> descargado desde
            Google Cloud Console. Se guarda cifrado en la base de datos.
        </p>
        <div class="app-form-group">
            <?= $this->Form->label('client_secret_json', 'Contenido JSON') ?>
            <?= $this->Form->textarea('client_secret_json', [
                'rows' => 10,
                'spellcheck' => 'false',
                'autocomplete' => 'off',
                'style' => 'font-family: var(--font-mono); font-size: 12px; line-height: 1.55;',
                'placeholder' => '{"web":{"client_id":"...","client_secret":"...","redirect_uris":["..."]}}',
                'required' => true,
            ]) ?>
            <small>Por seguridad, el JSON guardado no se muestra. Pega de nuevo si necesitas reemplazarlo.</small>
        </div>
    </div>
    <div class="app-card-footer">
        <?= $this->Form->button(
            '<i class="bi bi-save"></i> Guardar credenciales',
            ['type' => 'submit', 'class' => 'btn-brand-primary', 'escapeTitle' => false]
        ) ?>
    </div>
    <?= $this->Form->end() ?>
</div>

<!-- 4. WhatsApp -->
<div class="app-card">
    <div class="app-card-header">
        <div class="app-card-header-icon" style="background: #25d3661f; color: #075e54;"><i class="bi bi-whatsapp"></i></div>
        <div class="app-card-header-text">
            <h3 class="app-card-header-title">WhatsApp · Evolution API</h3>
            <div class="app-card-header-subtitle">Notificaciones automáticas por WhatsApp</div>
        </div>
    </div>
    <?= $this->Form->create(null, ['type' => 'post', 'url' => ['action' => 'index']]) ?>
    <div class="app-card-body">
        <label class="app-toggle">
            <?= $this->Form->checkbox('whatsapp_enabled', [
                'checked' => $whatsappEnabled,
                'value' => '1',
                'id' => 'whatsapp_enabled',
            ]) ?>
            <span class="app-toggle-label">Habilitar notificaciones de WhatsApp</span>
        </label>
        <small class="app-form-help">Envía alertas automáticas cuando se crean o actualizan tickets.</small>

        <div id="whatsapp-config-fields" class="app-collapsible" <?= $whatsappEnabled ? '' : 'hidden' ?>>
            <div class="app-form-row">
                <div class="app-form-group">
                    <?= $this->Form->label('whatsapp_api_url', 'URL de Evolution API') ?>
                    <?= $this->Form->text('whatsapp_api_url', [
                        'value' => $settings['whatsapp_api_url'] ?? '',
                        'placeholder' => 'https://your-evolution-api.com',
                    ]) ?>
                </div>
                <div class="app-form-group">
                    <?= $this->Form->label('whatsapp_api_key', 'API Key') ?>
                    <?= $this->Form->password('whatsapp_api_key', [
                        'value' => $settings['whatsapp_api_key'] ?? '',
                        'placeholder' => '••••••••••••••••',
                    ]) ?>
                </div>
            </div>
            <div class="app-form-row">
                <div class="app-form-group">
                    <?= $this->Form->label('whatsapp_instance_name', 'Nombre de instancia') ?>
                    <?= $this->Form->text('whatsapp_instance_name', [
                        'value' => $settings['whatsapp_instance_name'] ?? 'AlexBot',
                        'placeholder' => 'AlexBot',
                    ]) ?>
                </div>
                <div class="app-form-group">
                    <?= $this->Form->label('whatsapp_tickets_number', 'Número de alerta') ?>
                    <?= $this->Form->text('whatsapp_tickets_number', [
                        'value' => $settings['whatsapp_tickets_number'] ?? '',
                        'placeholder' => '5511999999999@s.whatsapp.net',
                    ]) ?>
                </div>
            </div>

            <div class="app-banner info" style="margin-top: 6px;">
                <i class="bi bi-info-circle"></i>
                <div>
                    <div class="app-banner-title">Formato de número</div>
                    <div class="app-banner-message">
                        Grupo: <code class="mono">ID@g.us</code> · Individual: <code class="mono">código+número@s.whatsapp.net</code>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="app-card-footer">
        <?php if ($whatsappEnabled): ?>
            <?= $this->Html->link('<i class="bi bi-play-circle"></i> Probar conexión',
                ['action' => 'testWhatsapp'],
                ['class' => 'btn-brand-ghost', 'escape' => false, 'id' => 'test-whatsapp-btn']
            ) ?>
        <?php endif; ?>
        <?= $this->Form->button(
            '<i class="bi bi-check-lg"></i> Guardar',
            ['class' => 'btn-brand-primary', 'type' => 'submit', 'escapeTitle' => false]
        ) ?>
    </div>
    <?= $this->Form->end() ?>
</div>

<!-- 5. n8n -->
<div class="app-card">
    <div class="app-card-header">
        <div class="app-card-header-icon" style="background: #ea4b711f; color: #c4264e;"><i class="bi bi-diagram-3"></i></div>
        <div class="app-card-header-text">
            <h3 class="app-card-header-title">Integración con n8n</h3>
            <div class="app-card-header-subtitle">Asignación automática de tags con IA</div>
        </div>
    </div>
    <?= $this->Form->create(null, ['type' => 'post', 'url' => ['action' => 'index']]) ?>
    <div class="app-card-body">
        <label class="app-toggle">
            <?= $this->Form->checkbox('n8n_enabled', [
                'checked' => $n8nEnabled,
                'value' => '1',
                'id' => 'n8n_enabled',
            ]) ?>
            <span class="app-toggle-label">Habilitar integración con n8n</span>
        </label>
        <small class="app-form-help">Envía tickets a n8n para clasificación automática con IA.</small>

        <div id="n8n-config-fields" class="app-collapsible" <?= $n8nEnabled ? '' : 'hidden' ?>>
            <div class="app-form-row">
                <div class="app-form-group">
                    <?= $this->Form->label('n8n_webhook_url', 'URL del webhook') ?>
                    <?= $this->Form->text('n8n_webhook_url', [
                        'value' => $settings['n8n_webhook_url'] ?? '',
                        'placeholder' => 'https://tu-n8n.com/webhook/ai-tags',
                    ]) ?>
                </div>
                <div class="app-form-group">
                    <?= $this->Form->label('n8n_api_key', 'API Key (opcional)') ?>
                    <?= $this->Form->password('n8n_api_key', [
                        'value' => $settings['n8n_api_key'] ?? '',
                        'placeholder' => '••••••••••••••••',
                    ]) ?>
                </div>
            </div>

            <div class="app-form-row">
                <div class="app-form-group">
                    <span class="app-form-label">Envío de tags</span>
                    <label class="app-toggle">
                        <?= $this->Form->checkbox('n8n_send_tags_list', [
                            'checked' => ($settings['n8n_send_tags_list'] ?? '1') === '1',
                            'value' => '1',
                            'id' => 'n8n_send_tags_list',
                        ]) ?>
                        <span class="app-toggle-label">Enviar lista de tags disponibles</span>
                    </label>
                    <small>Incluye la lista completa en el payload.</small>
                </div>
                <div class="app-form-group">
                    <?= $this->Form->label('n8n_timeout', 'Timeout (segundos)') ?>
                    <?= $this->Form->number('n8n_timeout', [
                        'value' => $settings['n8n_timeout'] ?? '10',
                        'placeholder' => '10',
                        'min' => 1,
                        'max' => 60,
                    ]) ?>
                </div>
            </div>

            <div class="app-banner info" style="margin-top: 6px;">
                <i class="bi bi-info-circle"></i>
                <div>
                    <div class="app-banner-title">Flujo de integración</div>
                    <div class="app-banner-message">Gmail → ticket → n8n (IA sugiere tags) → tags aplicados al ticket.</div>
                </div>
            </div>
        </div>
    </div>
    <div class="app-card-footer">
        <?php if ($n8nEnabled): ?>
            <?= $this->Html->link('<i class="bi bi-play-circle"></i> Probar conexión',
                ['action' => 'testN8n'],
                ['class' => 'btn-brand-ghost', 'escape' => false, 'id' => 'test-n8n-btn']
            ) ?>
        <?php endif; ?>
        <?= $this->Form->button(
            '<i class="bi bi-check-lg"></i> Guardar',
            ['class' => 'btn-brand-primary', 'type' => 'submit', 'escapeTitle' => false]
        ) ?>
    </div>
    <?= $this->Form->end() ?>
</div>

<!-- 6. Webhooks -->
<div class="app-card">
    <div class="app-card-header">
        <div class="app-card-header-icon"><i class="bi bi-link-45deg"></i></div>
        <div class="app-card-header-text">
            <h3 class="app-card-header-title">Webhook · Gmail import</h3>
            <div class="app-card-header-subtitle">Endpoint disparado por n8n para importar correos</div>
        </div>
    </div>
    <div class="app-card-body">
        <div class="app-form-group">
            <span class="app-form-label">URL del webhook</span>
            <code class="mono" style="display: inline-block; padding: 8px 10px; background: var(--gray-50); border: 1px solid var(--gray-200); border-radius: var(--radius-md); font-size: 12px; color: var(--gray-800); word-break: break-all;">
                <?= h($webhookGmailUrl) ?>
            </code>
        </div>

        <div class="app-form-group">
            <?= $this->Form->label('webhook-gmail-token', 'Token (X-Webhook-Token)') ?>
            <div style="display: flex; gap: 6px; align-items: stretch; flex-wrap: wrap;">
                <input type="password"
                       id="webhook-gmail-token"
                       value="<?= h($webhookGmailToken) ?>"
                       readonly
                       class="mono"
                       style="flex: 1 1 40ch; min-width: 30ch;">
                <button type="button"
                        class="btn-brand-secondary"
                        onclick="var f=document.getElementById('webhook-gmail-token');f.type=f.type==='password'?'text':'password';">
                    <i class="bi bi-eye"></i>
                </button>
                <button type="button"
                        class="btn-brand-secondary"
                        onclick="navigator.clipboard.writeText(document.getElementById('webhook-gmail-token').value)">
                    <i class="bi bi-clipboard"></i> Copiar
                </button>
            </div>
        </div>

        <div class="app-form-group" style="margin-bottom: 0;">
            <span class="app-form-label">Última ejecución</span>
            <span class="mono" style="font-size: 12px; color: var(--gray-600);">
                <?= $webhookGmailLastRun ? h($webhookGmailLastRun) : '— sin registros —' ?>
            </span>
        </div>
    </div>
    <div class="app-card-footer start">
        <?= $this->Form->postLink(
            '<i class="bi bi-arrow-clockwise"></i> Regenerar token',
            ['action' => 'regenerateWebhookToken'],
            [
                'class' => 'btn-brand-danger btn-brand-sm',
                'escapeTitle' => false,
                'confirm' => '¿Seguro? El token actual dejará de funcionar inmediatamente; deberás actualizarlo en n8n.',
            ]
        ) ?>
    </div>
</div>

<!-- 7. Quick links -->
<div class="app-card">
    <div class="app-card-header">
        <div class="app-card-header-icon"><i class="bi bi-grid"></i></div>
        <div class="app-card-header-text">
            <h3 class="app-card-header-title">Otras opciones</h3>
            <div class="app-card-header-subtitle">Accesos rápidos al workspace</div>
        </div>
    </div>
    <div class="app-card-body">
        <div class="app-grid compact">
            <?= $this->Html->link(
                '<i class="bi bi-envelope"></i><span>Plantillas</span>',
                ['action' => 'emailTemplates'],
                ['class' => 'quick-link-card', 'escape' => false]
            ) ?>
            <?= $this->Html->link(
                '<i class="bi bi-people"></i><span>Usuarios</span>',
                ['action' => 'users'],
                ['class' => 'quick-link-card', 'escape' => false]
            ) ?>
            <?= $this->Html->link(
                '<i class="bi bi-tags"></i><span>Etiquetas</span>',
                ['prefix' => 'Admin', 'controller' => 'Tags', 'action' => 'index'],
                ['class' => 'quick-link-card', 'escape' => false]
            ) ?>
        </div>
    </div>
</div>

<?= $this->Html->script('admin/settings', ['block' => 'script']) ?>
