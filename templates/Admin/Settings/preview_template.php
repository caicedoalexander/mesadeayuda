<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Vista Previa - <?= h($template->template_key) ?></title>
    <style>
        :root {
            --admin-green: #00A85E;
            --admin-orange: #CD6A15;
            --gray-50: #F9FAFB;
            --gray-100: #F3F4F6;
            --gray-200: #E5E7EB;
            --gray-600: #4B5563;
            --gray-700: #374151;
            --gray-900: #111827;
            --radius-lg: 12px;
            --radius-md: 8px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, var(--gray-50) 0%, var(--gray-100) 100%);
            padding: 2rem;
            min-height: 100vh;
        }

        .preview-header {
            background: white;
            padding: 1.5rem 2rem;
            border-radius: var(--radius-lg);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
            border-top: 4px solid var(--admin-green);
        }

        .preview-header h4 {
            color: var(--gray-900);
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .preview-header h4 i {
            color: var(--admin-green);
        }

        .preview-header p {
            color: var(--gray-600);
            font-size: 0.9rem;
            margin: 0;
        }

        .preview-container {
            background: white;
            padding: 2rem;
            border-radius: var(--radius-lg);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            max-width: 800px;
            margin: 0 auto;
        }

        .preview-label {
            background: linear-gradient(135deg, #FEF3EC 0%, #FCE7D9 100%);
            color: var(--admin-orange);
            padding: 0.5rem 1rem;
            border-radius: var(--radius-md);
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
            margin-bottom: 1rem;
            border: 1px solid rgba(205, 106, 21, 0.2);
        }

        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }

            .preview-header {
                padding: 1rem;
            }

            .preview-container {
                padding: 1rem;
            }
        }
    </style>
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
