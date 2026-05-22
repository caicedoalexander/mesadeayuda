<?php
/**
 * Element: Ticket thread (title block + messages).
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Ticket $entity
 * @var array $comments
 * @var string $description
 * @var array $attachments
 * @var array $tags
 * @var bool $isLocked
 */

use App\Constants\TicketConstants;

$comments = $comments ?? [];
$description = $description ?? ($entity->description ?? '');
$attachments = $attachments ?? [];
$tags = $tags ?? [];
$isLocked = $isLocked ?? false;
$requesterName = $entity->requester->name ?? 'Desconocido';
$requesterUser = $entity->requester ?? null;

$assignedTagIds = [];
foreach (($entity->tags ?? []) as $t) {
    $assignedTagIds[$t->id] = true;
}
$availableTags = [];
foreach ($tags as $tagId => $tagName) {
    if (!isset($assignedTagIds[$tagId])) {
        $availableTags[$tagId] = $tagName;
    }
}

$priorityGlyphs = [
    TicketConstants::PRIORITY_BAJA    => '↓',
    TicketConstants::PRIORITY_MEDIA   => '→',
    TicketConstants::PRIORITY_ALTA    => '↑',
    TicketConstants::PRIORITY_URGENTE => '↑',
];

// Hide inline images from the attachment-cards strip only when the body
// already references their local URL (rewriteCidReferences resolved
// cid:<id> → /uploads/.../$a->filename in TicketIngestionService). Before
// the CRIT-4 fix the comparison was against $a->content_id, which became
// stale post-rewrite — the cid: token no longer appears anywhere in the
// body. If the URL is missing, treat the image as an orphan inline and
// surface it as a card. See audit CRIT-4 (F1+F2+G1).
$entityAttachments = array_filter($attachments, function ($a) use ($description) {
    if ($a->comment_id !== null) {
        return false;
    }
    if (!$a->is_inline) {
        return true;
    }
    return $a->filename && strpos($description, $a->filename) === false;
});
?>

<div class="thread-scroll">

    <!-- Title block -->
    <section class="thread-title-block">
        <div class="thread-title-meta">
            <?= $this->Status->statusBadge($entity->status) ?>
            <span class="priority-flag <?= h($entity->priority) ?>">
                <span class="glyph"><?= $priorityGlyphs[$entity->priority] ?? '' ?></span>
                <?= h($this->Status->priorityLabel($entity->priority)) ?>
            </span>
            <span class="thread-meta-sep"></span>
            <span class="thread-meta-time">
                Creado el
                <span><?= $this->TimeHuman->long($entity->created) ?></span>
            </span>
        </div>
        <h1 class="thread-title"><?= h($entity->subject) ?></h1>

        <?php if (!empty($entity->tags) || (!$isLocked && !empty($availableTags))): ?>
            <div class="thread-tags">
                <?php foreach (($entity->tags ?? []) as $tag): ?>
                    <span class="tag-chip"
                          style="background:<?= h($tag->color) ?>20; color:<?= h($tag->color) ?>; border-color:<?= h($tag->color) ?>40">
                        <?= h($tag->name) ?>
                        <?php if (!$isLocked): ?>
                            <?= $this->Form->postLink(
                                '<i class="bi bi-x"></i>',
                                ['action' => 'removeTag', $entity->id, $tag->id],
                                ['confirm' => '¿Eliminar etiqueta?', 'escape' => false, 'class' => 'tag-remove']
                            ) ?>
                        <?php endif; ?>
                    </span>
                <?php endforeach; ?>

                <?php if (!$isLocked && !empty($availableTags)): ?>
                    <div class="dropdown add-tag-dropdown">
                        <button type="button"
                                class="btn-add-tag"
                                data-bs-toggle="dropdown"
                                data-bs-auto-close="true"
                                aria-expanded="false">
                            <i class="bi bi-plus"></i> añadir etiqueta
                        </button>
                        <ul class="dropdown-menu add-tag-menu shadow-sm">
                            <li class="add-tag-menu-header">Disponibles</li>
                            <?php foreach ($availableTags as $tagId => $tagName): ?>
                                <li>
                                    <?= $this->Form->postLink(
                                        '<i class="bi bi-tag-fill"></i> ' . h($tagName),
                                        ['action' => 'addTag', $entity->id],
                                        [
                                            'class'  => 'dropdown-item add-tag-item',
                                            'escape' => false,
                                            'data'   => ['tag_id' => $tagId],
                                        ]
                                    ) ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- Messages thread -->
    <section class="thread-messages">
        <!-- Original message (Solicitante) -->
        <?= $this->element('tickets/_thread_message', [
            'user'        => $requesterUser,
            'fallbackName' => $requesterName,
            'when'        => $entity->created,
            'role'        => 'solicitante',
            'body'        => $description,
            'attachments' => $entityAttachments,
            'emailTo'     => $entity->email_to_array ?? [],
            'emailCc'     => $entity->email_cc_array ?? [],
            'recipientsId' => 'recipients-' . $entity->id,
        ]) ?>

        <!-- Comments -->
        <?php foreach ($comments as $comment): ?>
            <?php if ($comment->is_system_comment): ?>
                <div class="thread-system-message">
                    <?= $this->Sanitize->html($comment->body) ?>
                </div>
            <?php else:
                // Same rationale as $entityAttachments above: compare against
                // the local filename (post-rewrite the body no longer contains
                // cid: tokens). See audit CRIT-4 (F1+F2+G1).
                $commentAttachments = array_filter($attachments, function ($a) use ($comment) {
                    if ($a->comment_id !== $comment->id) return false;
                    if (!$a->is_inline) return true;
                    return $a->filename && strpos($comment->body, $a->filename) === false;
                });
                $cTo = !empty($comment->email_to) ? json_decode($comment->email_to, true) : [];
                $cCc = !empty($comment->email_cc) ? json_decode($comment->email_cc, true) : [];
            ?>
                <?= $this->element('tickets/_thread_message', [
                    'user'        => $comment->user,
                    'fallbackName' => $comment->user->name ?? 'Agente',
                    'when'        => $comment->created,
                    'role'        => $comment->comment_type === 'internal' ? 'nota-interna' : 'agente',
                    'body'        => $comment->body,
                    'attachments' => $commentAttachments,
                    'emailTo'     => $cTo,
                    'emailCc'     => $cCc,
                    'recipientsId' => 'comment-recipients-' . $comment->id,
                ]) ?>
            <?php endif; ?>
        <?php endforeach; ?>
    </section>
</div>
