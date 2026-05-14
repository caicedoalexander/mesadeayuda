<?php
/**
 * Element: Ticket detail right sidebar (metadata panel).
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Ticket $ticket
 * @var array $agents
 * @var array $tags
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

        <?php if (!$isAssignmentDisabled && !$isLocked): ?>
            <?= $this->Form->create(null, [
                'url' => ['action' => 'assign', $ticket->id],
                'class' => 'meta-reassign-form',
                'id' => 'assign-form',
            ]) ?>
            <label class="meta-reassign-label" for="agent-select">
                <?= $ticket->hasAssignee() ? 'o reasignar a:' : 'o elige a otro agente:' ?>
            </label>
            <?= $this->Form->select('assignee_id', $agents, [
                'empty'    => '— Seleccionar agente —',
                'value'    => $ticket->assignee_id,
                'class'    => 'form-select form-select-sm meta-assignee-select',
                'id'       => 'agent-select',
                'disabled' => $isAssignmentDisabled || $isLocked,
            ]) ?>
            <?= $this->Form->end() ?>
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

    <!-- Etiquetas -->
    <?php
    $assignedTagIds = [];
    if (!empty($ticket->tags)) {
        foreach ($ticket->tags as $t) {
            $assignedTagIds[$t->id] = true;
        }
    }
    $availableTags = [];
    foreach (($tags ?? []) as $tagId => $tagName) {
        if (!isset($assignedTagIds[$tagId])) {
            $availableTags[$tagId] = $tagName;
        }
    }
    ?>
    <section class="meta-section">
        <h4 class="meta-label">Etiquetas</h4>
        <div class="meta-tags">
            <?php if (!empty($ticket->tags)): ?>
                <?php foreach ($ticket->tags as $tag): ?>
                    <span class="ticket-tag-chip" style="background:<?= h($tag->color) ?>20; color:<?= h($tag->color) ?>; border-color:<?= h($tag->color) ?>40">
                        <?= h($tag->name) ?>
                        <?php if (!$isLocked): ?>
                            <?= $this->Form->postLink('<i class="bi bi-x"></i>',
                                ['action' => 'removeTag', $ticket->id, $tag->id],
                                ['confirm' => '¿Eliminar etiqueta?', 'escape' => false, 'class' => 'tag-remove']
                            ) ?>
                        <?php endif; ?>
                    </span>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!$isLocked && !empty($availableTags)): ?>
                <div class="dropdown add-tag-dropdown">
                    <button type="button"
                            class="btn-add-tag"
                            data-bs-toggle="dropdown"
                            data-bs-auto-close="true"
                            aria-expanded="false">
                        <i class="bi bi-plus"></i> añadir
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end add-tag-menu shadow-sm">
                        <li class="add-tag-menu-header">Disponibles</li>
                        <?php foreach ($availableTags as $tagId => $tagName): ?>
                            <li>
                                <?= $this->Form->postLink(
                                    '<i class="bi bi-tag-fill"></i> ' . h($tagName),
                                    ['action' => 'addTag', $ticket->id],
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
            <?php elseif (!$isLocked && empty($availableTags) && empty($ticket->tags)): ?>
                <span class="meta-tags-empty">Sin etiquetas disponibles</span>
            <?php endif; ?>
        </div>
    </section>

    <!-- Actividad -->
    <section class="meta-section meta-activity-section">
        <h4 class="meta-label">Actividad</h4>
        <div id="history-container"
             class="meta-activity-timeline"
             data-entity-type="ticket"
             data-entity-id="<?= $ticket->id ?>"
             data-loaded="false">
            <div id="history-loader" class="meta-activity-loader">
                <div class="spinner-border spinner-border-sm" role="status">
                    <span class="visually-hidden">Cargando…</span>
                </div>
                <span>Cargando actividad…</span>
            </div>
            <div id="history-content" class="meta-activity-content" style="display: none;"></div>
        </div>
    </section>

</aside>
