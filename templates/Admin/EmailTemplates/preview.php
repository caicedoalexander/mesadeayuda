<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Vista Previa - <?= h($template->template_key) ?></title>
    <?= $this->Html->css('admin/preview-template') ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
</head>
<body>
    <div class="preview-header">
        <h4>
            <i class="bi bi-eye"></i>
            Vista Previa: <?= h($template->template_key) ?>
        </h4>
        <p>Esta es una vista previa con datos de ejemplo para verificar el diseño del email</p>
    </div>

    <div class="preview-container">
        <span class="preview-label">
            <i class="bi bi-envelope"></i> Contenido del Email
        </span>
        <?= $previewBody ?>
    </div>
</body>
</html>
