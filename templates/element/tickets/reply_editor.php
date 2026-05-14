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

<?php if ($isLocked): ?>
    <div class="composer-locked">
        <i class="bi bi-lock-fill"></i>
        <div>
            <strong>Solicitud cerrada</strong>
            <div>Esta solicitud está en estado final y no puede ser modificada.</div>
        </div>
    </div>
<?php else: ?>

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
        <!-- Email recipients (public reply only) -->
        <div id="email-recipients-section" class="composer-recipients" style="display: none;">
            <div id="recipients-collapsed"></div>
            <div id="recipients-expanded" class="composer-recipients-expanded" style="display: none;">
                <div class="d-flex justify-content-center">
                    <button type="button" class="btn-icon-collapse" data-action="collapse-recipients" title="Ocultar">
                        <i class="bi bi-chevron-up"></i>
                    </button>
                </div>
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

        <?= $this->Form->control('comment_body', [
            'type'        => 'textarea',
            'label'       => false,
            'placeholder' => 'Escribe tu respuesta a ' . h($requesterName) . '…',
            'class'       => 'composer-textarea',
            'required'    => false,
            'id'          => 'comment-textarea',
            'rows'        => 4,
        ]) ?>

        <!-- Footer: attach + status + send -->
        <div class="composer-footer">
            <label class="composer-attach" id="file-upload-btn" title="Adjuntar archivos">
                <i class="bi bi-paperclip"></i>
                <span>Adjuntar archivos</span>
                <?= $this->Form->file('attachments[]', [
                    'multiple' => true,
                    'id'       => 'file-input',
                    'style'    => 'display: none;',
                    'accept'   => '.jpg,.jpeg,.png,.gif,.bmp,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar,.7z',
                ]) ?>
            </label>
            <div id="file-list" class="file-list"></div>

            <div class="composer-footer-spacer"></div>

            <!-- Status selector — keeps existing IDs so tickets-view.js stays valid -->
            <?= $this->Form->hidden('status', ['value' => $entity->status, 'id' => 'status-hidden']) ?>
            <?php $currentConfig = $statuses[$entity->status] ?? $statuses[array_key_first($statuses)] ?? []; ?>
            <div class="dropup composer-status-wrap">
                <button class="composer-status-btn"
                        type="button"
                        id="status-dropdown"
                        data-bs-toggle="dropdown"
                        aria-expanded="false"
                        data-current-status="<?= h($entity->status) ?>">
                    <i class="bi bi-circle-fill" id="status-icon" style="color: <?= h($currentConfig['color'] ?? '#6c757d') ?>"></i>
                    <span class="composer-status-text">Enviar como
                        <strong id="status-label"><?= h($currentConfig['label'] ?? 'Estado') ?></strong>
                    </span>
                    <i class="bi bi-chevron-up chev"></i>
                </button>
                <ul class="dropdown-menu shadow-sm composer-status-menu" aria-labelledby="status-dropdown">
                    <?php foreach ($statuses as $statusKey => $statusConfig): ?>
                        <li>
                            <a class="dropdown-item composer-status-item"
                               href="#"
                               data-action="set-status"
                               data-status-key="<?= h($statusKey) ?>">
                                <i class="bi bi-circle-fill" style="color: <?= h($statusConfig['color']) ?>"></i>
                                <span><?= h($statusConfig['label']) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <span class="composer-shortcut mono" title="Atajo">⌘ ⏎</span>

            <?= $this->Form->button('<i class="bi bi-send-fill"></i> Enviar respuesta', [
                'class'       => 'btn-brand-primary composer-send-btn',
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
    if (empty($field)) return [];
    $decoded = is_string($field) ? json_decode($field, true) : $field;
    if (!is_array($decoded)) return [];
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
