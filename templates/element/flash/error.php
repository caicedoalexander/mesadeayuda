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
<div class="app-toast danger flash-message" role="alert" aria-live="assertive">
    <div class="app-toast-bar"></div>
    <div class="app-toast-body">
        <div class="app-toast-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
        <div class="app-toast-content">
            <div class="app-toast-title">Error</div>
            <div class="app-toast-message"><?= $message ?></div>
        </div>
        <button type="button" class="app-toast-close" aria-label="Cerrar">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
</div>
