<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class Initial extends BaseMigration
{
    public bool $autoId = false;

    /**
     * Up Method.
     *
     * More information on this method is available here:
     * https://book.cakephp.org/phinx/0/en/migrations.html#the-up-method
     *
     * @return void
     */
    public function up(): void
    {
        $this->table('attachments')
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'default' => null,
                'limit' => null,
                'null' => false,
                'signed' => false,
            ])
            ->addPrimaryKey(['id'])
            ->addColumn('ticket_id', 'integer', [
                'comment' => 'Reference to parent ticket',
                'default' => null,
                'limit' => null,
                'null' => false,
                'signed' => false,
            ])
            ->addColumn('comment_id', 'integer', [
                'comment' => 'Reference to ticket comment (null if attached to ticket directly)',
                'default' => null,
                'limit' => null,
                'null' => true,
                'signed' => false,
            ])
            ->addColumn('filename', 'string', [
                'comment' => 'Sanitized filename stored on disk (unique, safe)',
                'default' => null,
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('original_filename', 'string', [
                'comment' => 'Original filename uploaded by user (for display)',
                'default' => null,
                'limit' => 255,
                'null' => true,
            ])
            ->addColumn('file_path', 'string', [
                'comment' => 'Relative path from webroot (e.g., uploads/tickets/123/file.pdf)',
                'default' => null,
                'limit' => 500,
                'null' => false,
            ])
            ->addColumn('file_size', 'integer', [
                'comment' => 'File size in bytes',
                'default' => null,
                'limit' => null,
                'null' => false,
                'signed' => true,
            ])
            ->addColumn('mime_type', 'string', [
                'comment' => 'MIME type (e.g., application/pdf, image/jpeg)',
                'default' => null,
                'limit' => 100,
                'null' => false,
            ])
            ->addColumn('is_inline', 'boolean', [
                'comment' => 'True if embedded inline in email HTML (vs regular attachment)',
                'default' => false,
                'limit' => null,
                'null' => false,
            ])
            ->addColumn('content_id', 'string', [
                'comment' => 'Content-ID for inline images (cid: references in HTML)',
                'default' => null,
                'limit' => 255,
                'null' => true,
            ])
            ->addColumn('uploaded_by', 'integer', [
                'comment' => 'User who uploaded the file',
                'default' => null,
                'limit' => null,
                'null' => false,
                'signed' => false,
            ])
            ->addColumn('created', 'datetime', [
                'comment' => 'File upload timestamp',
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addIndex(
                $this->index('ticket_id')
                    ->setName('idx_ticket_id')
            )
            ->addIndex(
                $this->index('comment_id')
                    ->setName('idx_comment_id')
            )
            ->addIndex(
                $this->index('content_id')
                    ->setName('idx_content_id')
            )
            ->addIndex(
                $this->index('uploaded_by')
                    ->setName('idx_uploaded_by')
            )
            ->addIndex(
                $this->index('created')
                    ->setName('idx_created')
            )
            ->addIndex(
                $this->index([
                        'ticket_id',
                        'is_inline',
                    ])
                    ->setName('idx_ticket_inline')
            )
            ->create();

        $this->table('email_templates')
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'default' => null,
                'limit' => null,
                'null' => false,
                'signed' => false,
            ])
            ->addPrimaryKey(['id'])
            ->addColumn('template_key', 'string', [
                'comment' => 'Unique template identifier (e.g., ticket_created)',
                'default' => null,
                'limit' => 100,
                'null' => false,
            ])
            ->addColumn('subject', 'string', [
                'comment' => 'Email subject line (supports variables)',
                'default' => null,
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('body_html', 'text', [
                'comment' => 'HTML email body (supports variables and HTML tags)',
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addColumn('available_variables', 'text', [
                'comment' => 'JSON array of available variables for this template',
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addColumn('is_active', 'boolean', [
                'comment' => 'Template status (active templates are used for sending)',
                'default' => true,
                'limit' => null,
                'null' => false,
            ])
            ->addColumn('created', 'datetime', [
                'comment' => 'Template creation timestamp',
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addColumn('modified', 'datetime', [
                'comment' => 'Last modification timestamp',
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addIndex(
                $this->index('template_key')
                    ->setName('idx_template_key_unique')
                    ->setType('unique')
            )
            ->addIndex(
                $this->index('is_active')
                    ->setName('idx_is_active')
            )
            ->create();

        $this->table('organizations')
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'default' => null,
                'limit' => null,
                'null' => false,
                'signed' => false,
            ])
            ->addPrimaryKey(['id'])
            ->addColumn('name', 'string', [
                'comment' => 'Organization name',
                'default' => null,
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('domain', 'string', [
                'comment' => 'Email domain for auto-assignment (e.g., company.com)',
                'default' => null,
                'limit' => 255,
                'null' => true,
            ])
            ->addColumn('created', 'datetime', [
                'comment' => 'Creation timestamp',
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addColumn('modified', 'datetime', [
                'comment' => 'Last modification timestamp',
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addIndex(
                $this->index('name')
                    ->setName('idx_name')
            )
            ->addIndex(
                $this->index('domain')
                    ->setName('idx_domain')
            )
            ->create();

        $this->table('system_settings')
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'default' => null,
                'limit' => null,
                'null' => false,
                'signed' => false,
            ])
            ->addPrimaryKey(['id'])
            ->addColumn('setting_key', 'string', [
                'comment' => 'Unique setting identifier (e.g., gmail_user_email)',
                'default' => null,
                'limit' => 100,
                'null' => false,
            ])
            ->addColumn('setting_value', 'text', [
                'comment' => 'Setting value (may be encrypted for sensitive data)',
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addColumn('setting_type', 'string', [
                'comment' => 'Data type: string, boolean, integer, json, encrypted',
                'default' => null,
                'limit' => 50,
                'null' => true,
            ])
            ->addColumn('description', 'string', [
                'comment' => 'Human-readable description of the setting',
                'default' => null,
                'limit' => 255,
                'null' => true,
            ])
            ->addColumn('created', 'datetime', [
                'comment' => 'Setting creation timestamp',
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addColumn('modified', 'datetime', [
                'comment' => 'Last modification timestamp',
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addIndex(
                $this->index('setting_key')
                    ->setName('idx_setting_key_unique')
                    ->setType('unique')
            )
            ->addIndex(
                $this->index('setting_type')
                    ->setName('idx_setting_type')
            )
            ->create();

        $this->table('tags')
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'default' => null,
                'limit' => null,
                'null' => false,
                'signed' => false,
            ])
            ->addPrimaryKey(['id'])
            ->addColumn('name', 'string', [
                'comment' => 'Tag name (unique)',
                'default' => null,
                'limit' => 100,
                'null' => false,
            ])
            ->addColumn('color', 'string', [
                'comment' => 'Hex color code for UI display (e.g., #FF5733)',
                'default' => '#3498db',
                'limit' => 7,
                'null' => false,
            ])
            ->addColumn('is_active', 'boolean', [
                'comment' => 'Active status (inactive tags are hidden from selection)',
                'default' => true,
                'limit' => null,
                'null' => false,
            ])
            ->addColumn('created', 'datetime', [
                'comment' => 'Tag creation timestamp',
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addColumn('modified', 'datetime', [
                'comment' => 'Last modification timestamp',
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addIndex(
                $this->index('name')
                    ->setName('idx_name_unique')
                    ->setType('unique')
            )
            ->addIndex(
                $this->index('is_active')
                    ->setName('idx_is_active')
            )
            ->create();

        $this->table('ticket_comments')
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'default' => null,
                'limit' => null,
                'null' => false,
                'signed' => false,
            ])
            ->addPrimaryKey(['id'])
            ->addColumn('ticket_id', 'integer', [
                'comment' => 'Reference to parent ticket',
                'default' => null,
                'limit' => null,
                'null' => false,
                'signed' => false,
            ])
            ->addColumn('user_id', 'integer', [
                'comment' => 'User who created the comment (agent or system)',
                'default' => null,
                'limit' => null,
                'null' => false,
                'signed' => false,
            ])
            ->addColumn('body', 'text', [
                'comment' => 'Comment text content (supports HTML)',
                'default' => null,
                'limit' => null,
                'null' => false,
            ])
            ->addColumn('comment_type', 'string', [
                'comment' => 'public = visible to requester, internal = agent notes only',
                'default' => 'public',
                'limit' => null,
                'null' => false,
            ])
            ->addColumn('is_system_comment', 'boolean', [
                'comment' => 'True if automatically generated by system (status changes, etc.)',
                'default' => false,
                'limit' => null,
                'null' => false,
            ])
            ->addColumn('email_to', 'text', [
                'comment' => 'JSON array of To recipients when sent as email response',
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addColumn('email_cc', 'text', [
                'comment' => 'JSON array of CC recipients when sent as email response',
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addColumn('created', 'datetime', [
                'comment' => 'Comment creation timestamp',
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addColumn('modified', 'datetime', [
                'comment' => 'Last modification timestamp',
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addIndex(
                $this->index('ticket_id')
                    ->setName('idx_ticket_id')
            )
            ->addIndex(
                $this->index('user_id')
                    ->setName('idx_user_id')
            )
            ->addIndex(
                $this->index('created')
                    ->setName('idx_created')
            )
            ->addIndex(
                $this->index('comment_type')
                    ->setName('idx_comment_type')
            )
            ->addIndex(
                $this->index('is_system_comment')
                    ->setName('idx_is_system_comment')
            )
            ->addIndex(
                $this->index([
                        'ticket_id',
                        'created',
                    ])
                    ->setName('idx_ticket_created')
            )
            ->addIndex(
                $this->index([
                        'ticket_id',
                        'comment_type',
                    ])
                    ->setName('idx_ticket_comment_type')
            )
            ->create();

        $this->table('ticket_followers')
            ->addColumn('ticket_id', 'integer', [
                'comment' => 'Reference to the ticket being followed',
                'default' => null,
                'limit' => null,
                'null' => false,
                'signed' => false,
            ])
            ->addColumn('user_id', 'integer', [
                'comment' => 'User who is following the ticket',
                'default' => null,
                'limit' => null,
                'null' => false,
                'signed' => false,
            ])
            ->addPrimaryKey(['ticket_id', 'user_id'])
            ->addColumn('created', 'datetime', [
                'comment' => 'When user started following the ticket',
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addIndex(
                $this->index('user_id')
                    ->setName('idx_user_id')
            )
            ->addIndex(
                $this->index('ticket_id')
                    ->setName('idx_ticket_id')
            )
            ->create();

        $this->table('ticket_history')
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'default' => null,
                'limit' => null,
                'null' => false,
                'signed' => false,
            ])
            ->addPrimaryKey(['id'])
            ->addColumn('ticket_id', 'integer', [
                'comment' => 'Reference to the ticket that was modified',
                'default' => null,
                'limit' => null,
                'null' => false,
                'signed' => false,
            ])
            ->addColumn('changed_by', 'integer', [
                'comment' => 'User who made the change',
                'default' => null,
                'limit' => null,
                'null' => false,
                'signed' => false,
            ])
            ->addColumn('field_name', 'string', [
                'comment' => 'Name of the field that was changed (e.g., status, priority, assignee_id)',
                'default' => null,
                'limit' => 50,
                'null' => false,
            ])
            ->addColumn('old_value', 'text', [
                'comment' => 'Previous value before change (null for new records)',
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addColumn('new_value', 'text', [
                'comment' => 'New value after change',
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addColumn('description', 'string', [
                'comment' => 'Human-readable description of the change',
                'default' => null,
                'limit' => 255,
                'null' => true,
            ])
            ->addColumn('created', 'datetime', [
                'comment' => 'When the change occurred',
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addIndex(
                $this->index('ticket_id')
                    ->setName('idx_ticket_id')
            )
            ->addIndex(
                $this->index('changed_by')
                    ->setName('idx_changed_by')
            )
            ->addIndex(
                $this->index('created')
                    ->setName('idx_created')
            )
            ->addIndex(
                $this->index('field_name')
                    ->setName('idx_field_name')
            )
            ->addIndex(
                $this->index([
                        'ticket_id',
                        'created',
                    ])
                    ->setName('idx_ticket_created')
            )
            ->addIndex(
                $this->index([
                        'ticket_id',
                        'field_name',
                    ])
                    ->setName('idx_ticket_field')
            )
            ->create();

        $this->table('tickets')
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'default' => null,
                'limit' => null,
                'null' => false,
                'signed' => false,
            ])
            ->addPrimaryKey(['id'])
            ->addColumn('gmail_message_id', 'string', [
                'comment' => 'Gmail Message-ID for email threading and duplicate prevention',
                'default' => null,
                'limit' => 255,
                'null' => true,
            ])
            ->addColumn('gmail_thread_id', 'string', [
                'comment' => 'Gmail Thread-ID for grouping related email conversations',
                'default' => null,
                'limit' => 255,
                'null' => true,
            ])
            ->addColumn('email_to', 'text', [
                'comment' => 'JSON array of To recipients from original email',
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addColumn('email_cc', 'text', [
                'comment' => 'JSON array of CC recipients from original email',
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addColumn('subject', 'string', [
                'comment' => 'Ticket subject/title',
                'default' => null,
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('description', 'text', [
                'comment' => 'Ticket description/body (supports HTML)',
                'default' => null,
                'limit' => null,
                'null' => false,
            ])
            ->addColumn('channel', 'string', [
                'comment' => 'Creation channel: email (Gmail), web (manual), api (future)',
                'default' => 'email',
                'limit' => 20,
                'null' => false,
            ])
            ->addColumn('status', 'string', [
                'comment' => 'Ticket status. \"convertido\" = converted to purchase request',
                'default' => 'nuevo',
                'limit' => null,
                'null' => false,
            ])
            ->addColumn('priority', 'string', [
                'comment' => 'Ticket priority level',
                'default' => 'media',
                'limit' => null,
                'null' => false,
            ])
            ->addColumn('requester_id', 'integer', [
                'comment' => 'User who created/requested the ticket',
                'default' => null,
                'limit' => null,
                'null' => false,
                'signed' => false,
            ])
            ->addColumn('assignee_id', 'integer', [
                'comment' => 'Agent assigned to handle the ticket',
                'default' => null,
                'limit' => null,
                'null' => true,
                'signed' => false,
            ])
            ->addColumn('created', 'datetime', [
                'comment' => 'Ticket creation timestamp',
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addColumn('modified', 'datetime', [
                'comment' => 'Last modification timestamp',
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addColumn('resolved_at', 'datetime', [
                'comment' => 'Timestamp when ticket was resolved (status=resuelto)',
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addIndex(
                $this->index('gmail_message_id')
                    ->setName('idx_gmail_message_id_unique')
                    ->setType('unique')
            )
            ->addIndex(
                $this->index('gmail_thread_id')
                    ->setName('idx_gmail_thread_id')
            )
            ->addIndex(
                $this->index('priority')
                    ->setName('idx_priority')
            )
            ->addIndex(
                $this->index('assignee_id')
                    ->setName('idx_assignee_id')
            )
            ->addIndex(
                $this->index('requester_id')
                    ->setName('idx_requester_id')
            )
            ->addIndex(
                $this->index('created')
                    ->setName('idx_created')
            )
            ->addIndex(
                $this->index('channel')
                    ->setName('idx_channel')
            )
            ->addIndex(
                $this->index([
                        'status',
                        'priority',
                    ])
                    ->setName('idx_status_priority')
            )
            ->addIndex(
                $this->index([
                        'assignee_id',
                        'status',
                    ])
                    ->setName('idx_assignee_status')
            )
            ->addIndex(
                $this->index([
                        'status',
                        'created',
                    ])
                    ->setName('idx_status_created')
            )
            ->create();

        // Los tickets nuevos arrancan su id (identificador visible) en 1000.
        $this->execute('ALTER TABLE tickets AUTO_INCREMENT = 1000');

        $this->table('tickets_tags')
            ->addColumn('ticket_id', 'integer', [
                'comment' => 'Reference to the ticket',
                'default' => null,
                'limit' => null,
                'null' => false,
                'signed' => false,
            ])
            ->addColumn('tag_id', 'integer', [
                'comment' => 'Reference to the tag',
                'default' => null,
                'limit' => null,
                'null' => false,
                'signed' => false,
            ])
            ->addPrimaryKey(['ticket_id', 'tag_id'])
            ->addIndex(
                $this->index('tag_id')
                    ->setName('idx_tag_id')
            )
            ->addIndex(
                $this->index('ticket_id')
                    ->setName('idx_ticket_id')
            )
            ->create();

        $this->table('users')
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'default' => null,
                'limit' => null,
                'null' => false,
                'signed' => false,
            ])
            ->addPrimaryKey(['id'])
            ->addColumn('email', 'string', [
                'comment' => 'Email address (used for login)',
                'default' => null,
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('password', 'string', [
                'comment' => 'Hashed password (NULL for auto-created users from Gmail)',
                'default' => null,
                'limit' => 255,
                'null' => true,
            ])
            ->addColumn('first_name', 'string', [
                'comment' => 'First name',
                'default' => null,
                'limit' => 100,
                'null' => false,
            ])
            ->addColumn('last_name', 'string', [
                'comment' => 'Last name',
                'default' => null,
                'limit' => 100,
                'null' => false,
            ])
            ->addColumn('role', 'string', [
                'comment' => 'User role for authorization',
                'default' => 'requester',
                'limit' => null,
                'null' => false,
            ])
            ->addColumn('organization_id', 'integer', [
                'comment' => 'Associated organization (optional)',
                'default' => null,
                'limit' => null,
                'null' => true,
                'signed' => false,
            ])
            ->addColumn('profile_image', 'string', [
                'comment' => 'Path to profile photo',
                'default' => null,
                'limit' => 255,
                'null' => true,
            ])
            ->addColumn('is_active', 'boolean', [
                'comment' => 'Account status (active/inactive)',
                'default' => true,
                'limit' => null,
                'null' => false,
            ])
            ->addColumn('created', 'datetime', [
                'comment' => 'Account creation timestamp',
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addColumn('modified', 'datetime', [
                'comment' => 'Last modification timestamp',
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addIndex(
                $this->index('email')
                    ->setName('idx_email_unique')
                    ->setType('unique')
            )
            ->addIndex(
                $this->index('role')
                    ->setName('idx_role')
            )
            ->addIndex(
                $this->index('is_active')
                    ->setName('idx_is_active')
            )
            ->addIndex(
                $this->index([
                        'role',
                        'is_active',
                    ])
                    ->setName('idx_role_active')
            )
            ->create();

        $this->table('attachments')
            ->addForeignKey(
                $this->foreignKey('uploaded_by')
                    ->setReferencedTable('users')
                    ->setReferencedColumns('id')
                    ->setOnDelete('CASCADE')
                    ->setOnUpdate('CASCADE')
                    ->setName('fk_attachments_user')
            )
            ->addForeignKey(
                $this->foreignKey('ticket_id')
                    ->setReferencedTable('tickets')
                    ->setReferencedColumns('id')
                    ->setOnDelete('CASCADE')
                    ->setOnUpdate('CASCADE')
                    ->setName('fk_attachments_ticket')
            )
            ->addForeignKey(
                $this->foreignKey('comment_id')
                    ->setReferencedTable('ticket_comments')
                    ->setReferencedColumns('id')
                    ->setOnDelete('CASCADE')
                    ->setOnUpdate('CASCADE')
                    ->setName('fk_attachments_comment')
            )
            ->update();

        $this->table('ticket_comments')
            ->addForeignKey(
                $this->foreignKey('user_id')
                    ->setReferencedTable('users')
                    ->setReferencedColumns('id')
                    ->setOnDelete('CASCADE')
                    ->setOnUpdate('CASCADE')
                    ->setName('fk_ticket_comments_user')
            )
            ->addForeignKey(
                $this->foreignKey('ticket_id')
                    ->setReferencedTable('tickets')
                    ->setReferencedColumns('id')
                    ->setOnDelete('CASCADE')
                    ->setOnUpdate('CASCADE')
                    ->setName('fk_ticket_comments_ticket')
            )
            ->update();

        $this->table('ticket_followers')
            ->addForeignKey(
                $this->foreignKey('user_id')
                    ->setReferencedTable('users')
                    ->setReferencedColumns('id')
                    ->setOnDelete('CASCADE')
                    ->setOnUpdate('CASCADE')
                    ->setName('fk_ticket_followers_user')
            )
            ->addForeignKey(
                $this->foreignKey('ticket_id')
                    ->setReferencedTable('tickets')
                    ->setReferencedColumns('id')
                    ->setOnDelete('CASCADE')
                    ->setOnUpdate('CASCADE')
                    ->setName('fk_ticket_followers_ticket')
            )
            ->update();

        $this->table('ticket_history')
            ->addForeignKey(
                $this->foreignKey('changed_by')
                    ->setReferencedTable('users')
                    ->setReferencedColumns('id')
                    ->setOnDelete('CASCADE')
                    ->setOnUpdate('CASCADE')
                    ->setName('fk_ticket_history_user')
            )
            ->addForeignKey(
                $this->foreignKey('ticket_id')
                    ->setReferencedTable('tickets')
                    ->setReferencedColumns('id')
                    ->setOnDelete('CASCADE')
                    ->setOnUpdate('CASCADE')
                    ->setName('fk_ticket_history_ticket')
            )
            ->update();

        $this->table('tickets')
            ->addForeignKey(
                $this->foreignKey('requester_id')
                    ->setReferencedTable('users')
                    ->setReferencedColumns('id')
                    ->setOnDelete('CASCADE')
                    ->setOnUpdate('CASCADE')
                    ->setName('fk_tickets_requester')
            )
            ->addForeignKey(
                $this->foreignKey('assignee_id')
                    ->setReferencedTable('users')
                    ->setReferencedColumns('id')
                    ->setOnDelete('SET_NULL')
                    ->setOnUpdate('CASCADE')
                    ->setName('fk_tickets_assignee')
            )
            ->update();

        $this->table('tickets_tags')
            ->addForeignKey(
                $this->foreignKey('ticket_id')
                    ->setReferencedTable('tickets')
                    ->setReferencedColumns('id')
                    ->setOnDelete('CASCADE')
                    ->setOnUpdate('CASCADE')
                    ->setName('fk_tickets_tags_ticket')
            )
            ->addForeignKey(
                $this->foreignKey('tag_id')
                    ->setReferencedTable('tags')
                    ->setReferencedColumns('id')
                    ->setOnDelete('CASCADE')
                    ->setOnUpdate('CASCADE')
                    ->setName('fk_tickets_tags_tag')
            )
            ->update();
    }

    /**
     * Down Method.
     *
     * More information on this method is available here:
     * https://book.cakephp.org/phinx/0/en/migrations.html#the-down-method
     *
     * @return void
     */
    public function down(): void
    {
        $this->table('attachments')
            ->dropForeignKey(
                'uploaded_by'
            )
            ->dropForeignKey(
                'ticket_id'
            )
            ->dropForeignKey(
                'comment_id'
            )->save();

        $this->table('ticket_comments')
            ->dropForeignKey(
                'user_id'
            )
            ->dropForeignKey(
                'ticket_id'
            )->save();

        $this->table('ticket_followers')
            ->dropForeignKey(
                'user_id'
            )
            ->dropForeignKey(
                'ticket_id'
            )->save();

        $this->table('ticket_history')
            ->dropForeignKey(
                'changed_by'
            )
            ->dropForeignKey(
                'ticket_id'
            )->save();

        $this->table('tickets')
            ->dropForeignKey(
                'requester_id'
            )
            ->dropForeignKey(
                'assignee_id'
            )->save();

        $this->table('tickets_tags')
            ->dropForeignKey(
                'ticket_id'
            )
            ->dropForeignKey(
                'tag_id'
            )->save();

        $this->table('attachments')->drop()->save();
        $this->table('email_templates')->drop()->save();
        $this->table('organizations')->drop()->save();
        $this->table('system_settings')->drop()->save();
        $this->table('tags')->drop()->save();
        $this->table('ticket_comments')->drop()->save();
        $this->table('ticket_followers')->drop()->save();
        $this->table('ticket_history')->drop()->save();
        $this->table('tickets')->drop()->save();
        $this->table('tickets_tags')->drop()->save();
        $this->table('users')->drop()->save();
    }
}
