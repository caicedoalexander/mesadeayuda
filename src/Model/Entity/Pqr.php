<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Pqr Entity
 *
 * @property int $id
 * @property string $pqrs_number
 * @property string $type
 * @property string $subject
 * @property string $description
 * @property string $status
 * @property string $priority
 * @property string $requester_name
 * @property string $requester_email
 * @property string|null $requester_phone
 * @property string|null $requester_id_number
 * @property string|null $requester_address
 * @property string|null $requester_city
 * @property string $channel
 * @property int|null $assignee_id
 * @property string|null $source_url
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 * @property \Cake\I18n\DateTime|null $resolved_at
 * @property \Cake\I18n\DateTime|null $first_response_at
 * @property \Cake\I18n\DateTime|null $closed_at
 * @property \Cake\I18n\DateTime|null $first_response_sla_due
 * @property \Cake\I18n\DateTime|null $resolution_sla_due
 *
 * @property \App\Model\Entity\User $assignee
 * @property \App\Model\Entity\PqrsComment[] $pqrs_comments
 * @property \App\Model\Entity\PqrsAttachment[] $pqrs_attachments
 * @property \App\Model\Entity\PqrsHistory[] $pqrs_history
 */
class Pqr extends Entity
{
    /**
     * Fields that can be mass assigned using newEntity() or patchEntity().
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'pqrs_number' => false,
        'type' => true,
        'subject' => true,
        'description' => true,
        'status' => false,
        'priority' => true,
        'requester_name' => true,
        'requester_email' => true,
        'requester_phone' => true,
        'requester_id_number' => true,
        'requester_address' => true,
        'requester_city' => true,
        'channel' => false,
        'assignee_id' => true,
        'source_url' => true,
        'ip_address' => false,
        'user_agent' => false,
        'created' => false,
        'modified' => false,
        'resolved_at' => false,
        'first_response_at' => false,
        'closed_at' => false,
        'first_response_sla_due' => false,
        'resolution_sla_due' => false,
        'assignee' => false,
        'pqrs_comments' => false,
        'pqrs_attachments' => false,
        'pqrs_history' => false,
    ];

    /**
     * Fields that are excluded from JSON versions of the entity.
     *
     * @var list<string>
     */
    protected array $_hidden = [
        'ip_address',
        'user_agent',
    ];
}
