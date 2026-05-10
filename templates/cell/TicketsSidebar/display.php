<?php
/**
 * @var \App\View\AppView $this
 * @var array<string, int> $counts
 * @var string $view
 * @var string|null $userRole
 * @var \App\Model\Entity\User|null $currentUser
 */
?>
<div class="text-white overflow-auto sidebar-scroll" style="background-color: #273244; min-width: 300px; border-radius: 0 8px 8px 0;">
    <div class="p-4">
    <?php if ($currentUser): ?>
        <div class="text-center mb-3">
            <?= $this->User->profileImageTag($currentUser, ['width' => '80', 'height' => '80', 'class' => 'rounded-circle object-fit-cover shadow']) ?>
            <div class="mt-2">
                <strong><?= h($currentUser->name) ?></strong>
            </div>
        </div>
    <?php endif; ?>
    <h6 class="mb-2 fs-6">Vistas</h6>
    <ul class="mt-2 list-group">
        <?php if ($userRole !== 'admin'): ?>
            <li class="list-group-item">
                <?= $this->Html->link(
                    'Mis Tickets<span class="count">' . $counts['mis_tickets'] . '</span>',
                    ['controller' => 'Tickets', 'action' => 'index', '?' => ['view' => 'mis_tickets']],
                    ['class' => $view === 'mis_tickets' ? 'active' : '', 'escape' => false]
                ) ?>
            </li>
        <?php endif; ?>

        <li class="list-group-item">
            <?= $this->Html->link(
                'Tickets sin asignar <span class="count">' . $counts['sin_asignar'] . '</span>',
                ['action' => 'index', '?' => ['view' => 'sin_asignar']],
                ['class' => $view === 'sin_asignar' ? 'active' : '', 'escape' => false]
            ) ?>
        </li>
        <li class="list-group-item">
            <?= $this->Html->link(
                'Todos sin resolver <span class="count">' . $counts['todos_sin_resolver'] . '</span>',
                ['action' => 'index', '?' => ['view' => 'todos_sin_resolver']],
                ['class' => $view === 'todos_sin_resolver' ? 'active' : '', 'escape' => false]
            ) ?>
        </li>
    </ul>
    
    <h6 class="mt-4 mb-2 fs-6">Estados</h6>
    <ul class="mt-2 list-group">
        <li class="list-group-item">
            <?= $this->Html->link(
                '<span class="badge" style="background-color: #ffc107; color: white;">●</span>Nuevos <span class="count">' . $counts['nuevos'] . '</span>',
                ['action' => 'index', '?' => ['view' => 'nuevos']],
                ['class' => $view === 'nuevos' ? 'active' : '', 'escape' => false]
            ) ?>
        </li>
        <li class="list-group-item">
            <?= $this->Html->link(
                '<span class="badge" style="background-color: #dc3545; color: white;">●</span>Abiertos <span class="count">' . $counts['abiertos'] . '</span>',
                ['action' => 'index', '?' => ['view' => 'abiertos']],
                ['class' => $view === 'abiertos' ? 'active' : '', 'escape' => false]
            ) ?>
        </li>
        <li class="list-group-item">
            <?= $this->Html->link(
                '<span class="badge" style="background-color: #0d6efd; color: white;">●</span>Pendientes <span class="count">' . $counts['pendientes'] . '</span>',
                ['action' => 'index', '?' => ['view' => 'pendientes']],
                ['class' => $view === 'pendientes' ? 'active' : '', 'escape' => false]
            ) ?>
        </li>

        <li class="list-group-item">
            <?= $this->Html->link(
                '<span class="badge" style="background-color: #198754; color: white;">●</span>Resueltos <span class="count">' . $counts['resueltos'] . '</span>',
                ['action' => 'index', '?' => ['view' => 'resueltos']],
                ['class' => $view === 'resueltos' ? 'active' : '', 'escape' => false]
            ) ?>
        </li>
    </ul>
    </div>
</div>