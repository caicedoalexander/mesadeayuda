<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Ticket $ticket
 * @var array $statuses
 * @var array $agents
 * @var array $tags
 * @var bool $isLocked
 * @var bool $isAssignmentDisabled
 */
$this->assign('title', $ticket->ticket_number);
$user = $this->getRequest()->getAttribute('identity');
$userRole = $user ? $user->get('role') : null;
$userId = $user ? $user->get('id') : null;
$currentUser = $user;
?>

<?= $this->cell('TicketsSidebar::display', ['__detail__', $userRole, $userId]) ?>

<section class="ticket-detail flex-grow-1 d-flex flex-column">
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
            'tags'                => $tags,
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
