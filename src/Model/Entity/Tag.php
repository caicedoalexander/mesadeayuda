<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Tag Entity
 *
 * @property int $id
 * @property string $name
 * @property string $color
 * @property string|null $description
 * @property \Cake\I18n\DateTime $created
 *
 * @property \App\Model\Entity\TicketTag[] $ticket_tags
 */
class Tag extends Entity
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
        'name' => true,
        'color' => true,
        'description' => true,
        'created' => true,
        'ticket_tags' => true,
    ];
}
