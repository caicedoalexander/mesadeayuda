<?php
/**
 * @var \App\View\AppView $this
 * @var array<string, int> $counts
 * @var string $view
 * @var string|null $userRole
 * @var \App\Model\Entity\User|null $currentUser
 * @var string|null $activeWorkspace One of: users, tags, templates, settings
 */
use App\Constants\RoleConstants;

$isAdmin = $userRole === RoleConstants::ROLE_ADMIN;
$isAgent = $userRole === RoleConstants::ROLE_ASESOR_TIC;
$activeWorkspace = $activeWorkspace ?? null;
$wsClass = fn(string $key): string => 'rail-nav-item' . ($activeWorkspace === $key ? ' active' : '');
?>
<aside class="tickets-rail scroll-dark">
    <!-- Brand -->
    <div class="rail-brand">
        <div class="rail-brand-mark">
            <?= $this->Html->image('logos/logo-mesa-ayuda.svg', ['alt' => 'Mesa de Ayuda']) ?>
        </div>
        <div class="rail-brand-text">
            <div class="rail-brand-title">Mesa de <span class="ayuda">Ayuda</span></div>
            <div class="rail-brand-subtitle">Soporte interno</div>
        </div>
    </div>

    <!-- Vistas -->
    <div class="rail-section">
        <div class="rail-section-label">Vistas</div>
        <nav class="rail-nav">
            <?php if ($isAgent): ?>
                <?= $this->Html->link(
                    '<i class="bi bi-person-check"></i><span class="rail-nav-text">Mis tickets</span>'
                        . '<span class="rail-nav-count">' . (int)($counts['mis_tickets'] ?? 0) . '</span>',
                    ['prefix' => false, 'controller' => 'Tickets', 'action' => 'index', '?' => ['view' => 'mis_tickets']],
                    ['class' => 'rail-nav-item' . ($view === 'mis_tickets' ? ' active' : ''), 'escape' => false]
                ) ?>
            <?php endif; ?>

            <?= $this->Html->link(
                '<i class="bi bi-inbox"></i><span class="rail-nav-text">Sin asignar</span>'
                    . '<span class="rail-nav-count">' . (int)$counts['sin_asignar'] . '</span>',
                ['prefix' => false, 'controller' => 'Tickets', 'action' => 'index', '?' => ['view' => 'sin_asignar']],
                ['class' => 'rail-nav-item' . ($view === 'sin_asignar' ? ' active' : ''), 'escape' => false]
            ) ?>

            <?= $this->Html->link(
                '<i class="bi bi-ticket"></i><span class="rail-nav-text">Todos sin resolver</span>'
                    . '<span class="rail-nav-count">' . (int)$counts['todos_sin_resolver'] . '</span>',
                ['prefix' => false, 'controller' => 'Tickets', 'action' => 'index', '?' => ['view' => 'todos_sin_resolver']],
                ['class' => 'rail-nav-item' . ($view === 'todos_sin_resolver' ? ' active' : ''), 'escape' => false]
            ) ?>
        </nav>
    </div>

    <!-- Estados -->
    <div class="rail-section">
        <div class="rail-section-label">Estados</div>
        <nav class="rail-nav">
            <?php
            $states = [
                ['key' => 'nuevos',     'label' => 'Nuevos',     'dot' => 'var(--admin-orange)'],
                ['key' => 'abiertos',   'label' => 'Abiertos',   'dot' => 'var(--danger-color)'],
                ['key' => 'pendientes', 'label' => 'Pendientes', 'dot' => 'var(--admin-blue)'],
                ['key' => 'resueltos',  'label' => 'Resueltos',  'dot' => 'var(--admin-green)'],
            ];
            foreach ($states as $s):
                $isActive = $view === $s['key'];
                echo $this->Html->link(
                    '<span class="rail-state-dot" style="background:' . $s['dot'] . '"></span>'
                        . '<span class="rail-nav-text">' . h($s['label']) . '</span>'
                        . '<span class="rail-nav-count subtle">' . (int)($counts[$s['key']] ?? 0) . '</span>',
                    ['prefix' => false, 'controller' => 'Tickets', 'action' => 'index', '?' => ['view' => $s['key']]],
                    ['class' => 'rail-nav-item' . ($isActive ? ' active' : ''), 'escape' => false]
                );
            endforeach;
            ?>
        </nav>
    </div>

    <?php if ($isAdmin): ?>
    <!-- Workspace (admin only) -->
    <div class="rail-section">
        <div class="rail-section-label">Workspace</div>
        <nav class="rail-nav">
            <?= $this->Html->link(
                '<i class="bi bi-people"></i><span class="rail-nav-text">Usuarios</span>',
                ['prefix' => 'Admin', 'controller' => 'Settings', 'action' => 'users'],
                ['class' => $wsClass('users'), 'escape' => false]
            ) ?>
            <?= $this->Html->link(
                '<i class="bi bi-tags"></i><span class="rail-nav-text">Etiquetas</span>',
                ['prefix' => 'Admin', 'controller' => 'Tags', 'action' => 'index'],
                ['class' => $wsClass('tags'), 'escape' => false]
            ) ?>
            <?= $this->Html->link(
                '<i class="bi bi-envelope"></i><span class="rail-nav-text">Plantillas</span>',
                ['prefix' => 'Admin', 'controller' => 'EmailTemplates', 'action' => 'index'],
                ['class' => $wsClass('templates'), 'escape' => false]
            ) ?>
            <?= $this->Html->link(
                '<i class="bi bi-gear"></i><span class="rail-nav-text">Configuración</span>',
                ['prefix' => 'Admin', 'controller' => 'Settings', 'action' => 'index'],
                ['class' => $wsClass('settings'), 'escape' => false]
            ) ?>
        </nav>
    </div>
    <?php endif; ?>

    <div class="rail-spacer"></div>

    <?php if ($currentUser): ?>
        <!-- User footer -->
        <div class="rail-user">
            <?= $this->User->profileImageTag($currentUser, [
                'width' => '32', 'height' => '32',
                'class' => 'rail-user-avatar',
            ]) ?>
            <div class="rail-user-meta">
                <div class="rail-user-name"><?= h($currentUser->name) ?></div>
                <div class="rail-user-email"><?= h($currentUser->email) ?></div>
            </div>
            <?= $this->Html->link(
                '<i class="bi bi-box-arrow-right"></i>',
                ['prefix' => false, 'controller' => 'Users', 'action' => 'logout'],
                ['class' => 'rail-user-logout', 'escape' => false, 'title' => 'Salir']
            ) ?>
        </div>
    <?php endif; ?>
</aside>
