<?php
/**
 * Element: Styles and Scripts for Tickets view.
 *
 * @var object $entity Current ticket entity
 * @var array $statuses Status configuration from controller
 */
?>

<?= $this->Html->css('tickets-view', ['block' => 'css']) ?>

<script>
    window.ticketViewData = {
        statusConfig: <?= json_encode($statuses) ?>,
        currentStatus: <?= json_encode($entity->status ?? 'nuevo') ?>
    };
</script>

<?= $this->Html->script('tickets-view', ['block' => 'script']) ?>
<?= $this->Html->script('email-recipients', ['block' => 'script']) ?>
<?= $this->Html->script('entity-history-lazy', ['block' => 'script']) ?>
