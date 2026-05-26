<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Make ticket_history.changed_by nullable so events with no human actor
 * (inbound email/WhatsApp ingestion, scheduled jobs, system-level mutations)
 * can be recorded with changed_by = NULL meaning "system".
 *
 * Closes INC-3 from the 2026-05-26 history-coverage audit: the column was
 * NOT NULL but the application APIs accepted ?int $userId = null, causing
 * silent INSERT failures whenever no actor was supplied.
 */
class MakeChangedByNullableInTicketHistory extends BaseMigration
{
    public function change(): void
    {
        $this->table('ticket_history')
            ->changeColumn('changed_by', 'integer', [
                'comment' => 'User who made the change. NULL = system event (inbound ingestion, scheduled jobs).',
                'default' => null,
                'limit' => null,
                'null' => true,
                'signed' => false,
            ])
            ->update();
    }
}
