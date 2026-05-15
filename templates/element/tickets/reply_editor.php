<?php
/**
 * Element: Reply Editor for Tickets (composer).
 *
 * Keeps the data-action / id contract used by webroot/js/tickets-view.js and
 * webroot/js/reply-editor-init.js; only the chrome is rebuilt around tab pills
 * (Responder / Nota interna / Reasignar).
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Ticket $entity
 * @var array $statuses
 * @var \App\Model\Entity\User|null $currentUser
 * @var bool $isLocked
 */

$statuses    = $statuses ?? [];
$currentUser = $currentUser ?? null;
$isLocked    = $isLocked ?? false;

$requesterName  = $entity->requester->name  ?? $entity->requester->email;
$requesterEmail = $entity->requester->email ?? '';
?>

<?php if ($isLocked) : ?>
    <div class="composer-locked">
        <i class="bi bi-lock-fill"></i>
        <div>
            <strong>Solicitud cerrada</strong>
            <div>Esta solicitud está en estado final y no puede ser modificada.</div>
        </div>
    </div>
<?php else : ?>
<div class="composer" id="composer">
    <?= $this->Form->create(null, [
        'url'  => ['action' => 'addComment', $entity->id],
        'type' => 'file',
        'id'   => 'reply-form',
    ]) ?>

    <?= $this->Form->hidden('comment_type', ['value' => 'public', 'id' => 'comment-type']) ?>

    <!-- Tab pills -->
    <div class="composer-tabs">
        <a href="#" class="composer-tab active" data-action="comment-type" data-comment-type="public">
            <i class="bi bi-reply-fill"></i> Responder
        </a>
        <a href="#" class="composer-tab" data-action="comment-type" data-comment-type="internal">
            <i class="bi bi-pencil-square"></i> Nota interna
        </a>
        <a href="#" class="composer-tab composer-tab-reassign" data-action="focus-reassign">
            <i class="bi bi-arrow-left-right"></i> Reasignar
        </a>

        <div class="composer-tabs-spacer"></div>

        <span class="composer-recipient-hint" id="comment-type-recipients" data-action="expand-recipients">
            a <span id="comment-type-recipients-text" class="mono" data-original-text="<?= h($requesterName) ?>"><?= h($requesterName) ?></span>
        </span>
        <!-- Hidden anchors retained for JS compatibility -->
        <span id="comment-type-label" hidden>Respuesta pública</span>
        <i id="comment-type-icon" hidden></i>
    </div>

    <!-- Body / editor -->
    <div class="composer-body" id="editor-container">
        <!-- Drag-and-drop overlay -->
        <div class="app-drop-overlay" id="composer-drop-overlay">
            <span><i class="bi bi-arrow-up-circle"></i> Suelta los archivos aquí</span>
        </div>

        <!-- Email recipients (public reply only) -->
        <div id="email-recipients-section" class="composer-recipients" style="display: none;">
            <div id="recipients-collapsed"></div>
            <div id="recipients-expanded" class="composer-recipients-expanded" style="display: none;">
                <div class="composer-recipients-toolbar mb-2">
                    <button type="button" class="btn-icon-collapse" data-action="collapse-recipients" data-tip="Ocultar" data-tip-side="top" title="Ocultar">
                        <i class="bi bi-chevron-up"></i>
                    </button>
                </div>
                <div class="composer-recipients-card">
                    <div class="composer-recipient-row">
                        <label for="email-to" class="composer-recipient-label">Para</label>
                        <div class="composer-recipient-field">
                            <div id="email-to-container" class="tag-input-container">
                                <input type="text" id="email-to" placeholder="Agregar destinatario" autocomplete="off" class="tag-input-field">
                            </div>
                            <input type="hidden" name="email_to" id="email-to-hidden" value="">
                        </div>
                    </div>
                    <div class="composer-recipient-row">
                        <label for="email-cc" class="composer-recipient-label">CC</label>
                        <div class="composer-recipient-field">
                            <div id="email-cc-container" class="tag-input-container">
                                <input type="text" id="email-cc" placeholder="Agregar copia" autocomplete="off" class="tag-input-field">
                            </div>
                            <input type="hidden" name="email_cc" id="email-cc-hidden" value="">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Rich-text toolbar (formato markdown sobre el textarea) -->
        <div class="rt-toolbar full" id="composer-toolbar" role="toolbar" aria-label="Formato">
            <button type="button" class="rt-btn" data-rt="bold" data-tip="Negrita" data-tip-side="top" aria-label="Negrita"><strong>B</strong></button>
            <button type="button" class="rt-btn" data-rt="italic" data-tip="Itálica" data-tip-side="top" aria-label="Itálica"><em>I</em></button>
            <button type="button" class="rt-btn" data-rt="underline" data-tip="Subrayado" data-tip-side="top" aria-label="Subrayado"><u>U</u></button>
            <span class="rt-divider" aria-hidden="true"></span>
            <button type="button" class="rt-btn" data-rt="ul" data-tip="Lista" data-tip-side="top" aria-label="Lista"><i class="bi bi-list-ul"></i></button>
            <button type="button" class="rt-btn" data-rt="ol" data-tip="Lista numerada" data-tip-side="top" aria-label="Lista numerada"><i class="bi bi-list-ol"></i></button>
            <button type="button" class="rt-btn" data-rt="quote" data-tip="Cita" data-tip-side="top" aria-label="Cita"><i class="bi bi-quote"></i></button>
            <span class="rt-divider" aria-hidden="true"></span>
            <button type="button" class="rt-btn" data-rt="link" data-tip="Enlace" data-tip-side="top" aria-label="Enlace"><i class="bi bi-link-45deg"></i></button>
            <button type="button" class="rt-btn" data-rt="code" data-tip="Código" data-tip-side="top" aria-label="Código inline"><i class="bi bi-code"></i></button>
            <div class="rt-toolbar-spacer"></div>
            <button type="button" class="rt-btn rt-btn-text" data-action="open-template-picker" aria-label="Insertar plantilla">
                <i class="bi bi-file-earmark-text"></i> Plantilla
            </button>
        </div>

        <?= $this->Form->hidden('comment_body', ['id' => 'comment-body-hidden']) ?>
        <div
            id="comment-textarea"
            class="composer-editor"
            contenteditable="true"
            role="textbox"
            aria-multiline="true"
            aria-label="Cuerpo de la respuesta"
            data-placeholder="<?= h('Escribe tu respuesta a ' . $requesterName . '…') ?>"
            data-max="5000"
            spellcheck="true"
        ></div>

        <!-- Footer: attach + emoji + counter + ⌘⏎ + send -->
        <div class="composer-footer">
            <label class="composer-icon-btn" id="file-upload-btn" data-tip="Adjuntar archivos" data-tip-side="top" aria-label="Adjuntar archivos">
                <i class="bi bi-paperclip"></i>
                <?= $this->Form->file('attachments[]', [
                    'multiple' => true,
                    'id'       => 'file-input',
                    'style'    => 'display: none;',
                    'accept'   => '.jpg,.jpeg,.png,.gif,.bmp,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar,.7z',
                ]) ?>
            </label>
            <button type="button" class="composer-icon-btn" id="emoji-btn" data-tip="Emoji" data-tip-side="top" aria-label="Insertar emoji">
                <i class="bi bi-emoji-smile"></i>
            </button>
            <div id="file-list" class="file-list"></div>

            <div class="composer-footer-spacer"></div>

            <span class="composer-char-counter mono tnum" id="composer-char-counter" aria-live="polite">5.0k restantes</span>

            <!-- Status is fixed: the comment keeps the current ticket status.
                 State transitions live in the top bar (Resolver / Cambiar estado). -->
            <?= $this->Form->hidden('status', ['value' => $entity->status, 'id' => 'status-hidden']) ?>

            <?= $this->Form->button('Enviar', [
                'class'       => 'btn-brand-primary btn-brand-sm composer-send-btn',
                'type'        => 'submit',
                'escapeTitle' => false,
            ]) ?>
        </div>
    </div>

    <?= $this->Form->end() ?>
</div>
<?php endif; ?>

<?php
$systemEmailAddr = strtolower($systemConfig['gmail_user_email'] ?? '');
$buildRecipients = function ($field) use ($systemEmailAddr) {
    if (empty($field)) {
        return [];
    }
    $decoded = is_string($field) ? json_decode($field, true) : $field;
    if (!is_array($decoded)) {
        return [];
    }
    $out = [];
    foreach ($decoded as $r) {
        if (!empty($r['email']) && strtolower($r['email']) !== $systemEmailAddr) {
            $out[] = ['name' => $r['name'] ?? $r['email'], 'email' => $r['email']];
        }
    }

    return $out;
};
$initialTo = array_merge(
    [['name' => $requesterName, 'email' => $requesterEmail]],
    $buildRecipients($entity->email_to ?? null),
);
$initialCc = $buildRecipients($entity->email_cc ?? null);
?>
<script>
    window.replyEditorData = {
        to: <?= json_encode($initialTo) ?>,
        cc: <?= json_encode($initialCc) ?>,
        systemEmail: <?= json_encode($systemConfig['gmail_user_email'] ?? '') ?>
    };
</script>
<?= $this->Html->script('reply-editor-init', ['block' => 'script']) ?>
