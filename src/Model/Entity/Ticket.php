<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\TicketConstants;
use App\Service\Exception\InvalidStatusTransitionException;
use Cake\ORM\Entity;

/**
 * Ticket Entity
 *
 * @property int $id
 * @property string|null $gmail_message_id
 * @property string|null $gmail_thread_id
 * @property string|null $whatsapp_message_id
 * @property string|null $email_to
 * @property string|null $email_cc
 * @property string|null $rfc_message_id
 * @property string|null $in_reply_to
 * @property string|null $references_header
 * @property string $subject
 * @property string|null $description
 * @property string $status
 * @property string $priority
 * @property int $requester_id
 * @property int|null $assignee_id
 * @property string $channel
 * @property string|null $source_email
 * @property string|null $source_phone
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
        'gmail_message_id' => false,
        'gmail_thread_id' => false,
        'whatsapp_message_id' => false,
        'email_to' => true,
        'email_cc' => true,
        'rfc_message_id' => false,
        'in_reply_to' => false,
        'references_header' => false,
        'subject' => true,
        'description' => true,
        'status' => false,
        'priority' => true,
        'requester_id' => false,
        'assignee_id' => false,
        'channel' => false,
        'source_email' => false,
        'source_phone' => false,
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
     * Apply a status transition, asserting it is legal first.
     *
     * Centralises status mutation so services can't bypass the state machine
     * with a raw $entity->status = $foo assignment.
     *
     * @param string $newStatus Target status
     * @throws \App\Service\Exception\InvalidStatusTransitionException If the transition is not allowed
     */
    public function transitionTo(string $newStatus): void
    {
        if ($this->status === $newStatus) {
            return;
        }
        if (!$this->canTransitionTo($newStatus)) {
            throw InvalidStatusTransitionException::for($this->status, $newStatus);
        }
        $this->set('status', $newStatus);
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

    // region: Domain factories

    /**
     * Construye un Ticket nuevo a partir de un email ingestado (Gmail / WA bot).
     *
     * Encapsula la decisión de qué status y priority iniciales aplicar, y el
     * fallback de subject vacío. Bypasea el cierre de $_accessible — legítimo
     * porque es la entidad construyéndose a sí misma; el cierre sigue
     * protegiendo mass-assign desde controllers / marshalling.
     *
     * @param int $requesterId Resolved requester user id
     * @param string $subject Email subject (trim ya aplicado por el caller); usa '(Sin asunto)' si vacío
     * @param string $sanitizedDescription Body ya pasado por HtmlSanitizerTrait
     * @param string $channel TicketConstants::CHANNEL_EMAIL | CHANNEL_WHATSAPP
     * @param string $sourceEmail From-address del remitente
     * @param string|null $gmailMessageId Gmail message id si vino por Gmail API
     * @param string|null $gmailThreadId Gmail thread id si vino por Gmail API
     * @param mixed $emailTo Recipients array (To); se persiste tal cual
     * @param mixed $emailCc Recipients array (Cc); se persiste tal cual
     * @param string|null $rfcMessageId Header Message-ID normalizado (M-4)
     * @param string|null $inReplyTo Header In-Reply-To normalizado (M-4)
     * @param string|null $referencesHeader Header References crudo (M-4)
     */
    public static function fromEmailIngest(
        int $requesterId,
        string $subject,
        string $sanitizedDescription,
        string $channel,
        string $sourceEmail,
        ?string $gmailMessageId = null,
        ?string $gmailThreadId = null,
        mixed $emailTo = null,
        mixed $emailCc = null,
        ?string $rfcMessageId = null,
        ?string $inReplyTo = null,
        ?string $referencesHeader = null,
    ): self {
        $ticket = new self();
        $ticket->gmail_message_id = $gmailMessageId;
        $ticket->gmail_thread_id = $gmailThreadId;
        $ticket->subject = $subject !== '' ? $subject : '(Sin asunto)';
        $ticket->description = $sanitizedDescription;
        $ticket->status = TicketConstants::STATUS_NUEVO;
        $ticket->priority = TicketConstants::PRIORITY_MEDIA;
        $ticket->requester_id = $requesterId;
        $ticket->channel = $channel;
        $ticket->source_email = $sourceEmail;
        $ticket->email_to = $emailTo;
        $ticket->email_cc = $emailCc;
        $ticket->rfc_message_id = $rfcMessageId;
        $ticket->in_reply_to = $inReplyTo;
        $ticket->references_header = $referencesHeader;

        return $ticket;
    }

    /**
     * Factory para tickets ingresados desde el bot WhatsApp
     * (POST /webhooks/whatsapp/import).
     *
     * Paralelo a fromEmailIngest: defaults de status/priority y fallback de
     * asunto vacío viven aquí, no en la capa IO.
     *
     * @param int $requesterId Resolved or just-created requester user id
     * @param string $subject Subject already trimmed by caller; (Sin asunto) si vacío
     * @param string $sanitizedDescription Body ya pasado por HtmlSanitizerTrait
     * @param string $sourcePhone E.164 phone number from the WhatsApp Cloud API payload
     * @param string $whatsappMessageId Idempotency key (wamid)
     */
    public static function fromWhatsappIngest(
        int $requesterId,
        string $subject,
        string $sanitizedDescription,
        string $sourcePhone,
        string $whatsappMessageId,
    ): self {
        $ticket = new self();
        $ticket->subject = $subject !== '' ? $subject : '(Sin asunto)';
        $ticket->description = $sanitizedDescription;
        $ticket->status = TicketConstants::STATUS_NUEVO;
        $ticket->priority = TicketConstants::PRIORITY_MEDIA;
        $ticket->requester_id = $requesterId;
        $ticket->channel = TicketConstants::CHANNEL_WHATSAPP;
        $ticket->source_phone = $sourcePhone;
        $ticket->whatsapp_message_id = $whatsappMessageId;

        return $ticket;
    }

    // endregion
}
