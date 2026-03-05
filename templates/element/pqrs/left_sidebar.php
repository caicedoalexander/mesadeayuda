<!-- Left Sidebar - PQRS Info (with independent scroll) -->
<div class="sidebar-left d-flex flex-column p-3">
    <div class="sidebar-scroll flex-grow-1 overflow-auto shadow-sm bg-white" style="border-radius: 8px;">
        <div class="p-3">
        <?php
        // Check if PQRS is locked (in final status)
        $isLocked = $isLocked ?? in_array($pqrs->status, ['resuelto', 'cerrado']);
        ?>
        <section class="mb-4">
            <h3 class="fs-6 fw-semibold mb-3">Información del PQRS</h3>

            <div class="mb-3">
                <label class="small text-muted fw-semibold mb-1">Tipo:</label>
                <div><?= $this->Status->typeBadge($pqrs->type) ?></div>
            </div>

            <div class="mb-3">
                <label class="small text-muted text-muted fw-semibold mb-1">Estado:</label>
                <div>
                    <?= $this->Status->statusBadge($pqrs->status, 'pqrs') ?>
                    <?php if ($isLocked): ?>
                        <i class="bi bi-lock-fill text-muted" title="Solicitud cerrada"></i>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mb-3">
                <label class="small text-muted text-muted fw-semibold mb-1">Prioridad:</label>
                <div class="mb-2">
                    <?= $this->Status->priorityBadge($pqrs->priority) ?>
                </div>
                <?php if (!$isLocked): ?>
                <?= $this->Form->create(null, ['url' => ['action' => 'changePriority', $pqrs->id], 'class' => 'm-0']) ?>
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

            <div class="mb-3">
                <label class="small text-muted text-muted fw-semibold">Canal:</label>
                <div class="small text-uppercase"><?= h($pqrs->channel) ?></div>
            </div>

            <div class="mb-3">
                <label class="small text-muted fw-semibold mb-1">SLA:</label>
                <?php
                $firstResponseSla = $this->Sla->getSlaDisplayStatus(
                    $pqrs->first_response_sla_due,
                    $pqrs->first_response_at,
                    $pqrs->created,
                    $pqrs->status,
                    ['completado', 'cerrado', 'resuelto'],
                    'first_response'
                );
                $resolutionSla = $this->Sla->getSlaDisplayStatus(
                    $pqrs->resolution_sla_due,
                    $pqrs->resolved_at,
                    $pqrs->created,
                    $pqrs->status,
                    ['completado', 'cerrado', 'resuelto']
                );
                ?>
                <div><?= $this->Sla->dualSlaIndicator($firstResponseSla, $resolutionSla) ?></div>
            </div>
        </section>

        <section class="mb-4">
            <h3 class="fs-6 fw-semibold mb-3">Asignación</h3>
            <?= $this->Form->create(null, ['url' => ['action' => 'assign', $pqrs->id], 'class' => 'm-0', 'id' => 'assign-form']) ?>
            <?= $this->Form->select('assignee_id', $agents, [
                'empty' => '-- Sin asignar --',
                'value' => $pqrs->assignee_id,
                'class' => 'form-select form-select-sm',
                'disabled' => $isLocked,
                'id' => 'agent-select'
            ]) ?>
            <?= $this->Form->end() ?>
        </section>
        </div>
    </div>
</div>