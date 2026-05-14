<?php
/**
 * @var \App\View\AppView $this
 * @var array $params
 * @var string $message
 */
if (!isset($params['escape']) || $params['escape'] !== false) {
    $message = h($message);
}
?>
<div class="app-toast info flash-message" role="status" aria-live="polite">
    <div class="app-toast-bar"></div>
    <div class="app-toast-body">
        <div class="app-toast-icon"><i class="bi bi-info-circle-fill"></i></div>
        <div class="app-toast-content">
            <div class="app-toast-title">Información</div>
            <div class="app-toast-message"><?= $message ?></div>
        </div>
        <button type="button" class="app-toast-close" aria-label="Cerrar">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
</div>
