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
            ->addIndex(['whatsapp_message_id'], [
                'name' => 'idx_tickets_whatsapp_message_id',
                'unique' => true,
            ])
            ->update();
    }
}
