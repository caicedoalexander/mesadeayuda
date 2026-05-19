<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Operational retry queue for Gmail::markAsRead failures during ingestion.
 * Drained at the start of every GmailImportService::run(); not domain data.
 */
final class CreateGmailMarkReadPending extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('gmail_mark_read_pending')
            ->addColumn('gmail_message_id', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('attempts', 'integer', ['signed' => false, 'limit' => 3, 'default' => 0, 'null' => false])
            ->addColumn('last_error', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('last_category', 'string', ['limit' => 32, 'null' => true, 'default' => null])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addIndex(['gmail_message_id'], ['unique' => true, 'name' => 'uniq_mark_read_message_id'])
            ->create();
    }
}
