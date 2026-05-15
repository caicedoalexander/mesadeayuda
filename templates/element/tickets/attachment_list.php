<?php
/**
 * Element: Attachment list para hilos de tickets. Usa el chip compacto
 * del sistema de diseño (.file-chip — components.css sección 13).
 *
 * @var \App\View\AppView $this
 * @var array $attachments Array of Attachment entities
 */

$iconMap = [
    'pdf'   => ['icon' => 'bi-file-earmark-pdf-fill',         'tone' => 'danger'],
    'doc'   => ['icon' => 'bi-file-earmark-word-fill',        'tone' => 'blue'],
    'docx'  => ['icon' => 'bi-file-earmark-word-fill',        'tone' => 'blue'],
    'xls'   => ['icon' => 'bi-file-earmark-excel-fill',       'tone' => 'green'],
    'xlsx'  => ['icon' => 'bi-file-earmark-excel-fill',       'tone' => 'green'],
    'ppt'   => ['icon' => 'bi-file-earmark-ppt-fill',         'tone' => 'orange'],
    'pptx'  => ['icon' => 'bi-file-earmark-ppt-fill',         'tone' => 'orange'],
    'png'   => ['icon' => 'bi-file-earmark-image-fill',       'tone' => 'green'],
    'jpg'   => ['icon' => 'bi-file-earmark-image-fill',       'tone' => 'green'],
    'jpeg'  => ['icon' => 'bi-file-earmark-image-fill',       'tone' => 'green'],
    'gif'   => ['icon' => 'bi-file-earmark-image-fill',       'tone' => 'green'],
    'bmp'   => ['icon' => 'bi-file-earmark-image-fill',       'tone' => 'green'],
    'webp'  => ['icon' => 'bi-file-earmark-image-fill',       'tone' => 'green'],
    'zip'   => ['icon' => 'bi-file-earmark-zip-fill',         'tone' => 'orange'],
    'rar'   => ['icon' => 'bi-file-earmark-zip-fill',         'tone' => 'orange'],
    '7z'    => ['icon' => 'bi-file-earmark-zip-fill',         'tone' => 'orange'],
    'txt'   => ['icon' => 'bi-file-earmark-text-fill',        'tone' => ''],
    'csv'   => ['icon' => 'bi-file-earmark-spreadsheet-fill', 'tone' => 'green'],
];

$formatBytes = static function (int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return number_format($bytes / (1024 * 1024), 1) . ' MB';
};
?>

<?php if (!empty($attachments)): ?>
    <div class="file-chip-list">
        <?php foreach ($attachments as $attachment): ?>
            <?php
            $displayName = $attachment->original_filename ?? $attachment->filename;
            $ext = strtolower(pathinfo($displayName, PATHINFO_EXTENSION));
            $meta = $iconMap[$ext] ?? ['icon' => 'bi-file-earmark', 'tone' => ''];
            ?>
            <?= $this->Html->link(
                '<span class="file-chip-icon ' . h($meta['tone']) . '"><i class="bi ' . h($meta['icon']) . '"></i></span>'
                    . '<span class="file-chip-name">' . h($displayName) . '</span>'
                    . '<span class="file-chip-size mono">' . $formatBytes((int)$attachment->file_size) . '</span>',
                ['controller' => 'Tickets', 'action' => 'downloadAttachment', $attachment->id],
                [
                    'class' => 'file-chip',
                    'escape' => false,
                    'title' => 'Descargar ' . h($displayName),
                ],
            ) ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
