<?php
/**
 * Element: Reply Editor para Tickets.
 *
 * @var object $entity Ticket entity
 * @var array $statuses Estados disponibles con config (icon, color, label)
 * @var object $currentUser Usuario actual
 * @var bool $isLocked Si el ticket está en estado final
 */

$statuses = $statuses ?? [];
$currentUser = $currentUser ?? null;
$isLocked = $isLocked ?? false;

$requesterName = $entity->requester->name ?? $entity->requester->email;
$requesterEmail = $entity->requester->email;
?>

<?php if ($isLocked): ?>
<!-- Locked Entity Notice -->
<div class="alert alert-danger m-3 fw-light" role="alert">
    <i class="bi bi-lock-fill me-2"></i>
    <strong>Solicitud cerrada:</strong> Esta solicitud está en estado final y no puede ser modificada.
</div>
<?php else: ?>
<!-- Fixed Reply Editor -->
<div class="reply-editor position-relative bg-white shadow-sm w-100 border" style="border-radius: 8px; min-height: 225px;">
    <?= $this->Form->create(null, [
        'url' => ['action' => 'addComment', $entity->id],
        'type' => 'file',
        'id' => 'reply-form',
        'style' => 'min-height: 100%'
    ]) ?>

    <?= $this->Form->hidden('comment_type', ['value' => 'public', 'id' => 'comment-type']) ?>

    <!-- Comment Type Selector (Dropdown style) -->
    <div class="d-flex align-items-center gap-4 px-3 py-2">
        <div class="dropup">
            <button
                    type="button"
                    id="comment-type-dropdown"
                    data-bs-toggle="dropdown"
                    aria-expanded="false" class="btn py-2 text-muted px-4 btn-sm dropdown-toggle shadow-sm d-flex align-items-center gap-2 comment-type-selector rounded-md">
                <i class="bi bi-reply-fill text-muted" id="comment-type-icon"></i>
                <span id="comment-type-label" class="text-muted">Respuesta pública</span>
            </button>
            <ul class="dropdown-menu w-100 shadow p-0 mb-2 rounded-md" aria-labelledby="comment-type-dropdown" >
                <li>
                    <a class="dropdown-item d-flex text-muted align-items-center gap-2 px-3" href="#" onclick="setCommentType('public'); return false;">
                        <i class="bi bi-reply-fill"></i>
                        <span class="">Respuesta pública</span>
                    </a>
                </li>
                <li>
                    <a class="dropdown-item d-flex text-muted align-items-center gap-2 px-3 bg-warning bg-opacity-10" href="#" onclick="setCommentType('internal'); return false;">
                        <i class="bi bi-pencil-square"></i>
                        <span class="">Nota interna</span>
                    </a>
                </li>
            </ul>
        </div>
        <div class="" id="comment-type-recipients" onclick="expandRecipients()" style="cursor: pointer;">
            <span id="comment-type-recipients-text"
                  class="fs-sm"
                  data-original-text="<?= h($requesterName) ?>">
                <?= h($requesterName) ?>
            </span>
        </div>
    </div>

    <div class="position-relative px-3 d-flex flex-column bg-transparent" id="editor-container" style="min-height: 170px;">
        <!-- Email Recipients Section (only visible for public responses) -->
        <div id="email-recipients-section" class="position-absolute w-100 end-0 top-0 rounded" style="display: none; z-index: 10;">
            <!-- Collapsed View (Summary) -->
            <div id="recipients-collapsed">
            </div>

            <!-- Expanded View (Full Inputs) -->
            <div id="recipients-expanded" class="px-4 border-bottom" style="display: none; height: 170px; border-radius: 8px;">
                <div class="d-flex justify-content-center align-items-center">
                    <button type="button" class="btn btn-link text-decoration-none p-1 border my-2" onclick="collapseRecipients()">
                        <i class="bi bi-chevron-up"></i>
                    </button>
                </div>
                <div class="d-flex flex-column gap-2">
                    <!-- Para (To) TagInput -->
                    <div class="d-flex gap-2 align-items-center">
                        <label for="email-to" class="form-label small m-0 fw-semibold" style="min-width: 40px;">Para:</label>
                        <div class="flex-fill">
                            <div
                                 id="email-to-container" class="tag-input-container d-flex flex-wrap align-items-center gap-1 bg-white border rounded px-2 py-1 editable-line">
                                <!-- Los tags se renderizarán aquí -->
                                <input type="text"
                                    id="email-to"
                                    placeholder="Agregar destinatario"
                                    autocomplete="off" class="tag-input-field border-0 flex-fill fw-light tag-input-inline">
                            </div>
                            <input type="hidden" name="email_to" id="email-to-hidden" value="">
                        </div>
                    </div>

                    <!-- CC TagInput -->
                    <div class="d-flex gap-2 align-items-center">
                        <label for="email-cc" class="form-label small m-0 fw-semibold" style="min-width: 40px;">CC:</label>
                        <div class="flex-fill">
                            <div
                                 id="email-cc-container" class="tag-input-container d-flex flex-wrap align-items-center gap-1 bg-white border rounded px-2 py-1 editable-line">
                                <!-- Los tags se renderizarán aquí -->
                                <input type="text"
                                    id="email-cc"
                                    placeholder="Agregar copia"
                                    autocomplete="off" class="tag-input-field border-0 flex-fill fw-light tag-input-inline">
                            </div>
                            <input type="hidden" name="email_cc" id="email-cc-hidden" value="">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?= $this->Form->control('comment_body', [
            'type' => 'textarea',
            'label' => false,
            'placeholder' => 'Escribe tu respuesta aquí...',
            'class' => 'form-control form-control-sm p-3 shadow-none w-100 overflow-auto scroll',
            'required' => false,
            'id' => 'comment-textarea',
            'rows' => 4,
            'style' => 'resize: none; border-radius: 8px; min-height: 154px;'
        ]) ?>

        <div class="position-absolute bottom-0 start-0 w-100">
            <div class="mx-4 mb-4 px-2 py-1 d-flex justify-content-between align-items-start">
                <div>
                    <label class="btn btn-secondary rounded shadow-sm" id="file-upload-btn">
                        <i class="bi bi-paperclip fw-bold"></i>
                        <?= $this->Form->file('attachments[]', [
                            'multiple' => true,
                            'id' => 'file-input',
                            'onchange' => 'handleFileSelect(event)',
                            'style' => 'display: none;',
                            'accept' => '.jpg,.jpeg,.png,.gif,.bmp,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar,.7z'
                        ]) ?>
                    </label>
                    <div id="file-list" class="file-list"></div>
                </div>

                <div class="d-flex align-items-center gap-2 rounded-md" >
                    <!-- Status Selector (Dropdown style) -->
                    <?= $this->Form->hidden('status', ['value' => $entity->status, 'id' => 'status-hidden']) ?>

                    <div class="dropup">
                        <?php
                            $currentConfig = $statuses[$entity->status] ?? $statuses[array_key_first($statuses)] ?? [];
                        ?>
                        <button class="btn border dropdown-toggle bg-light d-flex align-items-center gap-2 status-selector shadow-none rounded-md" 
                                type="button"
                                id="status-dropdown"
                                data-bs-toggle="dropdown"
                                aria-expanded="false"
                                data-current-status="<?= h($entity->status) ?>">
                            <i class="bi bi-circle-fill" id="status-icon" style="color: <?= h($currentConfig['color'] ?? '#6c757d') ?>;"></i>
                            <span id="status-label" class="text-dark fw-bold small">Enviar como <?= h($currentConfig['label'] ?? 'Estado') ?></span>
                        </button>
                        <ul class="dropdown-menu rounded shadow-sm p-0 mb-2" aria-labelledby="status-dropdown">
                            <?php foreach ($statuses as $statusKey => $statusConfig): ?>
                            <li>
                                <a class="dropdown-item fw-bold d-flex align-items-center py-1 gap-2 fs-sm"  href="#" onclick="setStatus('<?= h($statusKey) ?>'); return false;">
                                    <i class="bi bi-circle-fill" style="color: <?= h($statusConfig['color']) ?>;"></i>
                                    <span><?= h($statusConfig['label']) ?></span>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <?= $this->Form->button('<i class="bi bi-send"></i>', [
                        'class' => 'btn btn-success', 'style' => 'border-radius: 8px; font-size: 14px;',
                        'type' => 'submit', 'escapeTitle' => false
                    ]) ?>
                </div>
            </div>
        </div>
    </div>

    <?= $this->Form->end() ?>
</div>
<?php endif; // End isLocked check ?>

<?php
$systemEmailAddr = strtolower($systemConfig['smtp_username'] ?? $systemConfig['gmail_user_email'] ?? '');

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
        systemEmail: <?= json_encode($systemConfig['smtp_username'] ?? '') ?>
    };
</script>
<?= $this->Html->script('reply-editor-init', ['block' => 'script']) ?>
