<?php
/**
 * Pagination element — renders only the page links.
 * The count summary ("Mostrando X–Y de N") is rendered separately by the
 * table footer in templates/Tickets/index.php so it doesn't duplicate here.
 *
 * Estilos en webroot/css/components.css, sección 15 · PAGINACIÓN.
 */
?>
<?php if ($this->Paginator->hasPrev() || $this->Paginator->hasNext()): ?>
<nav class="pagination" aria-label="Paginación">
    <ul class="app-pagination">
        <?= $this->Paginator->prev('<i class="bi bi-chevron-left"></i>', ['escape' => false]) ?>
        <?= $this->Paginator->numbers([
            'modulus'  => 2,
            'first'    => 1,
            'last'     => 1,
            'ellipsis' => '<li class="ellipsis"><span>…</span></li>',
        ]) ?>
        <?= $this->Paginator->next('<i class="bi bi-chevron-right"></i>', ['escape' => false]) ?>
    </ul>
</nav>
<?php endif; ?>
