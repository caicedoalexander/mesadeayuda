<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/**
 * Transient operational queue of Gmail message IDs whose markAsRead() call
 * failed during ingestion. Drained at the start of each GmailImportService
 * run; not domain data, not audited.
 */
class GmailMarkReadPendingTable extends Table
{
    /**
     * @param array<string, mixed> $config Table init config
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('gmail_mark_read_pending');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
    }
}
