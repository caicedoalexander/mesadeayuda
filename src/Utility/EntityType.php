<?php
declare(strict_types=1);

namespace App\Utility;

/**
 * EntityType Enum
 *
 * Centralizes entity type mappings used across the system.
 * Replaces 15+ match/switch statements that resolve table names,
 * foreign keys, and other entity-specific identifiers from string types.
 */
enum EntityType: string
{
    case TICKET = 'ticket';
    case PQRS = 'pqrs';
    case COMPRA = 'compra';

    /**
     * Create from ORM table source name (e.g., 'Tickets' → TICKET)
     */
    public static function fromSource(string $source): self
    {
        return match ($source) {
            'Tickets' => self::TICKET,
            'Pqrs' => self::PQRS,
            'Compras' => self::COMPRA,
            default => throw new \InvalidArgumentException("Unknown source: {$source}"),
        };
    }

    /**
     * Main entity table name (e.g., 'Tickets')
     */
    public function tableName(): string
    {
        return match ($this) {
            self::TICKET => 'Tickets',
            self::PQRS => 'Pqrs',
            self::COMPRA => 'Compras',
        };
    }

    /**
     * Comments table name (e.g., 'TicketComments')
     */
    public function commentsTable(): string
    {
        return match ($this) {
            self::TICKET => 'TicketComments',
            self::PQRS => 'PqrsComments',
            self::COMPRA => 'ComprasComments',
        };
    }

    /**
     * History table name (e.g., 'TicketHistory')
     */
    public function historyTable(): string
    {
        return match ($this) {
            self::TICKET => 'TicketHistory',
            self::PQRS => 'PqrsHistory',
            self::COMPRA => 'ComprasHistory',
        };
    }

    /**
     * Attachments table name (e.g., 'Attachments')
     */
    public function attachmentsTable(): string
    {
        return match ($this) {
            self::TICKET => 'Attachments',
            self::PQRS => 'PqrsAttachments',
            self::COMPRA => 'ComprasAttachments',
        };
    }

    /**
     * Primary foreign key name (e.g., 'ticket_id')
     */
    public function foreignKey(): string
    {
        return match ($this) {
            self::TICKET => 'ticket_id',
            self::PQRS => 'pqrs_id',
            self::COMPRA => 'compra_id',
        };
    }

    /**
     * Comment foreign key in attachments table
     */
    public function commentForeignKey(): string
    {
        return match ($this) {
            self::TICKET => 'comment_id',
            self::PQRS => 'pqrs_comment_id',
            self::COMPRA => 'compras_comment_id',
        };
    }

    /**
     * Entity number field name (e.g., 'ticket_number')
     */
    public function numberField(): string
    {
        return match ($this) {
            self::TICKET => 'ticket_number',
            self::PQRS => 'pqrs_number',
            self::COMPRA => 'compra_number',
        };
    }

    /**
     * Comments association name on entity (e.g., 'ticket_comments')
     */
    public function commentsAssociation(): string
    {
        return match ($this) {
            self::TICKET => 'ticket_comments',
            self::PQRS => 'pqrs_comments',
            self::COMPRA => 'compras_comments',
        };
    }

    /**
     * Attachments association name on entity (e.g., 'attachments')
     */
    public function attachmentsAssociation(): string
    {
        return match ($this) {
            self::TICKET => 'attachments',
            self::PQRS => 'pqrs_attachments',
            self::COMPRA => 'compras_attachments',
        };
    }

    /**
     * S3 storage prefix (e.g., 'tickets/')
     */
    public function s3Prefix(): string
    {
        return match ($this) {
            self::TICKET => 'tickets',
            self::PQRS => 'pqrs',
            self::COMPRA => 'compras',
        };
    }

    /**
     * Local upload base directory relative to webroot (e.g., 'uploads/attachments')
     */
    public function uploadBasePath(): string
    {
        return match ($this) {
            self::TICKET => 'uploads' . DS . 'attachments',
            self::PQRS => 'uploads' . DS . 'pqrs',
            self::COMPRA => 'uploads' . DS . 'compras',
        };
    }

    /**
     * Field name for 'uploaded_by' in attachment records
     */
    public function uploadedByField(): string
    {
        return match ($this) {
            self::TICKET, self::PQRS => 'uploaded_by',
            self::COMPRA => 'uploaded_by_user_id',
        };
    }

    /**
     * WhatsApp config number key (e.g., 'whatsapp_tickets_number')
     */
    public function whatsappNumberKey(): string
    {
        return match ($this) {
            self::TICKET => SettingKeys::WHATSAPP_TICKETS_NUMBER,
            self::PQRS => SettingKeys::WHATSAPP_PQRS_NUMBER,
            self::COMPRA => SettingKeys::WHATSAPP_COMPRAS_NUMBER,
        };
    }

    /**
     * Tags table name (e.g., 'TicketTags')
     */
    public function tagsTable(): string
    {
        return match ($this) {
            self::TICKET => 'TicketTags',
            self::PQRS => 'PqrsTags',
            self::COMPRA => 'ComprasTags',
        };
    }

    /**
     * Human-readable label in Spanish
     */
    public function label(): string
    {
        return match ($this) {
            self::TICKET => 'Ticket',
            self::PQRS => 'PQRS',
            self::COMPRA => 'Compra',
        };
    }

    /**
     * Get the entity number value from an entity instance
     */
    public function getNumber(\Cake\Datasource\EntityInterface $entity): string
    {
        $field = $this->numberField();

        return (string)$entity->get($field);
    }
}
