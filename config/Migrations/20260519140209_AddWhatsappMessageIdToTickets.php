<?php
declare(strict_types=1);

use Migrations\BaseMigration;

final class AddWhatsappMessageIdToTickets extends BaseMigration
{
    public function change(): void
    {
        $this->table('tickets')
            ->addColumn('whatsapp_message_id', 'string', [
                'limit' => 120,
                'null' => true,
                'default' => null,
                'after' => 'gmail_thread_id',
                'comment' => 'WhatsApp message ID (wamid) for idempotent ingest from n8n bot',
            ])
            ->addColumn('source_phone', 'string', [
                'limit' => 32,
                'null' => true,
                'default' => null,
                'after' => 'source_email',
                'comment' => 'E.164 phone number for tickets ingested from WhatsApp',
            ])
            ->addIndex(['whatsapp_message_id'], [
                'name' => 'idx_tickets_whatsapp_message_id',
                'unique' => true,
            ])
            ->update();
    }
}
