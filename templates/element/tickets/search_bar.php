<?php
/**
 * @var \App\View\AppView $this
 * @var string $searchValue
 * @var string $placeholder
 * @var string $view
 */

$searchValue = $searchValue ?? '';
$placeholder = $placeholder ?? 'Buscar...';
$view = $view ?? '';
?>

<?= $this->Form->create(null, [
    'type' => 'get',
    'class' => 'd-flex align-items-center flex-grow-1',
    'id' => 'searchForm',
]) ?>
<?= $this->Form->hidden('view', ['value' => $view]) ?>

<div class="search-wrapper focus-ring">
    <i class="bi bi-search search-icon-inner"></i>
    <?= $this->Form->text('search', [
        'class'        => 'form-control search-input-clean',
        'placeholder'  => $placeholder,
        'value'        => $searchValue,
        'id'           => 'searchInput',
        'autoComplete' => 'off',
    ]) ?>
</div>

<?php if (!empty($searchValue)): ?>
    <?= $this->Html->link(
        '<i class="bi bi-x-lg"></i>',
        ['action' => 'index', '?' => ['view' => $view]],
        ['class' => 'btn-clear-search', 'escape' => false, 'title' => 'Limpiar búsqueda']
    ) ?>
<?php endif; ?>

<?= $this->Form->end() ?>
