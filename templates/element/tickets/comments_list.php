<?php
/**
 * Element: Ticket thread (title block + messages).
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Ticket $entity
 * @var array $comments
 * @var string $description
 * @var array $attachments
 */

use App\Constants\TicketConstants;

$comments = $comments ?? [];
$description = $description ?? ($entity->description ?? '');
$attachments = $attachments ?? [];
$requesterName = $entity->requester->name ?? 'Desconocido';
$requesterUser = $entity->requester ?? null;

$priorityGlyphs = [
    TicketConstants::PRIORITY_BAJA    => '↓',
    TicketConstants::PRIORITY_MEDIA   => '→',
    TicketConstants::PRIORITY_ALTA    => '↑',
    TicketConstants::PRIORITY_URGENTE => '↑',
];

$entityAttachments = array_filter($attachments, function ($a) use ($description) {
    if ($a->comment_id !== null) {
        return false;
    }
    if (!$a->is_inline) {
        return true;
    }
    return $a->content_id && strpos($description, $a->content_id) === false;
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
                Abierto <?= $this->TimeHuman->short($entity->created) ?>
                ·
                <span class="mono"><?= $this->TimeHuman->long($entity->created) ?></span>
            </span>
        </div>
        <h1 class="thread-title"><?= h($entity->subject) ?></h1>

        <?php if (!empty($entity->tags)): ?>
            <div class="thread-tags">
                <?php foreach ($entity->tags as $tag): ?>
                    <span class="ticket-tag-chip"
                          style="background:<?= h($tag->color) ?>20; color:<?= h($tag->color) ?>; border-color:<?= h($tag->color) ?>40">
                        <?= h($tag->name) ?>
                    </span>
                <?php endforeach; ?>
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
                $commentAttachments = array_filter($attachments, function ($a) use ($comment) {
                    if ($a->comment_id !== $comment->id) return false;
                    if (!$a->is_inline) return true;
                    return $a->content_id && strpos($comment->body, $a->content_id) === false;
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
