<?php
declare(strict_types=1);

use Migrations\BaseMigration;

final class AddSourcePhoneAndRestoreWhatsappIndex extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $table = $this->table('tickets');

        $table->addColumn('source_phone', 'string', [
            'limit' => 32,
            'null' => true,
            'default' => null,
            'after' => 'email_to',
            'comment' => 'E.164 phone number for tickets ingested from WhatsApp',
        ]);

        // Recover from a previous botched rollback that dropped the unique
        // index from the live database while leaving whatsapp_message_id in
        // place. Only re-add if it does not already exist (fresh installs
        // get the index from the prior migration).
        if (!$table->hasIndex(['whatsapp_message_id'])) {
            $table->addIndex(['whatsapp_message_id'], [
                'name' => 'idx_tickets_whatsapp_message_id',
                'unique' => true,
            ]);
        }

        $table->update();
    }
}
