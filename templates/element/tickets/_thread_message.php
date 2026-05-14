<?php
/**
 * Element: Single message in the ticket thread.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User|null $user
 * @var string $fallbackName
 * @var \Cake\I18n\DateTime $when
 * @var string $role  One of: solicitante, agente, nota-interna
 * @var string $body  HTML body (already sanitized upstream or sanitized here)
 * @var array $attachments
 * @var array $emailTo
 * @var array $emailCc
 * @var string $recipientsId
 */

$role = $role ?? 'agente';
$roleLabels = [
    'solicitante'  => 'Solicitante',
    'agente'       => 'Agente',
    'nota-interna' => 'Nota interna',
];
$user = $user ?? null;
$displayName = $user ? $user->name : ($fallbackName ?? 'Desconocido');
$emailTo = $emailTo ?? [];
$emailCc = $emailCc ?? [];
$attachments = $attachments ?? [];
?>
<article class="thread-message thread-message-<?= h($role) ?>">
    <?php if ($user): ?>
        <?= $this->User->profileImageTag($user, [
            'width'  => '36',
            'height' => '36',
            'class'  => 'thread-message-avatar',
        ]) ?>
    <?php else: ?>
        <span class="agent-avatar thread-message-avatar"
              style="width:36px;height:36px;font-size:14px;background:<?= h($this->User->avatarColor($displayName)) ?>">
            <?= h($this->User->initials($displayName)) ?>
        </span>
    <?php endif; ?>

    <div class="thread-message-body">
        <header class="thread-message-head">
            <span class="thread-message-name"><?= h($displayName) ?></span>
            <span class="thread-message-role thread-role-<?= h($role) ?>">
                <?= h($roleLabels[$role] ?? $role) ?>
            </span>
            <span class="thread-message-spacer"></span>
            <time class="mono thread-message-time" datetime="<?= $when ? $when->format('c') : '' ?>">
                <?= $when ? $this->TimeHuman->long($when) : '' ?>
            </time>
        </header>

        <?php if (!empty($emailTo) || !empty($emailCc)):
            $namesOnly = array_map(fn($r) => h($r['name'] ?? $r['email']), array_merge($emailTo, $emailCc));
            $namesString = implode(', ', $namesOnly);
            $toDetailed = array_map(function ($r) {
                $name = h($r['name'] ?? $r['email']); $email = h($r['email']);
                return $name !== $email ? "{$name} &lt;{$email}&gt;" : $email;
            }, $emailTo);
            $ccDetailed = array_map(function ($r) {
                $name = h($r['name'] ?? $r['email']); $email = h($r['email']);
                return $name !== $email ? "{$name} &lt;{$email}&gt;" : $email;
            }, $emailCc);
        ?>
            <div class="thread-message-recipients">
                <div id="<?= h($recipientsId) ?>-collapsed" class="recipients-collapsed">
                    <span><strong>Para:</strong> <?= $namesString ?></span>
                    <a href="#" data-action="toggle-recipients" data-recipients-id="<?= h($recipientsId) ?>">
                        Mostrar más
                    </a>
                </div>
                <div id="<?= h($recipientsId) ?>-expanded" class="recipients-expanded" style="display: none;">
                    <?php if (!empty($toDetailed)): ?>
                        <div><strong>Para:</strong> <?= implode(', ', $toDetailed) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($ccDetailed)): ?>
                        <div><strong>CC:</strong> <?= implode(', ', $ccDetailed) ?></div>
                    <?php endif; ?>
                    <a href="#" data-action="toggle-recipients" data-recipients-id="<?= h($recipientsId) ?>">
                        Mostrar menos
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <div class="thread-message-card">
            <div class="thread-message-content">
                <?= $this->Sanitize->html($body) ?>
            </div>
            <?php if (!empty($attachments)): ?>
                <div class="thread-message-attachments">
                    <?= $this->element('tickets/attachment_list', ['attachments' => $attachments]) ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</article>
