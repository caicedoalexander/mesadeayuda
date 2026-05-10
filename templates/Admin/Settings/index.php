<?php
/**
 * @var \App\View\AppView $this
 * @var array $settings
 */
$this->assign('title', 'Configuración');
?>

<?= $this->Html->css('admin/settings', ['block' => 'css']) ?>

<div class="settings-page">
    <!-- Page Header -->
    <div class="page-header">
        <div class="header-icon">
            <i class="bi bi-gear-fill"></i>
        </div>
        <div class="header-text">
            <h3>Configuración del Sistema</h3>
        </div>
    </div>

    <?= $this->Flash->render() ?>

    <!-- General Configuration -->
    <div class="config-card">
        <div class="config-header">
            <img src="<?= $this->Url->build('img/email.png') ?>" alt="Email">
            <h3>Configuración General</h3>
        </div>

        <?= $this->Form->create(null, ['type' => 'post']) ?>

        <div class="form-group">
            <?= $this->Form->label('system_title', 'Título del Sistema') ?>
            <?= $this->Form->text('system_title', [
                'value' => $settings['system_title'] ?? 'Sistema de Soporte',
                'placeholder' => 'Sistema de Soporte'
            ]) ?>
        </div>

        <div class="form-group">
            <?= $this->Form->label('gmail_check_interval', 'Intervalo de comprobación de Gmail (minutos)') ?>
            <?= $this->Form->number('gmail_check_interval', [
                'value' => $settings['gmail_check_interval'] ?? '5',
                'placeholder' => '5',
                'min' => 1
            ]) ?>
            <small>Frecuencia con la que se revisan nuevos correos</small>
        </div>

        <div class="btn-actions">
            <?= $this->Form->button('<i class="bi bi-check-circle"></i> Guardar Configuración', [
                'class' => 'btn-primary',
                'escapeTitle' => false
            ]) ?>
        </div>

        <?= $this->Form->end() ?>
    </div>

    <!-- Google OAuth Configuration -->
    <div class="config-card">
        <div class="config-header">
            <img src="<?= $this->Url->build('img/google.png') ?>" alt="Google">
            <h3>Autorización de Google OAuth 2.0</h3>
        </div>

        <?php if (!empty($settings['gmail_refresh_token'])): ?>
            <div class="status-connected">
                Gmail está autorizado y conectado
            </div>

            <div class="btn-actions">
                <?= $this->Html->link('<i class="bi bi-arrow-repeat"></i> Reconectar', ['action' => 'gmailAuth'], [
                    'class' => 'btn-warning',
                    'escapeTitle' => false
                ]) ?>
                <?= $this->Html->link('<i class="bi bi-play-circle"></i> Probar Conexión', ['action' => 'testGmail'], [
                    'class' => 'btn-danger',
                    'escapeTitle' => false
                ]) ?>
            </div>
        <?php else: ?>
            <div class="alert-box warning">
                <i class="bi bi-exclamation-circle"></i>
                <strong>Gmail no está autorizado.</strong> Debes autorizar la aplicación para importar correos.
            </div>

            <div style="margin-top: 1.5rem;">
                <strong>Pasos para configurar Gmail:</strong>
                <ol style="margin-top: 0.75rem; color: var(--gray-700);">
                    <li>Asegúrate de tener el archivo <code>client_secret.json</code> en <code>config/google/</code></li>
                    <li>Haz clic en el botón de abajo para autorizar la aplicación</li>
                    <li>Inicia sesión con tu cuenta de Gmail</li>
                    <li>Autoriza los permisos solicitados</li>
                </ol>
            </div>

            <div class="btn-actions">
                <?= $this->Html->link('<i class="bi bi-shield-check"></i> Autorizar Gmail', ['action' => 'gmailAuth'], [
                    'class' => 'btn-primary',
                    'escapeTitle' => false
                ]) ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Gmail Client Secret JSON -->
    <div class="config-card">
        <div class="config-header">
            <i class="bi bi-key text-primary"></i>
            <h3>Credenciales OAuth de Gmail (client_secret.json)</h3>
        </div>

        <?php $clientSecretConfigured = !empty($settings[\App\Constants\SettingKeys::GMAIL_CLIENT_SECRET_JSON] ?? ''); ?>

        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i>
            Pega el contenido del archivo <strong>client_secret.json</strong> descargado desde Google Cloud Console.
            Se guarda cifrado en la base de datos.
            <?php if ($clientSecretConfigured): ?>
                <span class="badge bg-success">Configurado</span>
            <?php else: ?>
                <span class="badge bg-warning">No configurado</span>
            <?php endif; ?>
        </div>

        <?= $this->Form->create(null, [
            'url' => ['controller' => 'Settings', 'action' => 'gmailClientSecret', 'prefix' => 'Admin'],
            'class' => 'config-form',
        ]) ?>
            <div class="form-group">
                <?= $this->Form->label('client_secret_json', 'Contenido JSON') ?>
                <?= $this->Form->textarea('client_secret_json', [
                    'class' => 'form-control',
                    'rows' => 10,
                    'spellcheck' => 'false',
                    'autocomplete' => 'off',
                    'style' => 'font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 0.85rem;',
                    'placeholder' => '{"web":{"client_id":"...","client_secret":"...","redirect_uris":["..."]}}',
                    'required' => true,
                ]) ?>
                <small class="form-text text-muted">
                    Por seguridad, el JSON guardado no se muestra en pantalla. Pega de nuevo si necesitas reemplazarlo.
                </small>
            </div>

            <div class="btn-actions">
                <?= $this->Form->button('<i class="bi bi-save"></i> Guardar credenciales', [
                    'type' => 'submit',
                    'class' => 'btn-primary',
                    'escapeTitle' => false,
                ]) ?>
            </div>
        <?= $this->Form->end() ?>
    </div>

    <!-- WhatsApp Configuration -->
    <div class="config-card">
        <div class="config-header">
            <i class="bi bi-whatsapp text-success"></i>
            <h3>Configuración de WhatsApp</h3>
        </div>

        <?= $this->Form->create(null, ['type' => 'post', 'url' => ['action' => 'index']]) ?>

        <div class="form-group">
            <div class="checkbox-toggle">
                <?= $this->Form->checkbox('whatsapp_enabled', [
                    'checked' => ($settings['whatsapp_enabled'] ?? '0') === '1',
                    'value' => '1',
                    'id' => 'whatsapp_enabled'
                ]) ?>
                <?= $this->Form->label('whatsapp_enabled', 'Habilitar notificaciones de WhatsApp') ?>
            </div>
            <small>Enviar notificaciones automáticas por WhatsApp cuando se crean/actualizan tickets</small>
        </div>

        <div id="whatsapp-config-fields" class="collapsible-fields" style="display: <?= (($settings['whatsapp_enabled'] ?? '0') === '1') ? 'block' : 'none' ?>;">
            <div class="form-group">
                <?= $this->Form->label('whatsapp_api_url', 'URL de Evolution API') ?>
                <?= $this->Form->text('whatsapp_api_url', [
                    'value' => $settings['whatsapp_api_url'] ?? '',
                    'placeholder' => 'https://your-evolution-api.com'
                ]) ?>
                <small>URL base de tu instancia de Evolution API</small>
            </div>

            <div class="form-group">
                <?= $this->Form->label('whatsapp_api_key', 'API Key') ?>
                <?= $this->Form->password('whatsapp_api_key', [
                    'value' => $settings['whatsapp_api_key'] ?? '',
                    'placeholder' => '••••••••••••••••'
                ]) ?>
                <small>Clave de autenticación de Evolution API</small>
            </div>

            <div class="form-group">
                <?= $this->Form->label('whatsapp_instance_name', 'Nombre de Instancia') ?>
                <?= $this->Form->text('whatsapp_instance_name', [
                    'value' => $settings['whatsapp_instance_name'] ?? 'AlexBot',
                    'placeholder' => 'AlexBot'
                ]) ?>
                <small>Nombre de tu instancia de WhatsApp en Evolution API</small>
            </div>

            <div class="form-group">
                <?= $this->Form->label('whatsapp_tickets_number', 'Número de alerta de tickets') ?>
                <?= $this->Form->text('whatsapp_tickets_number', [
                    'value' => $settings['whatsapp_tickets_number'] ?? '',
                    'placeholder' => '5511999999999@s.whatsapp.net'
                ]) ?>
            </div>

            <div class="alert-box">
                <i class="bi bi-info-circle-fill"></i>
                <strong>Formatos de número:</strong>
                <ul>
                    <li><strong>Grupo:</strong> <code>ID@g.us</code> (ej: 120363424575102342@g.us)</li>
                    <li><strong>Individual:</strong> <code>código+número@s.whatsapp.net</code> (ej: 5219991234567@s.whatsapp.net)</li>
                </ul>
            </div>
        </div>

        <div class="btn-actions">
            <?= $this->Form->button('<i class="bi bi-check-circle"></i> Guardar Configuración', [
                'class' => 'btn-primary',
                'type' => 'submit',
                'escapeTitle' => false
            ]) ?>

            <?php if (($settings['whatsapp_enabled'] ?? '0') === '1'): ?>
                <?= $this->Html->link('<i class="bi bi-check-circle"></i> Probar Conexión', ['action' => 'testWhatsapp'], [
                    'class' => 'btn-outline',
                    'escape' => false,
                    'id' => 'test-whatsapp-btn'
                ]) ?>
            <?php endif; ?>
        </div>

        <?= $this->Form->end() ?>
    </div>

    <!-- n8n Configuration -->
    <div class="config-card">
        <div class="config-header">
            <img src="<?= $this->Url->build('img/n8n.png') ?>" alt="n8n">
            <h3>Configuración de n8n</h3>
        </div>

        <?= $this->Form->create(null, ['type' => 'post', 'url' => ['action' => 'index']]) ?>

        <div class="form-group">
            <div class="checkbox-toggle">
                <?= $this->Form->checkbox('n8n_enabled', [
                    'checked' => ($settings['n8n_enabled'] ?? '0') === '1',
                    'value' => '1',
                    'id' => 'n8n_enabled'
                ]) ?>
                <?= $this->Form->label('n8n_enabled', 'Habilitar integración con n8n') ?>
            </div>
            <small>Enviar tickets a n8n para asignación automática de tags con IA</small>
        </div>

        <div id="n8n-config-fields" class="collapsible-fields" style="display: <?= (($settings['n8n_enabled'] ?? '0') === '1') ? 'block' : 'none' ?>;">
            <div class="form-group">
                <?= $this->Form->label('n8n_webhook_url', 'URL del Webhook de n8n') ?>
                <?= $this->Form->text('n8n_webhook_url', [
                    'value' => $settings['n8n_webhook_url'] ?? '',
                    'placeholder' => 'https://tu-n8n.com/webhook/ai-tags'
                ]) ?>
                <small>URL completa del webhook que recibirá los datos del ticket</small>
            </div>

            <div class="form-group">
                <?= $this->Form->label('n8n_api_key', 'API Key (Opcional)') ?>
                <?= $this->Form->password('n8n_api_key', [
                    'value' => $settings['n8n_api_key'] ?? '',
                    'placeholder' => '••••••••••••••••'
                ]) ?>
                <small>Clave de autenticación para el webhook (opcional)</small>
            </div>

            <div class="form-group">
                <div class="checkbox-toggle">
                    <?= $this->Form->checkbox('n8n_send_tags_list', [
                        'checked' => ($settings['n8n_send_tags_list'] ?? '1') === '1',
                        'value' => '1',
                        'id' => 'n8n_send_tags_list'
                    ]) ?>
                    <?= $this->Form->label('n8n_send_tags_list', 'Enviar lista de tags disponibles') ?>
                </div>
                <small>Incluir la lista completa de tags en el payload del webhook</small>
            </div>

            <div class="form-group">
                <?= $this->Form->label('n8n_timeout', 'Timeout (segundos)') ?>
                <?= $this->Form->number('n8n_timeout', [
                    'value' => $settings['n8n_timeout'] ?? '10',
                    'placeholder' => '10',
                    'min' => 1,
                    'max' => 60
                ]) ?>
                <small>Tiempo máximo de espera para la respuesta del webhook</small>
            </div>

            <div class="alert-box">
                <i class="bi bi-info-circle-fill"></i>
                <strong>Flujo de integración:</strong>
                <ol>
                    <li>Se crea un ticket desde Gmail</li>
                    <li>El sistema envía los datos del ticket a n8n vía webhook</li>
                    <li>n8n procesa el ticket con IA para sugerir tags</li>
                    <li>n8n actualiza los tags directamente en la base de datos</li>
                </ol>
            </div>
        </div>

        <div class="btn-actions">
            <?= $this->Form->button('<i class="bi bi-check-circle"></i> Guardar Configuración', [
                'class' => 'btn-primary',
                'type' => 'submit',
                'escapeTitle' => false
            ]) ?>

            <?php if (($settings['n8n_enabled'] ?? '0') === '1'): ?>
                <?= $this->Html->link('<i class="bi bi-check-circle"></i> Probar Conexión', ['action' => 'testN8n'], [
                    'class' => 'btn-outline',
                    'escape' => false,
                    'id' => 'test-n8n-btn'
                ]) ?>
            <?php endif; ?>
        </div>

        <?= $this->Form->end() ?>
    </div>

    <!-- Webhooks Configuration -->
    <div class="config-card">
        <div class="config-header">
            <i class="bi bi-link-45deg text-primary"></i>
            <h3>Webhooks — Gmail Import</h3>
        </div>

        <p class="text-muted">
            Endpoint disparado por n8n para importar correos. Reemplaza al worker continuo.
        </p>

        <div class="form-group">
            <label>URL del webhook</label>
            <code style="display:inline-block; padding:.35rem .6rem; background:#f5f5f5; border-radius:4px;">
                <?= h($webhookGmailUrl) ?>
            </code>
        </div>

        <div class="form-group">
            <label for="webhook-gmail-token">Token (X-Webhook-Token)</label>
            <div style="display:flex; gap:.5rem; align-items:center; flex-wrap:wrap;">
                <input type="password"
                       id="webhook-gmail-token"
                       value="<?= h($webhookGmailToken) ?>"
                       readonly
                       style="flex:1 1 40ch; min-width:30ch; font-family:monospace;">
                <button type="button"
                        class="btn-outline"
                        onclick="var f=document.getElementById('webhook-gmail-token');f.type=f.type==='password'?'text':'password';">
                    <i class="bi bi-eye"></i> Mostrar / ocultar
                </button>
                <button type="button"
                        class="btn-outline"
                        onclick="navigator.clipboard.writeText(document.getElementById('webhook-gmail-token').value)">
                    <i class="bi bi-clipboard"></i> Copiar
                </button>
            </div>
        </div>

        <div class="form-group">
            <label>Última ejecución</label>
            <span><?= $webhookGmailLastRun ? h($webhookGmailLastRun) : '— sin registros —' ?></span>
        </div>

        <div class="btn-actions">
            <?= $this->Form->postLink(
                '<i class="bi bi-arrow-clockwise"></i> Regenerar token',
                ['action' => 'regenerateWebhookToken'],
                [
                    'class' => 'btn-outline',
                    'escapeTitle' => false,
                    'confirm' => '¿Seguro? El token actual dejará de funcionar inmediatamente; deberás actualizarlo en n8n.',
                ]
            ) ?>
        </div>
    </div>

    <!-- Quick Links -->
    <div class="quick-links-section pb-3">
        <h3>Otras Opciones</h3>
        <div class="quick-links-grid">
            <?= $this->Html->link(
                '<i class="bi bi-envelope"></i><span>Plantillas</span>',
                ['action' => 'emailTemplates'],
                ['class' => 'quick-link-card', 'escapeTitle' => false]
            ) ?>
            <?= $this->Html->link(
                '<i class="bi bi-people"></i><span>Usuarios</span>',
                ['action' => 'users'],
                ['class' => 'quick-link-card', 'escapeTitle' => false]
            ) ?>
            <?= $this->Html->link(
                '<i class="bi bi-tags"></i><span>Etiquetas</span>',
                ['action' => 'tags'],
                ['class' => 'quick-link-card', 'escapeTitle' => false]
            ) ?>
        </div>
    </div>
</div>

<?= $this->Html->script('admin/settings', ['block' => 'script']) ?>
