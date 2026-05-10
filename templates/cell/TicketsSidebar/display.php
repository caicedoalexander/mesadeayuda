<?php
/**
 * @var \App\View\AppView $this
 * @var array<string, int> $counts
 * @var string $view
 * @var string|null $userRole
 * @var \App\Model\Entity\User|null $currentUser
 */
?>
<aside class="qo-sidebar">
    <div class="qo-sidebar__inner">
        <?php if ($currentUser): ?>
            <div class="qo-sidebar__user">
                <?= $this->User->profileImageTag($currentUser, ['width' => '36', 'height' => '36', 'class' => 'qo-avatar']) ?>
                <div>
                    <div class="qo-sidebar__user-name"><?= h($currentUser->name) ?></div>
                    <?php if ($userRole): ?>
                        <div class="qo-sidebar__user-role"><?= h($userRole) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <h6 class="qo-sidebar__label">Vistas</h6>
        <ul class="list-group">
            <?php if ($userRole !== 'admin'): ?>
                <li class="list-group-item">
                    <?= $this->Html->link(
                        '<span>Mis Tickets</span><span class="count">' . $counts['mis_tickets'] . '</span>',
                        ['controller' => 'Tickets', 'action' => 'index', '?' => ['view' => 'mis_tickets']],
                        ['class' => $view === 'mis_tickets' ? 'active' : '', 'escape' => false]
                    ) ?>
                </li>
            <?php endif; ?>

            <li class="list-group-item">
                <?= $this->Html->link(
                    '<span>Sin asignar</span><span class="count">' . $counts['sin_asignar'] . '</span>',
                    ['action' => 'index', '?' => ['view' => 'sin_asignar']],
                    ['class' => $view === 'sin_asignar' ? 'active' : '', 'escape' => false]
                ) ?>
            </li>
            <li class="list-group-item">
                <?= $this->Html->link(
                    '<span>Todos sin resolver</span><span class="count">' . $counts['todos_sin_resolver'] . '</span>',
                    ['action' => 'index', '?' => ['view' => 'todos_sin_resolver']],
                    ['class' => $view === 'todos_sin_resolver' ? 'active' : '', 'escape' => false]
                ) ?>
            </li>
        </ul>

        <h6 class="qo-sidebar__label">Estados</h6>
        <ul class="list-group">
            <li class="list-group-item">
                <?= $this->Html->link(
                    '<span><span class="qo-status-dot qo-status-dot--new"></span>Nuevos</span><span class="count">' . $counts['nuevos'] . '</span>',
                    ['action' => 'index', '?' => ['view' => 'nuevos']],
                    ['class' => $view === 'nuevos' ? 'active' : '', 'escape' => false]
                ) ?>
            </li>
            <li class="list-group-item">
                <?= $this->Html->link(
                    '<span><span class="qo-status-dot qo-status-dot--open"></span>Abiertos</span><span class="count">' . $counts['abiertos'] . '</span>',
                    ['action' => 'index', '?' => ['view' => 'abiertos']],
                    ['class' => $view === 'abiertos' ? 'active' : '', 'escape' => false]
                ) ?>
            </li>
            <li class="list-group-item">
                <?= $this->Html->link(
                    '<span><span class="qo-status-dot qo-status-dot--pending"></span>Pendientes</span><span class="count">' . $counts['pendientes'] . '</span>',
                    ['action' => 'index', '?' => ['view' => 'pendientes']],
                    ['class' => $view === 'pendientes' ? 'active' : '', 'escape' => false]
                ) ?>
            </li>
            <li class="list-group-item">
                <?= $this->Html->link(
                    '<span><span class="qo-status-dot qo-status-dot--resolved"></span>Resueltos</span><span class="count">' . $counts['resueltos'] . '</span>',
                    ['action' => 'index', '?' => ['view' => 'resueltos']],
                    ['class' => $view === 'resueltos' ? 'active' : '', 'escape' => false]
                ) ?>
            </li>
        </ul>
    </div>
</aside>
