<!DOCTYPE html>
<html lang="es">
<?= $this->element('head') ?>
<body>
    <nav class="top-navbar pqrs-navbar" style="max-height: 55px; z-index: 1000;">
        <div class="d-flex justify-content-between align-items-center px-3 w-100">
            <img src="<?= $this->Url->image('logos/servicioalcliente.svg') ?>">
            <div class="nav-menu d-flex align-items-center gap-3 py-3">
                <?= $this->Html->link(
                    '<i class="bi bi-bar-chart"></i> Estadísticas',
                    ['prefix' => false, 'controller' => 'Pqrs', 'action' => 'statistics'],
                    ['escape' => false]
                ) ?>
                
                <?= $this->Html->link(
                    '<i class="bi bi-chat-square-text"></i> PQRS',
                    ['prefix' => false, 'controller' => 'Pqrs', 'action' => 'index'],
                    ['escape' => false]
                ) ?>

                <?= $this->Html->link(
                    '<i class="bi bi-plus-circle"></i> Formulario Público',
                    ['_name' => 'pqrs_public_form'],
                    ['escape' => false, 'target' => '_blank']
                ) ?>

                <?= $this->Html->link(
                    '<i class="bi bi-person"></i> Mi Perfil',
                    ['prefix' => 'Admin', 'controller' => 'Settings', 'action' => 'editUser', $currentUser->id],
                    ['escape' => false]
                ) ?>

            </div>
            <div class="nav-user d-flex align-items-center gap-2">
                <?= $this->Html->link(
                    '<i class="bi bi-box-arrow-right"></i> Salir',
                    ['prefix' => false, 'controller' => 'Users', 'action' => 'logout'],
                    ['class' => 'btn-logout', 'escape' => false]
                ) ?>
            </div>
        </div>
    </nav>

    <div class="overflow-auto sidebar-scroll" style="max-height: calc(100vh - 55px);">
        <?= $this->Flash->render() ?>
        <!-- Loading Spinner -->
        <?= $this->element('loading_spinner') ?>
        <div class="d-flex" style="height: calc(100vh - 55px);">
            <?= $this->fetch('content') ?>
        </div>
    </div>

    <!-- Welcome Modal for First Time Users -->
    <?php if (isset($showWelcome) && $showWelcome): ?>
    <div class="modal fade" id="welcomeModal" tabindex="-1" aria-labelledby="welcomeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title" id="welcomeModalLabel">
                        <i class="bi bi-chat-square-text-fill"></i>
                        Bienvenido al Sistema PQRS
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <h6>¿Qué es PQRS?</h6>
                    <ul class="mb-3">
                        <li><strong>P</strong>eticiones - Solicitudes de información o servicios</li>
                        <li><strong>Q</strong>uejas - Manifestación de insatisfacción</li>
                        <li><strong>R</strong>eclamos - Exigencia de un derecho o corrección</li>
                        <li><strong>S</strong>ugerencias - Propuestas de mejora</li>
                    </ul>

                    <h6>Características del Sistema:</h6>
                    <ul>
                        <li><i class="bi bi-check-circle text-success"></i> Gestión completa de PQRS</li>
                        <li><i class="bi bi-check-circle text-success"></i> Notificaciones automáticas (Email + WhatsApp)</li>
                        <li><i class="bi bi-check-circle text-success"></i> Formulario público sin autenticación</li>
                        <li><i class="bi bi-check-circle text-success"></i> Seguimiento con historial completo</li>
                        <li><i class="bi bi-check-circle text-success"></i> Comentarios públicos e internos</li>
                    </ul>

                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle"></i>
                        <strong>Nota:</strong> Como usuario de Servicio al Cliente, solo tienes acceso al módulo de PQRS.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">
                        <i class="bi bi-check-lg"></i> Entendido
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Show welcome modal on first visit
        document.addEventListener('DOMContentLoaded', function() {
            const welcomeModal = new bootstrap.Modal(document.getElementById('welcomeModal'));
            welcomeModal.show();
        });
    </script>
    <?php endif; ?>
</body>
</html>
