<?php
/**
 * Element: Empty state reutilizable.
 *
 * @var \App\View\AppView $this
 * @var string $icon      Bootstrap icon name (sin "bi-"). Default: "inbox".
 * @var string $tone      success|neutral|danger|info|warning. Default: "neutral".
 * @var string $title     Título principal.
 * @var string $message   Texto descriptivo opcional. Acepta HTML pre-escapado si $escapeMessage = false.
 * @var bool   $escapeMessage  Default: true.
 * @var string $action    HTML de la CTA (ya renderizado). Opcional.
 * @var bool   $inline    Si true, renderiza la variante de una sola línea (para tablas).
 */

$icon          = $icon          ?? 'inbox';
$tone          = $tone          ?? 'neutral';
$title         = $title         ?? '';
$message       = $message       ?? '';
$escapeMessage = $escapeMessage ?? true;
$action        = $action        ?? '';
$inline        = $inline        ?? false;

$validTones = ['success', 'neutral', 'danger', 'info', 'warning'];
if (!in_array($tone, $validTones, true)) {
    $tone = 'neutral';
}
?>
<?php if ($inline): ?>
    <div class="app-empty-inline">
        <i class="bi bi-<?= h($icon) ?>"></i>
        <span><?= $escapeMessage ? h($title) : $title ?></span>
        <?= $action ?>
    </div>
<?php else: ?>
    <div class="app-empty">
        <div class="app-empty-icon <?= h($tone) ?>">
            <i class="bi bi-<?= h($icon) ?>"></i>
        </div>
        <?php if ($title !== ''): ?>
            <div class="app-empty-title"><?= h($title) ?></div>
        <?php endif; ?>
        <?php if ($message !== ''): ?>
            <div class="app-empty-message"><?= $escapeMessage ? h($message) : $message ?></div>
        <?php endif; ?>
        <?php if ($action !== ''): ?>
            <div class="app-empty-action"><?= $action ?></div>
        <?php endif; ?>
    </div>
<?php endif; ?>
