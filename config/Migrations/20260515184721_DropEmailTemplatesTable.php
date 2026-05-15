<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class DropEmailTemplatesTable extends BaseMigration
{
    /**
     * Drops the email_templates table — templates now live in code under
     * App\Notification\Email\*. Any custom-edited rows are intentionally
     * discarded; the deployment runbook recommends a mysqldump backup
     * before applying.
     */
    public function up(): void
    {
        if ($this->hasTable('email_templates')) {
            $this->table('email_templates')->drop()->update();
        }
    }

    public function down(): void
    {
        // Recreates the original structure so rollback succeeds. Seeded data
        // is NOT restored.
        $this->table('email_templates')
            ->addColumn('template_key', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('subject', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('body_html', 'text', ['null' => true])
            ->addColumn('available_variables', 'text', ['null' => true])
            ->addColumn('is_active', 'boolean', ['default' => true, 'null' => false])
            ->addColumn('created', 'datetime', ['null' => true])
            ->addColumn('modified', 'datetime', ['null' => true])
            ->addIndex(['template_key'], ['unique' => true])
            ->create();
    }
}
