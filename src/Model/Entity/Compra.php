<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Compra Entity
 *
 * @property int $id
 * @property string $compra_number
 * @property string|null $original_ticket_number
 * @property string $subject
 * @property string $description
 * @property string $status
 * @property string $priority
 * @property int $requester_id
 * @property int|null $assignee_id
 * @property string $channel
 * @property string|null $email_to
 * @property string|null $email_cc
 * @property \Cake\I18n\DateTime|null $sla_due_date
 * @property \Cake\I18n\DateTime|null $created
 * @property \Cake\I18n\DateTime|null $modified
 * @property \Cake\I18n\DateTime|null $resolved_at
 * @property \Cake\I18n\DateTime|null $first_response_at
 * @property \Cake\I18n\DateTime|null $first_response_sla_due
 * @property \Cake\I18n\DateTime|null $resolution_sla_due
 *
 * @property \App\Model\Entity\User $requester
 * @property \App\Model\Entity\User $assignee
 * @property \App\Model\Entity\ComprasComment[] $compras_comments
 * @property \App\Model\Entity\ComprasAttachment[] $compras_attachments
 * @property \App\Model\Entity\ComprasHistory[] $compras_history
 */
class Compra extends Entity
{
    use Trait\EmailRecipientsTrait;

    protected array $_accessible = [
        'compra_number' => false,
        'original_ticket_number' => true,
        'subject' => true,
        'description' => true,
        'status' => false,
        'priority' => true,
        'requester_id' => false,
        'assignee_id' => true,
        'channel' => false,
        'email_to' => true,
        'email_cc' => true,
        'sla_due_date' => false,
        'created' => false,
        'modified' => false,
        'resolved_at' => false,
        'first_response_at' => false,
        'first_response_sla_due' => false,
        'resolution_sla_due' => false,
        'requester' => false,
        'assignee' => false,
        'compras_comments' => false,
        'compras_attachments' => false,
        'compras_history' => false,
    ];

}
