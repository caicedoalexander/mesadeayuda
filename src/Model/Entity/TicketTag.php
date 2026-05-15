<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * TicketTag Entity
 *
 * @property int $ticket_id
 * @property int $tag_id
 *
 * @property \App\Model\Entity\Ticket $ticket
 * @property \App\Model\Entity\Tag $tag
 */
class TicketTag extends Entity
{
    /**
     * Fields that can be mass assigned using newEntity() or patchEntity().
     *
     * Note that when '*' is set to true, this allows all unspecified fields to
     * be mass assigned. For security purposes, it is advised to set '*' to false
     * (or remove it), and explicitly make individual fields accessible as needed.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'ticket_id' => true,
        'tag_id' => true,
        'ticket' => true,
        'tag' => true,
    ];
}
