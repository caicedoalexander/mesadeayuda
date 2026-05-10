<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\TicketConstants;
use Cake\ORM\Entity;

/**
 * Ticket Entity
 *
 * @property int $id
 * @property string $ticket_number
 * @property string|null $gmail_message_id
 * @property string|null $gmail_thread_id
 * @property string|null $email_to
 * @property string|null $email_cc
 * @property string $subject
 * @property string|null $description
 * @property string $status
 * @property string $priority
 * @property int $requester_id
 * @property int|null $assignee_id
 * @property string $channel
 * @property string|null $source_email
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 * @property \Cake\I18n\DateTime|null $resolved_at
 *
 * @property \App\Model\Entity\User $requester
 * @property \App\Model\Entity\User $assignee
 * @property \App\Model\Entity\Attachment[] $attachments
 * @property \App\Model\Entity\TicketComment[] $ticket_comments
 * @property \App\Model\Entity\TicketFollower[] $ticket_followers
 * @property \App\Model\Entity\TicketTag[] $ticket_tags
 */
class Ticket extends Entity
{
    use Trait\EmailRecipientsTrait;

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
        'ticket_number' => false,
        'gmail_message_id' => false,
        'gmail_thread_id' => false,
        'email_to' => true,
        'email_cc' => true,
        'subject' => true,
        'description' => true,
        'status' => false,
        'priority' => true,
        'requester_id' => false,
        'assignee_id' => false,
        'channel' => false,
        'source_email' => false,
        'created' => false,
        'modified' => false,
        'resolved_at' => false,
        'requester' => false,
        'assignee' => false,
        'attachments' => false,
        'ticket_comments' => false,
        'ticket_followers' => false,
        'ticket_tags' => false,
    ];

    // region: Domain predicates — status

    /**
     * @return bool
     */
    public function isResolved(): bool
    {
        return $this->status === TicketConstants::STATUS_RESUELTO;
    }

    /**
     * @return bool
     */
    public function isOpen(): bool
    {
        return in_array($this->status, TicketConstants::OPEN_STATUSES, true);
    }

    /**
     * Domain status check for the "nuevo" lifecycle state.
     *
     * Named `isStatusNew` (not `isNew`) to avoid shadowing
     * `Cake\Datasource\EntityInterface::isNew()`, which returns the
     * persistence flag for unsaved records.
     *
     * @return bool
     */
    public function isStatusNew(): bool
    {
        return $this->status === TicketConstants::STATUS_NUEVO;
    }

    /**
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === TicketConstants::STATUS_PENDIENTE;
    }

    /**
     * A locked ticket cannot be mutated by normal flows (assignment,
     * status change, comments from non-staff).
     *
     * @return bool
     */
    public function isLocked(): bool
    {
        return $this->isResolved();
    }

    // endregion

    // region: Domain predicates — relationships

    /**
     * @return bool
     */
    public function hasAssignee(): bool
    {
        return $this->assignee_id !== null;
    }

    /**
     * @param int $userId User id to compare against requester
     * @return bool
     */
    public function belongsTo(int $userId): bool
    {
        return $this->requester_id === $userId;
    }

    /**
     * @param int $userId User id to compare against assignee
     * @return bool
     */
    public function isAssignedTo(int $userId): bool
    {
        return $this->assignee_id === $userId;
    }

    /**
     * @return bool
     */
    public function wasCreatedFromEmail(): bool
    {
        return $this->gmail_message_id !== null;
    }

    // endregion

    // region: Domain transitions

    /**
     * Legal status transitions per the ticket state machine.
     *
     * - nuevo     -> abierto, pendiente, resuelto
     * - abierto   -> pendiente, resuelto, nuevo (revertir)
     * - pendiente -> abierto, resuelto
     * - resuelto  -> abierto (reapertura)
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        TicketConstants::STATUS_NUEVO => [
            TicketConstants::STATUS_ABIERTO,
            TicketConstants::STATUS_PENDIENTE,
            TicketConstants::STATUS_RESUELTO,
        ],
        TicketConstants::STATUS_ABIERTO => [
            TicketConstants::STATUS_PENDIENTE,
            TicketConstants::STATUS_RESUELTO,
            TicketConstants::STATUS_NUEVO,
        ],
        TicketConstants::STATUS_PENDIENTE => [
            TicketConstants::STATUS_ABIERTO,
            TicketConstants::STATUS_RESUELTO,
        ],
        TicketConstants::STATUS_RESUELTO => [
            TicketConstants::STATUS_ABIERTO,
        ],
    ];

    /**
     * @param string $newStatus Target status
     * @return bool
     */
    public function canTransitionTo(string $newStatus): bool
    {
        if (!in_array($newStatus, TicketConstants::STATUSES, true)) {
            return false;
        }
        if ($this->status === $newStatus) {
            return false;
        }
        $allowed = self::TRANSITIONS[$this->status] ?? [];

        return in_array($newStatus, $allowed, true);
    }

    /**
     * @param \App\Model\Entity\User $user Candidate assignee
     * @return bool
     */
    public function canBeAssignedTo(User $user): bool
    {
        if ($this->isLocked()) {
            return false;
        }
        if (!$user->isStaff()) {
            return false;
        }
        if (!$user->is_active) {
            return false;
        }

        return true;
    }

    // endregion
}
