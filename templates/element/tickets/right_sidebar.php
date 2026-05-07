<!-- Right Sidebar - User Info (with independent scroll) -->
<div class="sidebar-right d-flex flex-column p-3">
    <div class="bg-white p-3 text-start shadow-sm" style="border-radius: 8px;">
        <div class="avatar-large text-white rounded-circle d-flex align-items-center justify-content-center fw-bold mb-2"
            style="width: 60px; height: 60px; font-size: 28px; background-color: #CD6A15">
            <?= strtoupper(substr($ticket->requester->name, 0, 2)) ?>
        </div>
        <div class="fw-semibold"><?= h($ticket->requester->name) ?></div>
        <small class="text-muted"><?= h($ticket->requester->email) ?></small>
    </div>

    <div class="sidebar-scroll flex-grow-1 overflow-auto mt-3 bg-white shadow-sm" style="border-radius: 8px;">
        <div class="p-3">
        <section class="mb-3">
            <h3 class="fs-6 mb-3">Información del Usuario</h3>

            <?php if ($ticket->requester->phone): ?>
                <div class="mb-2">
                    <label class="small text-muted fw-semibold mb-1">Teléfono</label>
                    <div class="small"><?= h($ticket->requester->phone) ?></div>
                </div>
            <?php endif; ?>

            <div class="mb-2">
                <label class="small text-muted fw-semibold mb-1">Usuario desde:</label>
                <div class="small"><?= $this->TimeHuman->long($ticket->requester->created) ?></div>
            </div>
        </section>

        <?php if (!empty($ticket->ticket_followers)): ?>
            <section class="mb-3">
                <h3 class="fs-6 fw-semibold mb-3">Seguidores</h3>
                <?php foreach ($ticket->ticket_followers as $follower): ?>
                    <div class="d-flex align-items-center gap-2 py-2">
                        <div class="avatar-sm bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold flex-shrink-0"
                            style="width: 28px; height: 28px; font-size: 11px;">
                            <?= strtoupper(substr($follower->user->name, 0, 1)) ?>
                        </div>
                        <small><?= h($follower->user->name) ?></small>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <section class="mb-3">
            <h3 class="fs-6 fw-semibold mb-3">Historial de cambios</h3>
            <!-- PERFORMANCE FIX: Lazy load history on scroll -->
            <div id="history-container" data-entity-type="ticket" data-entity-id="<?= $ticket->id ?>" data-loaded="false">
                <div id="history-loader" class="text-center py-3">
                    <div class="spinner-border spinner-border-sm text-primary" role="status">
                        <span class="visually-hidden">Cargando...</span>
                    </div>
                    <p class="small text-muted mt-2">Cargando historial...</p>
                </div>
                <div id="history-content" style="display: none;"></div>
            </div>
        </section>
        </div>
    </div>
</div>