<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Ticket $ticket
 * @var array $statuses Injected by controller trait
 */
$this->assign('title', $ticket->ticket_number);
$user = $this->getRequest()->getAttribute('identity');
?>

<div class="ticket-view-container">
    <?= $this->element('tickets/left_sidebar', [
        'ticket' => $ticket,
        'agents' => $agents,
        'tags' => $tags,
        'user' => $user
    ]) ?>

    <!-- Main Content Area -->
    <div class="main-content d-flex flex-column p-3 gap-2">
        <?= $this->element('tickets/header', [
            'entity' => $ticket,
        ]) ?>

        <?= $this->element('tickets/comments_list', [
            'entity' => $ticket,
            'comments' => $ticket->ticket_comments ?? [],
            'description' => $ticket->description ?? '',
            'attachments' => $ticket->attachments ?? []
        ]) ?>

        <?= $this->element('tickets/reply_editor', [
            'entity' => $ticket,
            'statuses' => $statuses,
            'currentUser' => $currentUser,
            'isLocked' => $isLocked
        ]) ?>
    </div>

    <?= $this->element('tickets/right_sidebar', ['ticket' => $ticket]) ?>
</div>

<?= $this->element('tickets/styles_and_scripts', [
    'entity' => $ticket,
    'statuses' => $statuses
]) ?>
