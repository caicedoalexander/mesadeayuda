<?php
/**
 * @var \App\View\AppView $this
 * @var string $kind  Badge family — 'status' or 'priority'. Drives CSS class prefix.
 * @var string $value Key value (e.g. 'nuevo', 'urgente'). Used as CSS modifier.
 * @var string $label Human-readable label rendered inside the badge.
 * @var string|null $url Optional URL — wraps badge in <a>.
 */
declare(strict_types=1);

$badge = sprintf(
    '<span class="badge badge-%1$s badge-%1$s-%2$s">%3$s</span>',
    h($kind),
    h($value),
    h($label),
);

if (!empty($url)) {
    echo $this->Html->link($badge, $url, ['escape' => false, 'class' => 'text-decoration-none']);
} else {
    echo $badge;
}
