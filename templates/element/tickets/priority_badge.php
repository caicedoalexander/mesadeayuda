<?php
/**
 * @var \App\View\AppView $this
 * @var string $priority Priority key (e.g. 'baja', 'urgente')
 * @var string $label Human-readable label
 * @var string|null $url Optional URL — wraps badge in <a>
 */
declare(strict_types=1);

$badge = sprintf(
    '<span class="badge badge-priority badge-priority-%s">%s</span>',
    h($priority),
    h($label),
);

if (!empty($url)) {
    echo $this->Html->link($badge, $url, ['escape' => false, 'class' => 'text-decoration-none']);
} else {
    echo $badge;
}
