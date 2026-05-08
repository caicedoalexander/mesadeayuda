<?php
/**
 * @var \App\View\AppView $this
 * @var string $status Status key (e.g. 'nuevo', 'abierto')
 * @var string $label Human-readable label
 * @var string|null $url Optional URL — wraps badge in <a>
 */
declare(strict_types=1);

$badge = sprintf(
    '<span class="badge badge-status badge-status-%s">%s</span>',
    h($status),
    h($label),
);

if (!empty($url)) {
    echo $this->Html->link($badge, $url, ['escape' => false, 'class' => 'text-decoration-none']);
} else {
    echo $badge;
}
