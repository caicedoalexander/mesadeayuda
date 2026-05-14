<?php
/**
 * Pagination element — renders only the page links.
 * The count summary ("Mostrando X–Y de N") is rendered separately by the
 * table footer in templates/Tickets/index.php so it doesn't duplicate here.
 */
?>
<?php if ($this->Paginator->hasPrev() || $this->Paginator->hasNext()): ?>
<div class="pagination">
    <ul class="pagination-links">
        <?= $this->Paginator->prev('<i class="bi bi-chevron-left"></i>', ['escape' => false]) ?>
        <?= $this->Paginator->numbers(['modulus' => 4, 'first' => 1, 'last' => 1]) ?>
        <?= $this->Paginator->next('<i class="bi bi-chevron-right"></i>', ['escape' => false]) ?>
    </ul>
</div>
<?php endif; ?>
