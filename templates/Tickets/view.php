<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Ticket $ticket
 * @var array $statuses
 * @var array $agents
 * @var array $tags
 * @var bool $isLocked
 * @var bool $isAssignmentDisabled
 * @var \App\Model\Entity\User|null $currentUser
 */
$this->assign('title', '#' . $ticket->id);
$this->assign('current_view', '__detail__');
$this->assign('shell_modifier', 'flush');
?>

<section class="ticket-detail d-flex flex-column" style="flex: 1; min-height: 0;">
    <?= $this->element('tickets/header', [
        'entity'   => $ticket,
        'isLocked' => $isLocked,
    ]) ?>

    <div class="ticket-detail-body">
        <div class="ticket-thread-column">
            <?= $this->element('tickets/comments_list', [
                'entity'      => $ticket,
                'comments'    => $ticket->ticket_comments ?? [],
                'description' => $ticket->description ?? '',
                'attachments' => $ticket->attachments ?? [],
                'tags'        => $tags,
                'isLocked'    => $isLocked,
            ]) ?>

            <?= $this->element('tickets/reply_editor', [
                'entity'      => $ticket,
                'statuses'    => $statuses,
                'currentUser' => $currentUser,
                'isLocked'    => $isLocked,
            ]) ?>
        </div>

        <?= $this->element('tickets/right_sidebar', [
            'ticket'              => $ticket,
            'agents'              => $agents,
            'isLocked'            => $isLocked,
            'isAssignmentDisabled'=> $isAssignmentDisabled,
            'currentUser'         => $currentUser,
        ]) ?>
    </div>
</section>

<?= $this->element('tickets/styles_and_scripts', [
    'entity'   => $ticket,
    'statuses' => $statuses,
]) ?>
