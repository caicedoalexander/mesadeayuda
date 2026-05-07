<?php
/**
 * Element: Bulk Action Modals para Tickets.
 *
 * @var array $agents Lista de agentes disponibles
 * @var array $tags Lista de tags disponibles
 * @var bool $showTagModal Mostrar modal de tags (default: false)
 */

$agents = $agents ?? [];
$tags = $tags ?? [];
$showTagModal = $showTagModal ?? false;
?>

<!-- Modal: Asignar a agente -->
<div class="modal fade" id="bulkAssignModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-centered-small">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-person-fill-add"></i>
                    Asignar a agente
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>
            <?= $this->Form->create(null, ['url' => ['action' => 'bulkAssign'], 'id' => 'bulkAssignForm']) ?>
            <div class="modal-body">
                <input type="hidden" name="ticket_ids" id="assignTicketIds" value="">
                <p class="text-muted small mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    Se asignarán <strong><span id="assignCount">0</span> ticket(s)</strong>
                </p>
                <div class="mb-0">
                    <label class="form-label">Seleccionar agente</label>
                    <?= $this->Form->select('agent_id', $agents, [
                        'empty' => 'Seleccionar...',
                        'class' => 'form-select',
                        'required' => true
                    ]) ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-check-lg me-1"></i>Asignar
                </button>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>

<!-- Modal: Cambiar prioridad -->
<div class="modal fade" id="bulkPriorityModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-centered-small">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle"></i>
                    Cambiar prioridad
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>
            <?= $this->Form->create(null, ['url' => ['action' => 'bulkChangePriority'], 'id' => 'bulkPriorityForm']) ?>
            <div class="modal-body">
                <input type="hidden" name="ticket_ids" id="priorityTicketIds" value="">
                <p class="text-muted small mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    Se actualizarán <strong><span id="priorityCount">0</span> ticket(s)</strong>
                </p>
                <div class="mb-0">
                    <label class="form-label">Nueva prioridad</label>
                    <?= $this->Form->select('priority', [
                        'baja' => 'Baja',
                        'media' => 'Media',
                        'alta' => 'Alta',
                        'urgente' => 'Urgente'
                    ], [
                        'empty' => 'Seleccionar...',
                        'class' => 'form-select',
                        'required' => true
                    ]) ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-check-lg me-1"></i>Cambiar
                </button>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>

<?php if ($showTagModal): ?>
<!-- Modal: Agregar etiqueta -->
<div class="modal fade" id="bulkTagModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-centered-small">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-tag"></i>
                    Agregar etiqueta
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>
            <?= $this->Form->create(null, ['url' => ['action' => 'bulkAddTag'], 'id' => 'bulkTagForm']) ?>
            <div class="modal-body">
                <input type="hidden" name="ticket_ids" id="tagTicketIds" value="">
                <p class="text-muted small mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    Se etiquetarán <strong><span id="tagCount">0</span> ticket(s)</strong>
                </p>
                <div class="mb-0">
                    <label class="form-label">Seleccionar etiqueta</label>
                    <select name="tag_id" class="form-select" required>
                        <option value="">Seleccionar...</option>
                        <?php if (!empty($tags)): ?>
                            <?php foreach ($tags as $tag): ?>
                                <option value="<?= $tag->id ?>"><?= h($tag->name) ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-check-lg me-1"></i>Agregar
                </button>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal: Confirmar eliminación -->
<div class="modal fade" id="bulkDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-centered-small">
        <div class="modal-content">
            <div class="modal-header bg-danger-gradient">
                <h5 class="modal-title">
                    <i class="bi bi-trash"></i>
                    Confirmar eliminación
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>
            <?= $this->Form->create(null, ['url' => ['action' => 'bulkDelete'], 'id' => 'bulkDeleteForm']) ?>
            <div class="modal-body">
                <input type="hidden" name="ticket_ids" id="deleteTicketIds" value="">
                <div class="alert alert-danger mb-3">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <strong>¿Está seguro?</strong>
                </div>
                <p class="mb-2">
                    Está a punto de eliminar <strong><span id="deleteCount">0</span> ticket(s)</strong>.
                </p>
                <p class="text-muted small mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    Esta acción no se puede deshacer.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-sm btn-danger">
                    <i class="bi bi-trash me-1"></i>Eliminar
                </button>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>
