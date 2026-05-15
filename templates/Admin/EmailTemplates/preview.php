<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vista previa · <?= h($template->template_key) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <?= $this->Html->css(['styles', 'components']) ?>
</head>
<body style="background: var(--gray-50); padding: 32px 16px; height: auto !important; max-height: none !important; overflow: auto !important;">
    <div style="max-width: 720px; margin: 0 auto;">
        <header class="app-page-header" style="margin-bottom: 18px;">
            <nav class="app-breadcrumb">
                <i class="bi bi-envelope"></i>
                <span>Plantilla</span>
                <i class="bi bi-chevron-right separator"></i>
                <span class="current mono"><?= h($template->template_key) ?></span>
            </nav>
            <div class="app-page-header-row">
                <div class="app-page-header-text">
                    <h1 class="app-page-title" style="font-size: 22px;">Vista previa del email</h1>
                    <div class="app-page-stats">
                        <span class="stat-inline">
                            <span class="dot" style="background: var(--admin-blue);"></span>
                            <span class="label">Datos de ejemplo · diseño real</span>
                        </span>
                    </div>
                </div>
            </div>
        </header>

        <div class="app-card">
            <div class="app-card-header">
                <div class="app-card-header-icon blue"><i class="bi bi-envelope-open"></i></div>
                <div class="app-card-header-text">
                    <h3 class="app-card-header-title">Asunto</h3>
                    <div class="app-card-header-subtitle"><?= h($template->subject) ?></div>
                </div>
            </div>
            <div class="app-card-body" style="padding: 0;">
                <div style="padding: 24px; background: #fff;">
                    <?= $previewBody ?>
                </div>
            </div>
            <div class="app-card-footer">
                <button onclick="window.close()" class="btn-brand-ghost btn-brand-sm">
                    <i class="bi bi-x-lg"></i> Cerrar
                </button>
            </div>
        </div>
    </div>
</body>
</html>
