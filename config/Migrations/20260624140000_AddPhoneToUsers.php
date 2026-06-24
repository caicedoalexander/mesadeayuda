<?php
declare(strict_types=1);

use Migrations\BaseMigration;

final class AddPhoneToUsers extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $table = $this->table('users');

        $table->addColumn('phone', 'string', [
            'limit' => 50,
            'null' => true,
            'default' => null,
            'after' => 'last_name',
            'comment' => 'E.164 phone number for requesters auto-created from WhatsApp',
        ]);

        // Backs the phone-keyed requester lookup in TicketIngestionService.
        $table->addIndex(['phone'], [
            'name' => 'idx_users_phone',
        ]);

        $table->update();
    }
}
