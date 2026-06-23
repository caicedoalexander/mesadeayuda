<!-- Left Sidebar - Ticket Info (with independent scroll) -->
<div class="sidebar-left d-flex flex-column p-3">
    <div class="sidebar-scroll flex-grow-1 overflow-auto shadow-sm bg-white" style="border-radius: 8px;">
        <div class="p-3">
        <?php
        // Check if ticket is locked (in final status)
        $isLocked = $isLocked ?? in_array($ticket->status, ['resuelto', 'convertido']);
        ?>
        <section class="mb-3">
            <h3 class="fs-6 fw-semibold mb-3">Información del Ticket</h3>

            <div class="mb-3">
                <label class="small text-muted fw-semibold mb-1">Estado:</label>
                <div>
                    <?= $this->Status->statusBadge($ticket->status) ?>
                    <?php if ($isLocked): ?>
                        <i class="bi bi-lock-fill text-muted" title="Solicitud cerrada"></i>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mb-3">
                <label class="small text-muted fw-semibold mb-1">Prioridad:</label>
                <div class="mb-2">
                    <?= $this->Status->priorityBadge($ticket->priority) ?>
                </div>
                <?php if (!$isLocked): ?>
                <?= $this->Form->create(null, ['url' => ['action' => 'changePriority', $ticket->id], 'class' => '']) ?>
                <?= $this->Form->select('priority', [
                    'baja' => 'Cambiar a Baja',
                    'media' => 'Cambiar a Media',
                    'alta' => 'Cambiar a Alta',
                    'urgente' => 'Cambiar a Urgente'
                ], [
                    'empty' => '-- Cambiar prioridad --',
                    'class' => 'form-select form-select-sm',
                    'onchange' => 'this.form.submit()'
                ]) ?>
                <?= $this->Form->end() ?>
                <?php endif; ?>
            </div>

            <div class="d-flex align-items-center gap-2">
                <label class="small text-muted fw-semibold">Canal:</label>
                <?php if ($ticket->channel === 'email'): ?>
                    <i class="bi bi-envelope text-secondary fs-5"></i>
                <?php else: ?>
                    <i class="bi bi-whatsapp text-success fs-5"></i>
                <?php endif; ?>
            </div>
        </section>

        <!--
            <section class="mb-4">
                <h3 class="fs-6 fw-semibold mb-3">Solicitante</h3>
                <div>
                    <strong class="d-block"><?= h($ticket->requester->name) ?></strong>
                    <small class="text-muted"><?= h($ticket->requester->email) ?></small>
                    <?php if ($ticket->requester->phone): ?>
                        <br><small class="text-muted">📞 <?= h($ticket->requester->phone) ?></small>
                    <?php endif; ?>
                </div>
            </section>
        -->

        <section class="mb-3">
            <h3 class="small text-muted fw-semibold mb-1">Asignación:</h3>
            <?= $this->Form->create(null, ['url' => ['action' => 'assign', $ticket->id], 'class' => 'm-0', 'id' => 'assign-form']) ?>
            <?= $this->Form->select('assignee_id', $agents, [
                'empty' => '-- Sin asignar --',
                'value' => $ticket->assignee_id,
                'class' => 'form-select form-select-sm',
                'id' => 'agent-select',
                'disabled' => $isAssignmentDisabled || $isLocked,
            ]) ?>
            <?= $this->Form->end() ?>
        </section>

        <?php if (!empty($ticket->tags) || (!$isLocked && !empty($tags))): ?>
            <section class="">
                <h3 class="small text-muted fw-semibold mb-1">Etiquetas:</h3>
                <?php if (!empty($ticket->tags)): ?>
                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <?php foreach ($ticket->tags as $tag): ?>
                            <span class="small px-2 py-1 text-white shadow-sm" style="background-color: <?= h($tag->color) ?>; border-radius: 8px;">
                                <?= h($tag->name) ?>
                                <?php if (!$isLocked): ?>
                                    <?= $this->Form->postLink('<i class="bi bi-trash-fill"></i>', ['action' => 'removeTag', $ticket->id, $tag->id], [
                                        'confirm' => '¿Eliminar etiqueta?',
                                        'class' => 'text-white text-decoration-none ms-1 fw-bold', 'escape' => false
                                    ]) ?>
                                <?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if (!$isLocked && !empty($tags)): ?>
                    <?= $this->Form->create(null, ['url' => ['action' => 'addTag', $ticket->id]]) ?>
                    <?= $this->Form->control('tag_id', [
                        'options' => $tags,
                        'empty' => '-- Agregar etiqueta --',
                        'label' => false,
                        'class' => 'select2-tags form-select form-select-sm',
                    ]) ?>
                    <?= $this->Form->button('Agregar', ['class' => 'btn btn-outline-secondary btn-sm w-100 my-2']) ?>
                    <?= $this->Form->end() ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>
        </div>
    </div>
</div>