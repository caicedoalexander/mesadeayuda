<?php
/**
 * Element: Ticket detail right sidebar (metadata panel).
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Ticket $ticket
 * @var array $agents
 * @var bool $isLocked
 * @var bool $isAssignmentDisabled
 * @var \App\Model\Entity\User|null $currentUser
 */

use App\Constants\TicketConstants;

$priorities = TicketConstants::PRIORITY_LABELS;
$priorityGlyphs = [
    TicketConstants::PRIORITY_BAJA    => '↓',
    TicketConstants::PRIORITY_MEDIA   => '→',
    TicketConstants::PRIORITY_ALTA    => '↑',
    TicketConstants::PRIORITY_URGENTE => '↑',
];

$assignedToCurrent = $currentUser && $ticket->assignee_id === $currentUser->id;
?>
<aside class="ticket-meta-sidebar">

    <!-- Solicitante -->
    <section class="meta-section">
        <h4 class="meta-label">Solicitante</h4>
        <div class="meta-requester">
            <?= $this->User->profileImageTag($ticket->requester, [
                'width' => '36', 'height' => '36',
                'class' => 'meta-requester-avatar',
            ]) ?>
            <div class="meta-requester-text">
                <div class="meta-requester-name"><?= h($ticket->requester->name ?? '—') ?></div>
                <div class="meta-requester-email" title="<?= h($ticket->requester->email ?? '') ?>">
                    <?= h($ticket->requester->email ?? '') ?>
                </div>
            </div>
        </div>
        <?php if (!empty($ticket->requester->phone)): ?>
            <div class="meta-requester-phone">
                <i class="bi bi-telephone"></i> <?= h($ticket->requester->phone) ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- Asignado a -->
    <section class="meta-section">
        <h4 class="meta-label">Asignado a</h4>

        <?php if (!$ticket->hasAssignee() && !$isAssignmentDisabled && !$isLocked && $currentUser): ?>
            <?= $this->Form->postLink(
                '<i class="bi bi-plus-lg"></i> Asignarme este ticket',
                ['action' => 'assign', $ticket->id],
                [
                    'class' => 'btn-self-assign',
                    'escape' => false,
                    'data' => ['assignee_id' => $currentUser->id],
                ]
            ) ?>
        <?php elseif ($ticket->hasAssignee()): ?>
            <div class="meta-assignee-current">
                <span class="agent-avatar"
                      style="width:28px;height:28px;font-size:12px;background:<?= h($this->User->avatarColor($ticket->assignee)) ?>">
                    <?= h($this->User->initials($ticket->assignee, 2)) ?>
                </span>
                <span class="meta-assignee-name"><?= h($ticket->assignee->name) ?></span>
                <?php if ($assignedToCurrent): ?>
                    <span class="meta-assignee-you">tú</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!$isAssignmentDisabled && !$isLocked):
            $otherAgents = [];
            foreach (($agents ?? []) as $agentId => $agentName) {
                if ($currentUser && $agentId === $currentUser->id) {
                    continue;
                }
                if ($ticket->hasAssignee() && $agentId === $ticket->assignee_id) {
                    continue;
                }
                $otherAgents[$agentId] = $agentName;
            }
        ?>
            <?php if (!empty($otherAgents)): ?>
                <div class="meta-reassign-avatars">
                    <span class="meta-reassign-label">
                        <?= $ticket->hasAssignee() ? 'o reasignar a:' : 'o elige a otro agente:' ?>
                    </span>
                    <div class="meta-reassign-list">
                        <?php foreach ($otherAgents as $agentId => $agentName): ?>
                            <?= $this->Form->postLink(
                                h($this->User->initials($agentName, 2)),
                                ['action' => 'assign', $ticket->id],
                                [
                                    'class'    => 'agent-avatar meta-reassign-avatar',
                                    'escape'   => false,
                                    'title'    => $agentName,
                                    'aria-label' => 'Asignar a ' . $agentName,
                                    'data'     => ['assignee_id' => $agentId],
                                    'style'    => 'background:' . h($this->User->avatarColor($agentName)),
                                ]
                            ) ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <!-- Prioridad -->
    <section class="meta-section">
        <h4 class="meta-label">Prioridad</h4>
        <?php if (!$isLocked): ?>
            <?= $this->Form->create(null, [
                'url' => ['action' => 'changePriority', $ticket->id],
                'class' => 'priority-segmented',
                'id' => 'priority-form',
            ]) ?>
            <?php foreach ($priorities as $key => $label): ?>
                <?php $isActive = $ticket->priority === $key; ?>
                <button type="submit"
                        name="priority"
                        value="<?= h($key) ?>"
                        class="priority-seg priority-seg-<?= h($key) ?><?= $isActive ? ' active' : '' ?>"
                        title="<?= h($label) ?>">
                    <span class="glyph"><?= $priorityGlyphs[$key] ?? '' ?></span>
                    <span class="lbl"><?= h($label) ?></span>
                </button>
            <?php endforeach; ?>
            <?= $this->Form->end() ?>
        <?php else: ?>
            <span class="badge badge-priority badge-priority-<?= h($ticket->priority) ?>">
                <?= h($priorities[$ticket->priority] ?? ucfirst($ticket->priority)) ?>
            </span>
        <?php endif; ?>
    </section>

    <!-- Actividad -->
    <section class="meta-section meta-activity-section">
        <h4 class="meta-label">Actividad</h4>
        <div id="history-container"
             class="meta-activity-timeline"
             data-entity-type="ticket"
             data-entity-id="<?= $ticket->id ?>"
             data-loaded="false">
            <div id="history-loader" class="skeleton-activity" aria-label="Cargando actividad" aria-busy="true">
                <?php for ($i = 0; $i < 3; $i++): ?>
                    <div class="skeleton-activity-item">
                        <span class="skeleton skeleton-dot"></span>
                        <div class="skeleton-activity-text">
                            <span class="skeleton skeleton-line-sm" style="width: 78%"></span>
                            <span class="skeleton skeleton-line-sm" style="width: 48%; height: 8px;"></span>
                        </div>
                    </div>
                <?php endfor; ?>
            </div>
            <div id="history-content" class="meta-activity-content" style="display: none;"></div>
        </div>
    </section>

</aside>
