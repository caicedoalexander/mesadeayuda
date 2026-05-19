<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Adds RFC 5322 threading columns (Message-ID, In-Reply-To, References) to
 * tickets and ticket_comments so TicketIngestionService can reattach incoming
 * replies to existing tickets even when Gmail's threadId is missing or wrong.
 */
final class AddRfcThreadingToTickets extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('tickets')
            ->addColumn('rfc_message_id', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('in_reply_to', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('references_header', 'text', ['null' => true, 'default' => null])
            ->addIndex(['rfc_message_id'], ['name' => 'idx_tickets_rfc_message_id'])
            ->update();

        $this->table('ticket_comments')
            ->addColumn('rfc_message_id', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('in_reply_to', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('references_header', 'text', ['null' => true, 'default' => null])
            ->addIndex(['rfc_message_id'], ['name' => 'idx_ticket_comments_rfc_message_id'])
            ->update();
    }
}
