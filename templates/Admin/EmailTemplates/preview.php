<?php
/**
 * @var \App\View\AppView $this
 * @var string $subject
 * @var string $bodyHtml
 * @var \App\Notification\Email\Admin\TemplateDescriptor $descriptor
 */
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Preview — <?= h($descriptor->key) ?></title>
<style>
  body {
    margin: 0;
    background: #f0eee9;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
    color: #111827;
  }
  .preview-meta {
    max-width: 720px;
    margin: 24px auto 12px;
    padding: 12px 16px;
    background: #fff;
    border: 1px solid #E5E7EB;
    border-radius: 8px;
    font-size: 12px;
    color: #4B5563;
  }
  .preview-meta .label { color: #6B7280; margin-right: 6px; }
  .preview-meta .subject { color: #111827; font-weight: 600; }
  .preview-canvas { padding: 0 0 32px; }
</style>
</head>
<body>

<div class="preview-meta">
  <span class="label">Plantilla:</span>
  <span class="subject mono"><?= h($descriptor->key) ?></span>
  &nbsp;·&nbsp;
  <span class="label">Asunto:</span>
  <span class="subject"><?= h($subject) ?></span>
</div>

<div class="preview-canvas">
  <?= $bodyHtml /* trusted: rendered by our own template */ ?>
</div>

</body>
</html>
