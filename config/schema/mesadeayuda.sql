use mesadeayuda;

CREATE TABLE `organizations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL COMMENT 'Organization name',
  `domain` varchar(255) DEFAULT NULL COMMENT 'Email domain for auto-assignment (e.g., company.com)',
  `created` datetime DEFAULT NULL COMMENT 'Creation timestamp',
  `modified` datetime DEFAULT NULL COMMENT 'Last modification timestamp',
  PRIMARY KEY (`id`),
  KEY `idx_name` (`name`),
  KEY `idx_domain` (`domain`)
);

CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL COMMENT 'Email address (used for login)',
  `password` varchar(255) DEFAULT NULL COMMENT 'Hashed password (NULL for auto-created users from Gmail)',
  `first_name` varchar(100) NOT NULL COMMENT 'First name',
  `last_name` varchar(100) NOT NULL COMMENT 'Last name',
  `role` enum('admin','agent','compras','servicio_cliente','requester') NOT NULL DEFAULT 'requester' COMMENT 'User role for authorization',
  `organization_id` int unsigned DEFAULT NULL COMMENT 'Associated organization (optional)',
  `profile_image` varchar(255) DEFAULT NULL COMMENT 'Path to profile photo',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Account status (active/inactive)',
  `created` datetime DEFAULT NULL COMMENT 'Account creation timestamp',
  `modified` datetime DEFAULT NULL COMMENT 'Last modification timestamp',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_email_unique` (`email`),
  KEY `idx_organization_id` (`organization_id`),
  KEY `idx_role` (`role`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_role_active` (`role`,`is_active`),
  CONSTRAINT `fk_users_organization` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE TABLE `email_templates` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `template_key` varchar(100) NOT NULL COMMENT 'Unique template identifier (e.g., ticket_created, pqrs_comment)',
  `subject` varchar(255) NOT NULL COMMENT 'Email subject line (supports variables)',
  `body_html` text COMMENT 'HTML email body (supports variables and HTML tags)',
  `available_variables` text COMMENT 'JSON array of available variables for this template',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Template status (active templates are used for sending)',
  `created` datetime DEFAULT NULL COMMENT 'Template creation timestamp',
  `modified` datetime DEFAULT NULL COMMENT 'Last modification timestamp',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_template_key_unique` (`template_key`),
  KEY `idx_is_active` (`is_active`)
);

CREATE TABLE `tags` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT 'Tag name (unique)',
  `color` varchar(7) NOT NULL DEFAULT '#3498db' COMMENT 'Hex color code for UI display (e.g., #FF5733)',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Active status (inactive tags are hidden from selection)',
  `created` datetime DEFAULT NULL COMMENT 'Tag creation timestamp',
  `modified` datetime DEFAULT NULL COMMENT 'Last modification timestamp',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_name_unique` (`name`),
  KEY `idx_is_active` (`is_active`)
);

CREATE TABLE `system_settings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL COMMENT 'Unique setting identifier (e.g., gmail_user_email, sla_pqrs_queja_first_response_days)',
  `setting_value` text COMMENT 'Setting value (may be encrypted for sensitive data)',
  `setting_type` varchar(50) DEFAULT NULL COMMENT 'Data type: string, boolean, integer, json, encrypted',
  `description` varchar(255) DEFAULT NULL COMMENT 'Human-readable description of the setting',
  `created` datetime DEFAULT NULL COMMENT 'Setting creation timestamp',
  `modified` datetime DEFAULT NULL COMMENT 'Last modification timestamp',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_setting_key_unique` (`setting_key`),
  KEY `idx_setting_type` (`setting_type`)
);

CREATE TABLE `tickets` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ticket_number` varchar(20) NOT NULL COMMENT 'Unique ticket identifier. Format: TKT-YYYY-NNNNN',
  `gmail_message_id` varchar(255) DEFAULT NULL COMMENT 'Gmail Message-ID for email threading and duplicate prevention',
  `gmail_thread_id` varchar(255) DEFAULT NULL COMMENT 'Gmail Thread-ID for grouping related email conversations',
  `email_to` text COMMENT 'JSON array of To recipients from original email',
  `email_cc` text COMMENT 'JSON array of CC recipients from original email',
  `subject` varchar(255) NOT NULL COMMENT 'Ticket subject/title',
  `description` text NOT NULL COMMENT 'Ticket description/body (supports HTML)',
  `channel` varchar(20) NOT NULL DEFAULT 'email' COMMENT 'Creation channel: email (Gmail), web (manual), api (future)',
  `status` enum('nuevo','abierto','pendiente','resuelto','convertido') NOT NULL DEFAULT 'nuevo' COMMENT 'Ticket status. "convertido" = converted to purchase request',
  `priority` enum('baja','media','alta','urgente') NOT NULL DEFAULT 'media' COMMENT 'Ticket priority level',
  `requester_id` int unsigned NOT NULL COMMENT 'User who created/requested the ticket',
  `assignee_id` int unsigned DEFAULT NULL COMMENT 'Agent assigned to handle the ticket',
  `created` datetime DEFAULT NULL COMMENT 'Ticket creation timestamp',
  `modified` datetime DEFAULT NULL COMMENT 'Last modification timestamp',
  `resolved_at` datetime DEFAULT NULL COMMENT 'Timestamp when ticket was resolved (status=resuelto)',
  `first_response_at` datetime DEFAULT NULL COMMENT 'Timestamp of first agent response (for SLA metrics)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_ticket_number_unique` (`ticket_number`),
  UNIQUE KEY `idx_gmail_message_id_unique` (`gmail_message_id`),
  KEY `idx_gmail_thread_id` (`gmail_thread_id`),
  KEY `idx_priority` (`priority`),
  KEY `idx_assignee_id` (`assignee_id`),
  KEY `idx_requester_id` (`requester_id`),
  KEY `idx_created` (`created`),
  KEY `idx_channel` (`channel`),
  KEY `idx_status_priority` (`status`,`priority`),
  KEY `idx_assignee_status` (`assignee_id`,`status`),
  KEY `idx_status_created` (`status`,`created`),
  CONSTRAINT `fk_tickets_assignee` FOREIGN KEY (`assignee_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_tickets_requester` FOREIGN KEY (`requester_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE `ticket_comments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` int unsigned NOT NULL COMMENT 'Reference to parent ticket',
  `user_id` int unsigned NOT NULL COMMENT 'User who created the comment (agent or system)',
  `body` text NOT NULL COMMENT 'Comment text content (supports HTML)',
  `comment_type` enum('public','internal') NOT NULL DEFAULT 'public' COMMENT 'public = visible to requester, internal = agent notes only',
  `is_system_comment` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'True if automatically generated by system (status changes, etc.)',
  `email_to` text COMMENT 'JSON array of To recipients when sent as email response',
  `email_cc` text COMMENT 'JSON array of CC recipients when sent as email response',
  `created` datetime DEFAULT NULL COMMENT 'Comment creation timestamp',
  `modified` datetime DEFAULT NULL COMMENT 'Last modification timestamp',
  PRIMARY KEY (`id`),
  KEY `idx_ticket_id` (`ticket_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created` (`created`),
  KEY `idx_comment_type` (`comment_type`),
  KEY `idx_is_system_comment` (`is_system_comment`),
  KEY `idx_ticket_created` (`ticket_id`,`created`),
  KEY `idx_ticket_comment_type` (`ticket_id`,`comment_type`),
  CONSTRAINT `fk_ticket_comments_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ticket_comments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE `attachments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` int unsigned NOT NULL COMMENT 'Reference to parent ticket',
  `comment_id` int unsigned DEFAULT NULL COMMENT 'Reference to ticket comment (null if attached to ticket directly)',
  `filename` varchar(255) NOT NULL COMMENT 'Sanitized filename stored on disk (unique, safe)',
  `original_filename` varchar(255) DEFAULT NULL COMMENT 'Original filename uploaded by user (for display)',
  `file_path` varchar(500) NOT NULL COMMENT 'Relative path from webroot (e.g., uploads/tickets/123/file.pdf)',
  `file_size` int NOT NULL COMMENT 'File size in bytes',
  `mime_type` varchar(100) NOT NULL COMMENT 'MIME type (e.g., application/pdf, image/jpeg)',
  `is_inline` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'True if embedded inline in email HTML (vs regular attachment)',
  `content_id` varchar(255) DEFAULT NULL COMMENT 'Content-ID for inline images (cid: references in HTML)',
  `uploaded_by` int unsigned NOT NULL COMMENT 'User who uploaded the file',
  `created` datetime DEFAULT NULL COMMENT 'File upload timestamp',
  PRIMARY KEY (`id`),
  KEY `idx_ticket_id` (`ticket_id`),
  KEY `idx_comment_id` (`comment_id`),
  KEY `idx_content_id` (`content_id`),
  KEY `idx_uploaded_by` (`uploaded_by`),
  KEY `idx_created` (`created`),
  KEY `idx_ticket_inline` (`ticket_id`,`is_inline`),
  CONSTRAINT `fk_attachments_comment` FOREIGN KEY (`comment_id`) REFERENCES `ticket_comments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_attachments_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_attachments_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE `ticket_followers` (
  `ticket_id` int unsigned NOT NULL COMMENT 'Reference to the ticket being followed',
  `user_id` int unsigned NOT NULL COMMENT 'User who is following the ticket',
  `created` datetime DEFAULT NULL COMMENT 'When user started following the ticket',
  PRIMARY KEY (`ticket_id`,`user_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_ticket_id` (`ticket_id`),
  CONSTRAINT `fk_ticket_followers_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ticket_followers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE `ticket_history` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` int unsigned NOT NULL COMMENT 'Reference to the ticket that was modified',
  `changed_by` int unsigned NOT NULL COMMENT 'User who made the change',
  `field_name` varchar(50) NOT NULL COMMENT 'Name of the field that was changed (e.g., status, priority, assignee_id)',
  `old_value` text COMMENT 'Previous value before change (null for new records)',
  `new_value` text COMMENT 'New value after change',
  `description` varchar(255) DEFAULT NULL COMMENT 'Human-readable description of the change',
  `created` datetime DEFAULT NULL COMMENT 'When the change occurred',
  PRIMARY KEY (`id`),
  KEY `idx_ticket_id` (`ticket_id`),
  KEY `idx_changed_by` (`changed_by`),
  KEY `idx_created` (`created`),
  KEY `idx_field_name` (`field_name`),
  KEY `idx_ticket_created` (`ticket_id`,`created`),
  KEY `idx_ticket_field` (`ticket_id`,`field_name`),
  CONSTRAINT `fk_ticket_history_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ticket_history_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE `tickets_tags` (
  `ticket_id` int unsigned NOT NULL COMMENT 'Reference to the ticket',
  `tag_id` int unsigned NOT NULL COMMENT 'Reference to the tag',
  PRIMARY KEY (`ticket_id`,`tag_id`),
  KEY `idx_tag_id` (`tag_id`),
  KEY `idx_ticket_id` (`ticket_id`),
  CONSTRAINT `fk_tickets_tags_tag` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_tickets_tags_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE `compras` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `compra_number` varchar(20) NOT NULL COMMENT 'Unique purchase request identifier. Format: CPR-YYYY-NNNNN',
  `original_ticket_number` varchar(20) DEFAULT NULL COMMENT 'Reference to original ticket number (if converted)',
  `subject` varchar(255) NOT NULL COMMENT 'Purchase request subject/title',
  `description` text NOT NULL COMMENT 'Purchase request description/details (supports HTML)',
  `channel` varchar(20) NOT NULL DEFAULT 'email' COMMENT 'Creation channel: email, web (inherited from original ticket)',
  `email_to` json DEFAULT NULL COMMENT 'JSON array of primary email recipients',
  `email_cc` json DEFAULT NULL COMMENT 'JSON array of CC email recipients (e.g., managers)',
  `status` enum('nuevo','en_revision','aprobado','en_proceso','completado','rechazado') NOT NULL DEFAULT 'nuevo' COMMENT 'Purchase request status',
  `priority` enum('baja','media','alta','urgente') NOT NULL DEFAULT 'media' COMMENT 'Purchase request priority level',
  `requester_id` int unsigned NOT NULL COMMENT 'User who requested the purchase',
  `assignee_id` int unsigned DEFAULT NULL COMMENT 'Purchase team member assigned to process this request',
  `created` datetime DEFAULT NULL COMMENT 'Purchase request creation timestamp',
  `modified` datetime DEFAULT NULL COMMENT 'Last modification timestamp',
  `resolved_at` datetime DEFAULT NULL COMMENT 'Timestamp when request was completed or rejected',
  `first_response_at` datetime DEFAULT NULL COMMENT 'Timestamp of first purchase team response (for SLA metrics)',
  `sla_due_date` datetime DEFAULT NULL COMMENT 'Legacy SLA field (deprecated, use resolution_sla_due instead)',
  `first_response_sla_due` datetime DEFAULT NULL COMMENT 'SLA deadline for first response',
  `resolution_sla_due` datetime DEFAULT NULL COMMENT 'SLA deadline for resolution/completion',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_compra_number_unique` (`compra_number`),
  KEY `idx_original_ticket_number` (`original_ticket_number`),
  KEY `idx_priority` (`priority`),
  KEY `idx_assignee_id` (`assignee_id`),
  KEY `idx_requester_id` (`requester_id`),
  KEY `idx_sla_due_date` (`sla_due_date`),
  KEY `idx_compras_first_response_sla` (`first_response_sla_due`),
  KEY `idx_compras_resolution_sla` (`resolution_sla_due`),
  KEY `idx_channel` (`channel`),
  KEY `idx_status_created` (`status`,`created`),
  KEY `idx_assignee_status` (`assignee_id`,`status`),
  CONSTRAINT `fk_compras_assignee` FOREIGN KEY (`assignee_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_compras_requester` FOREIGN KEY (`requester_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE `compras_comments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `compra_id` int unsigned NOT NULL COMMENT 'Reference to parent purchase request',
  `user_id` int unsigned DEFAULT NULL COMMENT 'User who created the comment (null for system comments)',
  `body` text NOT NULL COMMENT 'Comment text content (supports HTML)',
  `comment_type` varchar(20) NOT NULL DEFAULT 'public' COMMENT 'public = visible to requester, internal = agent notes only',
  `is_system_comment` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'True if automatically generated by system',
  `email_to` text COMMENT 'JSON array of To recipients when sent as email',
  `email_cc` text COMMENT 'JSON array of CC recipients when sent as email',
  `sent_as_email` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'True if comment was sent as email notification',
  `created` datetime DEFAULT NULL COMMENT 'Comment creation timestamp',
  `modified` datetime DEFAULT NULL COMMENT 'Last modification timestamp',
  PRIMARY KEY (`id`),
  KEY `idx_compra_id` (`compra_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_comment_type` (`comment_type`),
  KEY `idx_compra_created` (`compra_id`,`created`),
  CONSTRAINT `fk_compras_comments_compra` FOREIGN KEY (`compra_id`) REFERENCES `compras` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_compras_comments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE TABLE `compras_attachments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `compra_id` INT UNSIGNED NOT NULL COMMENT 'Reference to parent purchase request',
    `compras_comment_id` INT UNSIGNED DEFAULT NULL COMMENT 'Reference to compras comment (null if attached to compra directly)',
    `filename` VARCHAR(255) NOT NULL COMMENT 'Sanitized filename stored on disk (unique, safe)',
    `original_filename` VARCHAR(255) NOT NULL COMMENT 'Original filename uploaded by user (for display)',
    `file_path` VARCHAR(500) NOT NULL COMMENT 'Relative path from webroot (e.g., uploads/compras/123/file.pdf)',
    `file_size` INT UNSIGNED DEFAULT NULL COMMENT 'File size in bytes',
    `mime_type` VARCHAR(100) DEFAULT NULL COMMENT 'MIME type (e.g., application/pdf, image/jpeg)',
    `is_inline` TINYINT(1) NOT NULL DEFAULT '0' COMMENT 'True if embedded inline in email HTML',
    `content_id` VARCHAR(255) DEFAULT NULL COMMENT 'Content-ID for inline images (cid: references)',
    `uploaded_by_user_id` INT UNSIGNED DEFAULT NULL COMMENT 'User who uploaded the file',
    `created` DATETIME DEFAULT NULL COMMENT 'File upload timestamp',
    PRIMARY KEY (`id`),
    KEY `idx_compra_id` (`compra_id`),
    KEY `idx_compras_comment_id` (`compras_comment_id`),
    KEY `idx_uploaded_by_user_id` (`uploaded_by_user_id`),
    KEY `idx_content_id` (`content_id`),
    CONSTRAINT `fk_compras_attachments_comment` FOREIGN KEY (`compras_comment_id`)
        REFERENCES `compras_comments` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_compras_attachments_compra` FOREIGN KEY (`compra_id`)
        REFERENCES `compras` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_compras_attachments_user` FOREIGN KEY (`uploaded_by_user_id`)
        REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE TABLE `compras_history` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `compra_id` int unsigned NOT NULL COMMENT 'Reference to the purchase request that was modified',
  `changed_by` int unsigned DEFAULT NULL COMMENT 'User who made the change',
  `field_name` varchar(100) NOT NULL COMMENT 'Name of the field that was changed',
  `old_value` varchar(255) DEFAULT NULL COMMENT 'Previous value before change',
  `new_value` varchar(255) DEFAULT NULL COMMENT 'New value after change',
  `description` text COMMENT 'Human-readable description of the change',
  `created` datetime DEFAULT NULL COMMENT 'When the change occurred',
  PRIMARY KEY (`id`),
  KEY `idx_compra_id` (`compra_id`),
  KEY `idx_changed_by` (`changed_by`),
  KEY `idx_field_name` (`field_name`),
  KEY `idx_compra_created` (`compra_id`,`created`),
  CONSTRAINT `fk_compras_history_compra` FOREIGN KEY (`compra_id`) REFERENCES `compras` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_compras_history_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE TABLE `pqrs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `pqrs_number` varchar(20) NOT NULL COMMENT 'Unique PQRS identifier. Format: PQRS-YYYY-NNNNN',
  `type` enum('peticion','queja','reclamo','sugerencia') NOT NULL COMMENT 'Type of request (determines SLA targets)',
  `subject` varchar(255) NOT NULL COMMENT 'PQRS subject/title',
  `description` text NOT NULL COMMENT 'PQRS description/details (supports HTML)',
  `status` enum('nuevo','en_revision','en_proceso','resuelto','cerrado') NOT NULL DEFAULT 'nuevo' COMMENT 'PQRS status',
  `priority` enum('baja','media','alta','urgente') NOT NULL DEFAULT 'media' COMMENT 'PQRS priority level',
  `requester_name` varchar(255) NOT NULL COMMENT 'Full name of person submitting PQRS',
  `requester_email` varchar(255) NOT NULL COMMENT 'Email address for notifications',
  `requester_phone` varchar(20) DEFAULT NULL COMMENT 'Optional phone number',
  `channel` varchar(20) NOT NULL DEFAULT 'web' COMMENT 'Channel: web (public form), whatsapp',
  `assignee_id` int unsigned DEFAULT NULL COMMENT 'Customer service agent assigned to handle PQRS',
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'IP address of submitter (IPv4 or IPv6)',
  `user_agent` text COMMENT 'Browser user agent string',
  `source_url` varchar(500) DEFAULT NULL COMMENT 'URL where form was submitted from',
  `created` datetime DEFAULT NULL COMMENT 'PQRS creation timestamp',
  `modified` datetime DEFAULT NULL COMMENT 'Last modification timestamp',
  `resolved_at` datetime DEFAULT NULL COMMENT 'Timestamp when PQRS was marked as resolved',
  `first_response_at` datetime DEFAULT NULL COMMENT 'Timestamp of first agent response (for SLA metrics)',
  `closed_at` datetime DEFAULT NULL COMMENT 'Timestamp when PQRS was closed',
  `first_response_sla_due` datetime DEFAULT NULL COMMENT 'SLA deadline for first response (varies by type)',
  `resolution_sla_due` datetime DEFAULT NULL COMMENT 'SLA deadline for resolution (varies by type)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_pqrs_number_unique` (`pqrs_number`),
  KEY `idx_priority` (`priority`),
  KEY `idx_assignee_id` (`assignee_id`),
  KEY `idx_type` (`type`),
  KEY `idx_status` (`status`),
  KEY `idx_channel` (`channel`),
  KEY `idx_requester_email` (`requester_email`),
  KEY `idx_first_response_sla` (`first_response_sla_due`),
  KEY `idx_resolution_sla` (`resolution_sla_due`),
  KEY `idx_status_created` (`status`,`created`),
  KEY `idx_type_status` (`type`,`status`),
  KEY `idx_assignee_status` (`assignee_id`,`status`),
  CONSTRAINT `fk_pqrs_assignee` FOREIGN KEY (`assignee_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE TABLE `pqrs_comments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `pqrs_id` int unsigned NOT NULL COMMENT 'Reference to parent PQRS',
  `user_id` int unsigned NOT NULL COMMENT 'User who created the comment (customer service agent)',
  `body` text NOT NULL COMMENT 'Comment text content (supports HTML)',
  `comment_type` enum('public','internal') NOT NULL DEFAULT 'public' COMMENT 'public = visible to requester, internal = agent notes only',
  `is_system_comment` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'True if automatically generated by system',
  `email_to` text COMMENT 'JSON array of To recipients when sent as email',
  `email_cc` text COMMENT 'JSON array of CC recipients when sent as email',
  `created` datetime DEFAULT NULL COMMENT 'Comment creation timestamp',
  `modified` datetime DEFAULT NULL COMMENT 'Last modification timestamp',
  PRIMARY KEY (`id`),
  KEY `idx_pqrs_id` (`pqrs_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created` (`created`),
  KEY `idx_comment_type` (`comment_type`),
  KEY `idx_pqrs_created` (`pqrs_id`,`created`),
  CONSTRAINT `fk_pqrs_comments_pqrs` FOREIGN KEY (`pqrs_id`) REFERENCES `pqrs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pqrs_comments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE `pqrs_attachments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `pqrs_id` int unsigned NOT NULL COMMENT 'Reference to parent PQRS',
  `comment_id` int unsigned DEFAULT NULL COMMENT 'Reference to PQRS comment (null if attached to PQRS directly)',
  `filename` varchar(255) NOT NULL COMMENT 'Sanitized filename stored on disk',
  `original_filename` varchar(255) NOT NULL COMMENT 'Original filename uploaded by user (for display)',
  `file_path` varchar(500) NOT NULL COMMENT 'Relative path from webroot (e.g., uploads/pqrs/123/file.pdf)',
  `file_size` int NOT NULL COMMENT 'File size in bytes',
  `mime_type` varchar(100) NOT NULL COMMENT 'MIME type (e.g., application/pdf, image/jpeg)',
  `uploaded_by` int unsigned DEFAULT NULL COMMENT 'User who uploaded the file (NULL for public submissions)',
  `created` datetime DEFAULT NULL COMMENT 'File upload timestamp',
  PRIMARY KEY (`id`),
  KEY `idx_pqrs_id` (`pqrs_id`),
  KEY `idx_comment_id` (`comment_id`),
  KEY `idx_uploaded_by` (`uploaded_by`),
  CONSTRAINT `fk_pqrs_attachments_comment` FOREIGN KEY (`comment_id`) REFERENCES `pqrs_comments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pqrs_attachments_pqrs` FOREIGN KEY (`pqrs_id`) REFERENCES `pqrs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pqrs_attachments_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE TABLE `pqrs_history` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `pqrs_id` int unsigned NOT NULL COMMENT 'Reference to the PQRS that was modified',
  `changed_by` int unsigned NOT NULL COMMENT 'User who made the change',
  `field_name` varchar(50) NOT NULL COMMENT 'Name of the field that was changed',
  `old_value` text COMMENT 'Previous value before change',
  `new_value` text COMMENT 'New value after change',
  `description` varchar(255) DEFAULT NULL COMMENT 'Human-readable description of the change',
  `created` datetime DEFAULT NULL COMMENT 'When the change occurred',
  PRIMARY KEY (`id`),
  KEY `idx_pqrs_id` (`pqrs_id`),
  KEY `idx_changed_by` (`changed_by`),
  KEY `idx_created` (`created`),
  KEY `idx_field_name` (`field_name`),
  KEY `idx_pqrs_created` (`pqrs_id`,`created`),
  CONSTRAINT `fk_pqrs_history_pqrs` FOREIGN KEY (`pqrs_id`) REFERENCES `pqrs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pqrs_history_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
);

INSERT INTO `system_settings` VALUES (1,'system_title','Mesa de Ayuda','string','System title displayed in UI and emails','2026-01-05 14:43:39','2026-03-05 09:31:16'),(2,'gmail_client_secret_path','/var/www/html/config/google/client_secret.json','string','Path to Gmail OAuth2 client secret JSON file','2026-01-05 14:43:39','2026-01-07 14:15:38'),(3,'gmail_refresh_token',NULL,'encrypted','Gmail OAuth2 refresh token (encrypted, auto-generated)','2026-01-05 14:43:39','2026-03-07 10:16:56'),(4,'gmail_user_email',NULL,'string','Gmail account email for ticket import','2026-01-05 14:43:39','2026-01-05 14:43:39'),(5,'gmail_check_interval',NULL,'integer','Email check interval in minutes','2026-01-05 14:43:39','2026-03-05 09:31:16'),(6,'whatsapp_api_url',NULL,'string','WhatsApp Evolution API base URL (e.g., http://localhost:8080)','2026-01-05 14:43:39','2026-02-14 10:46:08'),(9,'whatsapp_tickets_number',NULL,'string','WhatsApp number/group for ticket notifications (format: 5511999999999@s.whatsapp.net or groupid@g.us)','2026-01-05 14:43:39','2026-02-14 10:46:08'),(10,'whatsapp_pqrs_number',NULL,'string','WhatsApp number/group for PQRS notifications (format: 5511999999999@s.whatsapp.net or groupid@g.us)','2026-01-05 14:43:39','2026-02-14 10:46:08'),(11,'whatsapp_compras_number',NULL,'string','WhatsApp number/group for purchase request notifications (format: 5511999999999@s.whatsapp.net or groupid@g.us)','2026-01-05 14:43:39','2026-02-14 10:46:08'),(12,'n8n_webhook_url',NULL,'string','n8n webhook URL for AI tag classification and automation','2026-01-05 14:43:39','2026-01-07 15:11:15'),(13,'n8n_enabled','0','boolean','Enable/disable n8n webhook integration','2026-01-05 14:43:39','2026-03-05 09:31:16'),(14,'tickets_per_page','25','integer','Number of tickets to display per page','2026-01-05 14:43:39','2026-01-05 14:43:39'),(15,'pqrs_per_page','25','integer','Number of PQRS to display per page','2026-01-05 14:43:39','2026-01-05 14:43:39'),(16,'compras_per_page','25','integer','Number of purchase requests to display per page','2026-01-05 14:43:39','2026-01-05 14:43:39'),(17,'sla_pqrs_peticion_first_response_days','2','integer','SLA para primera respuesta en PQRS tipo Petición (días)','2026-01-05 14:43:47','2026-01-05 14:43:47'),(18,'sla_pqrs_peticion_resolution_days','5','integer','SLA para resolución en PQRS tipo Petición (días)','2026-01-05 14:43:47','2026-01-05 14:43:47'),(19,'sla_pqrs_queja_first_response_days','1','integer','SLA para primera respuesta en PQRS tipo Queja (días)','2026-01-05 14:43:47','2026-01-05 14:43:47'),(20,'sla_pqrs_queja_resolution_days','3','integer','SLA para resolución en PQRS tipo Queja (días)','2026-01-05 14:43:47','2026-01-05 14:43:47'),(21,'sla_pqrs_reclamo_first_response_days','1','integer','SLA para primera respuesta en PQRS tipo Reclamo (días)','2026-01-05 14:43:47','2026-01-05 14:43:47'),(22,'sla_pqrs_reclamo_resolution_days','3','integer','SLA para resolución en PQRS tipo Reclamo (días)','2026-01-05 14:43:47','2026-01-05 14:43:47'),(23,'sla_pqrs_sugerencia_first_response_days','3','integer','SLA para primera respuesta en PQRS tipo Sugerencia (días)','2026-01-05 14:43:47','2026-01-05 14:43:47'),(24,'sla_pqrs_sugerencia_resolution_days','7','integer','SLA para resolución en PQRS tipo Sugerencia (días)','2026-01-05 14:43:47','2026-01-05 14:43:47'),(25,'sla_compras_first_response_days','1','integer','SLA para primera respuesta en Compras (días)','2026-01-05 14:43:47','2026-01-05 14:43:47'),(26,'sla_compras_resolution_days','3','integer','SLA para resolución en Compras (días)','2026-01-05 14:43:47','2026-01-05 14:43:47'),(27,'n8n_api_key','','string',NULL,'2026-01-06 09:36:37','2026-01-07 15:11:15'),(28,'n8n_send_tags_list','0','string',NULL,'2026-01-06 09:36:37','2026-03-05 09:31:16'),(29,'n8n_timeout','10','string',NULL,'2026-01-06 09:36:38','2026-01-07 15:11:15'),(30,'whatsapp_enabled','0','string',NULL,'2026-01-06 09:36:38','2026-03-05 09:31:16'),(31,'whatsapp_api_key',NULL,'string',NULL,'2026-02-14 10:08:58','2026-02-14 10:46:08'),(32,'whatsapp_instance_name',NULL,'string',NULL,'2026-02-14 10:08:58','2026-02-14 10:46:08');

INSERT INTO `email_templates` VALUES (1,'nuevo_ticket','[Ticket #{{ticket_number}}] {{subject}}','<!DOCTYPE html>\r\n<html>\r\n<head>\r\n    <meta charset=\"UTF-8\">\r\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\r\n    <style>\r\n        @import url(\'https://fonts.googleapis.com/css2?family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&display=swap\');\r\n         td {\r\n             padding: 0px;\r\n         }\r\n     </style>\r\n</head>\r\n<body style=\"font-size: 16px !important; font-family: \'Google Sans\', -apple-system, BlinkMacSystemFont, \'Segoe UI\', sans-serif;\">\r\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\">\r\n        <tr>\r\n            <td align=\"center\">\r\n                <table width=\"600\" cellpadding=\"20\" cellspacing=\"0\" border=\"0\">\r\n                    <!-- Logo -->\r\n                    <tr>\r\n                        <td align=\"left\">\r\n                            <img style=\"border-radius: 8px; height: 55px;\"\r\n                                 src=\"https://www.copcsa.com/wp-content/uploads/2026/01/soporte-interno.png\">\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Saludo -->\r\n                    <tr>\r\n                        <td>\r\n                            <p>Hola <strong>{{requester_name}}</strong>,</p>\r\n                            <p>Hemos recibido tu solicitud y hemos creado un ticket para darle seguimiento. La solicitud puede ser redirigida a la organización de compras.</p>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Detalles del Ticket -->\r\n                    <tr>\r\n                        <td>\r\n                            <p><strong>Detalles de tu ticket:</strong></p>\r\n                            <ul>\r\n                                <li><strong>Número:</strong> {{ticket_number}}</li>\r\n                                <li><strong>Asunto:</strong> {{subject}}</li>\r\n                                <li><strong>Fecha de creación:</strong> {{created_date}}</li>\r\n                            </ul>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Mensaje de cierre -->\r\n                    <tr>\r\n                        <td>\r\n                            <p>Recibirás notificaciones sobre el estado de tu solicitud en este correo electrónico.</p>\r\n                            <p>Gracias por contactarnos.</p>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Footer -->\r\n                    <tr>\r\n                        <td align=\"center\">\r\n                            <hr>\r\n                            <p><em>Este es un correo automático. Por favor no respondas a este mensaje.</em></p>\r\n                        </td>\r\n                    </tr>\r\n                </table>\r\n            </td>\r\n        </tr>\r\n    </table>\r\n</body>\r\n</html>\r\n','[\"ticket_number\",\"subject\",\"requester_name\",\"created_date\",\"ticket_url\",\"system_title\"]',1,'2026-01-05 14:43:39','2026-01-05 15:55:48'),(2,'ticket_estado','[Ticket #{{ticket_number}}] Cambio de estado','<!DOCTYPE html>\r\n<html>\r\n<head>\r\n    <meta charset=\"UTF-8\">\r\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\r\n    <style>\r\n        @import url(\'https://fonts.googleapis.com/css2?family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&display=swap\');\r\n         td {\r\n             padding: 0px;\r\n         }\r\n     </style>\r\n</head>\r\n<body style=\"font-size: 16px !important; font-family: \'Google Sans\', -apple-system, BlinkMacSystemFont, \'Segoe UI\', sans-serif;\">\r\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\">\r\n        <tr>\r\n            <td align=\"center\">\r\n                <table width=\"600\" cellpadding=\"20\" cellspacing=\"0\" border=\"0\">\r\n                    <!-- Logo -->\r\n                    <tr>\r\n                        <td align=\"left\">\r\n                            <img style=\"border-radius: 8px; height: 55px;\"\r\n                                 src=\"https://www.copcsa.com/wp-content/uploads/2026/01/soporte-interno.png\">\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Saludo -->\r\n                    <tr>\r\n                        <td>\r\n                            <p>Hola <strong>{{requester_name}}</strong>,</p>\r\n                            <p>El estado de tu ticket ha sido actualizado.</p>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Detalles del Ticket -->\r\n                    <tr>\r\n                        <td>\r\n                            <p><strong>Detalles de tu ticket:</strong></p>\r\n                            <ul>\r\n                                <li><strong>Número:</strong> {{ticket_number}}</li>\r\n                                <li><strong>Asunto:</strong> {{subject}}</li>\r\n                                <li><strong>Fecha de actualización:</strong> {{update_date}}</li>\r\n                                {{status_change_section}}\r\n                            </ul>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Mensaje de cierre -->\r\n                    <tr>\r\n                        <td>\r\n                            <p>Recibirás notificaciones sobre el estado de tu solicitud en este correo electrónico.</p>\r\n                            <p>Gracias por contactarnos.</p>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Footer -->\r\n                    <tr>\r\n                        <td align=\"center\">\r\n                            <hr>\r\n                            <p><em>Este es un correo automático. Por favor no respondas a este mensaje.</em></p>\r\n                        </td>\r\n                    </tr>\r\n                </table>\r\n            </td>\r\n        </tr>\r\n    </table>\r\n</body>\r\n</html>\r\n','[\"ticket_number\",\"subject\",\"requester_name\",\"status_change_section\",\"ticket_url\",\"system_title\"]',1,'2026-01-05 14:43:39','2026-01-06 08:21:12'),(3,'nuevo_comentario','[Ticket #{{ticket_number}}] Nuevo comentario','<!DOCTYPE html>\r\n<html>\r\n<head>\r\n    <meta charset=\"UTF-8\">\r\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\r\n    <style>\r\n        @import url(\'https://fonts.googleapis.com/css2?family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&display=swap\');\r\n         td {\r\n             padding: 0px;\r\n         }\r\n     </style>\r\n</head>\r\n<body style=\"font-size: 16px !important; font-family: \'Google Sans\', -apple-system, BlinkMacSystemFont, \'Segoe UI\', sans-serif;\">\r\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\">\r\n        <tr>\r\n            <td align=\"center\">\r\n                <table width=\"600\" cellpadding=\"20\" cellspacing=\"0\" border=\"0\">\r\n                    <!-- Logo -->\r\n                    <tr>\r\n                        <td align=\"left\">\r\n                            <img style=\"border-radius: 8px; height: 55px;\"\r\n                                 src=\"https://www.copcsa.com/wp-content/uploads/2026/01/soporte-interno.png\">\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Saludo -->\r\n                    <tr>\r\n                        <td>\r\n                            <p>Hola <strong>{{requester_name}}</strong>,</p>\r\n                            <p>El estado de tu ticket ha sido actualizado.</p>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Detalles del Ticket -->\r\n                    <tr>\r\n                        <td>\r\n                            <p><strong>Detalles de tu ticket:</strong></p>\r\n                            <ul>\r\n                                <li><strong>Número:</strong> {{ticket_number}}</li>\r\n                                <li><strong>Asunto:</strong> {{subject}}</li>\r\n                                <li><strong>Fecha de actualización:</strong> {{update_date}}</li>\r\n                            </ul>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <tr>\r\n                        <td>\r\n                            <p style=\"display:flex;align-items: center; gap: 8px;\"><img style=\"border-radius: 9999px;\" src=\"{{agent_profile_image_url}}\" width=40 />\r\n<strong style=\"margin: auto 8px;\" >{{comment_author}}</strong></p>\r\n                            <p style=\"background-color:#f8f9fa; padding:15px; border-left:4px solid #8f5736; margin:10px 0\">{{comment_body}}</p>\r\n                        </td>\r\n                    </tr>\r\n                    {{attachments_list}}\r\n\r\n                    <!-- Mensaje de cierre -->\r\n                    <tr>\r\n                        <td>\r\n                            <p>Recibirás notificaciones sobre el estado de tu solicitud en este correo electrónico.</p>\r\n                            <p>Gracias por contactarnos.</p>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Footer -->\r\n                    <tr>\r\n                        <td align=\"center\">\r\n                            <hr>\r\n                            <p><em>Este es un correo automático. Por favor no respondas a este mensaje.</em></p>\r\n                        </td>\r\n                    </tr>\r\n                </table>\r\n            </td>\r\n        </tr>\r\n    </table>\r\n</body>\r\n</html>','[\"ticket_number\",\"subject\",\"comment_author\",\"comment_body\",\"attachments_list\",\"ticket_url\",\"agent_profile_image_url\",\"agent_name\",\"system_tit         le\"]',1,'2026-01-05 14:43:39','2026-01-07 15:31:08'),(4,'ticket_respuesta','[Ticket #{{ticket_number}}] Respuesta del agente','<!DOCTYPE html>\r\n<html>\r\n<head>\r\n    <meta charset=\"UTF-8\">\r\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\r\n    <style>\r\n        @import url(\'https://fonts.googleapis.com/css2?family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&display=swap\');\r\n         td {\r\n             padding: 0px;\r\n         }\r\n     </style>\r\n</head>\r\n<body style=\"font-size: 16px !important; font-family: \'Google Sans\', -apple-system, BlinkMacSystemFont, \'Segoe UI\', sans-serif;\">\r\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\">\r\n        <tr>\r\n            <td align=\"center\">\r\n                <table width=\"600\" cellpadding=\"20\" cellspacing=\"0\" border=\"0\">\r\n                    <!-- Logo -->\r\n                    <tr>\r\n                        <td align=\"left\">\r\n                            <img style=\"border-radius: 8px; height: 55px;\"\r\n                                 src=\"https://www.copcsa.com/wp-content/uploads/2026/01/soporte-interno.png\">\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Saludo -->\r\n                    <tr>\r\n                        <td>\r\n                            <p>Hola <strong>{{requester_name}}</strong>,</p>\r\n                            <p>El estado de tu ticket ha sido actualizado.</p>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Detalles del Ticket -->\r\n                    <tr>\r\n                        <td>\r\n                            <p><strong>Detalles de tu ticket:</strong></p>\r\n                            <ul>\r\n                                <li><strong>Número:</strong> {{ticket_number}}</li>\r\n                                <li><strong>Asunto:</strong> {{subject}}</li>\r\n                                <li><strong>Fecha de actualización:</strong> {{update_date}}</li>\r\n                            </ul>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <tr>\r\n                        {{status_change_section}}\r\n                    </tr>\r\n                    {{attachments_list}}\r\n\r\n                    <tr>\r\n                        <td>\r\n                            <p><strong>Respuesta de \r\n{{comment_author}}</strong></p>\r\n                            <p style=\"background-color:#f8f9fa; padding:15px; border-left:4px solid #8f5736; margin:10px 0\">{{comment_body}}</p>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Mensaje de cierre -->\r\n                    <tr>\r\n                        <td>\r\n                            <p>Recibirás notificaciones sobre el estado de tu solicitud en este correo electrónico.</p>\r\n                            <p>Gracias por contactarnos.</p>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Footer -->\r\n                    <tr>\r\n                        <td align=\"center\">\r\n                            <hr>\r\n                            <p><em>Este es un correo automático. Por favor no respondas a este mensaje.</em></p>\r\n                        </td>\r\n                    </tr>\r\n                </table>\r\n            </td>\r\n        </tr>\r\n    </table>\r\n</body>\r\n</html>','[\"ticket_number\",\"subject\",\"requester_name\",\"comment_author\",\"comment_body\",\"attachments_list\",\"status_change_section\",\"ticket_url\",\"agent_profile_image_url\",\"agent_name\",\"system_title\"]',1,'2026-01-05 14:43:39','2026-01-06 10:00:54'),(5,'nueva_compra','[Compra #{{compra_number}}] {{subject}}','<!DOCTYPE html>\r\n<html>\r\n<head>\r\n    <meta charset=\"UTF-8\">\r\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\r\n    <style>\r\n        @import url(\'https://fonts.googleapis.com/css2?family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&display=swap\');\r\n         td {\r\n             padding: 0px;\r\n         }\r\n     </style>\r\n</head>\r\n<body style=\"font-size: 16px !important; font-family: \'Google Sans\', -apple-system, BlinkMacSystemFont, \'Segoe UI\', sans-serif;\">\r\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\">\r\n        <tr>\r\n            <td align=\"center\">\r\n                <table width=\"600\" cellpadding=\"20\" cellspacing=\"0\" border=\"0\">\r\n                    <!-- Logo -->\r\n                    <tr>\r\n                        <td align=\"left\">\r\n                            <img style=\"border-radius: 8px; height: 55px;\"\r\n                                 src=\"https://www.copcsa.com/wp-content/uploads/2026/01/compras.png\">\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Saludo -->\r\n                    <tr>\r\n                        <td>\r\n                            <p>Hola <strong>{{requester_name}}</strong>,</p>\r\n                            <p>Hemos recibido tu solicitud y hemos creado un ticket para darle seguimiento. </p>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Detalles del Ticket -->\r\n                    <tr>\r\n                        <td>\r\n                            <p><strong>Detalles de tu ticket:</strong></p>\r\n                            <ul>\r\n                                <li><strong>Número:</strong> {{compra_number}}</li>\r\n                                <li><strong>Prioridad:</strong> {{priority}}</li>\r\n                                <li><strong>SLA:</strong> {{sla_due_date}}</li>\r\n                                <li><strong>Asunto:</strong> {{subject}}</li>\r\n                                <li><strong>Fecha de creación:</strong> {{created_date}}</li>\r\n                            </ul>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Mensaje de cierre -->\r\n                    <tr>\r\n                        <td>\r\n                            <p>Recibirás notificaciones sobre el estado de tu solicitud en este correo electrónico.</p>\r\n                            <p>Gracias por contactarnos.</p>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Footer -->\r\n                    <tr>\r\n                        <td align=\"center\">\r\n                            <hr>\r\n                            <p><em>Este es un correo automático. Por favor no respondas a este mensaje.</em></p>\r\n                        </td>\r\n                    </tr>\r\n                </table>\r\n            </td>\r\n        </tr>\r\n    </table>\r\n</body>\r\n</html>','[\"compra_number\",\"subject\",\"requester_name\",\"assignee_name\",\"priority\",\"sla_due_date\",\"created_date\",\"compra_url\",\"system_title\"]',1,'2026-01-05 14:43:44','2026-01-06 09:23:39'),(6,'compra_estado','[Compra #{{compra_number}}] Cambio de estado','<!DOCTYPE html>\r\n<html>\r\n<head>\r\n    <meta charset=\"UTF-8\">\r\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\r\n    <style>\r\n        @import url(\'https://fonts.googleapis.com/css2?family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&display=swap\');\r\n         td {\r\n             padding: 0px;\r\n         }\r\n     </style>\r\n</head>\r\n<body style=\"font-size: 16px !important; font-family: \'Google Sans\', -apple-system, BlinkMacSystemFont, \'Segoe UI\', sans-serif;\">\r\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\">\r\n        <tr>\r\n            <td align=\"center\">\r\n                <table width=\"600\" cellpadding=\"20\" cellspacing=\"0\" border=\"0\">\r\n                    <!-- Logo -->\r\n                    <tr>\r\n                        <td align=\"left\">\r\n                            <img style=\"border-radius: 8px; height: 55px;\"\r\n                                 src=\"https://www.copcsa.com/wp-content/uploads/2026/01/compras.png\">\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Saludo -->\r\n                    <tr>\r\n                        <td>\r\n                            <p>Hola <strong>{{requester_name}}</strong>,</p>\r\n                            <p>El estado de tu ticket ha sido actualizado.</p>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Detalles del Ticket -->\r\n                    <tr>\r\n                        <td>\r\n                            <p><strong>Detalles de tu ticket:</strong></p>\r\n                            <ul>\r\n                                <li><strong>Número:</strong> {{compra_number}}</li>\r\n                                <li><strong>Asunto:</strong> {{subject}}</li>\r\n                                <li><strong>Fecha de actualización:</strong> {{update_date}}</li>\r\n                                {{status_change_section}}\r\n                            </ul>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Mensaje de cierre -->\r\n                    <tr>\r\n                        <td>\r\n                            <p>Recibirás notificaciones sobre el estado de tu solicitud en este correo electrónico.</p>\r\n                            <p>Gracias por contactarnos.</p>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Footer -->\r\n                    <tr>\r\n                        <td align=\"center\">\r\n                            <hr>\r\n                            <p><em>Este es un correo automático. Por favor no respondas a este mensaje.</em></p>\r\n                        </td>\r\n                    </tr>\r\n                </table>\r\n            </td>\r\n        </tr>\r\n    </table>\r\n</body>\r\n</html>','[\"compra_number\",\"subject\",\"requester_name\",\"status_change_section\",\"compra_url\",\"system_title\"]',1,'2026-01-05 14:43:44','2026-01-06 09:24:45'),(7,'compra_comentario','[Compra #{{compra_number}}] Nuevo comentario','<!DOCTYPE html>\r\n<html>\r\n<head>\r\n    <meta charset=\"UTF-8\">\r\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\r\n    <style>\r\n        @import url(\'https://fonts.googleapis.com/css2?family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&display=swap\');\r\n         td {\r\n             padding: 0px;\r\n         }\r\n     </style>\r\n</head>\r\n<body style=\"font-size: 16px !important; font-family: \'Google Sans\', -apple-system, BlinkMacSystemFont, \'Segoe UI\', sans-serif;\">\r\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\">\r\n        <tr>\r\n            <td align=\"center\">\r\n                <table width=\"600\" cellpadding=\"20\" cellspacing=\"0\" border=\"0\">\r\n                    <!-- Logo -->\r\n                    <tr>\r\n                        <td align=\"left\">\r\n                            <img style=\"border-radius: 8px; height: 55px;\"\r\n                                 src=\"https://www.copcsa.com/wp-content/uploads/2026/01/soporte-interno.png\">\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Saludo -->\r\n                    <tr>\r\n                        <td>\r\n                            <p>Hola <strong>{{requester_name}}</strong>,</p>\r\n                            <p>El estado de tu ticket ha sido actualizado.</p>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Detalles del Ticket -->\r\n                    <tr>\r\n                        <td>\r\n                            <p><strong>Detalles de tu ticket:</strong></p>\r\n                            <ul>\r\n                                <li><strong>Número:</strong> {{compra_number}}</li>\r\n                                <li><strong>Asunto:</strong> {{subject}}</li>\r\n                                <li><strong>Fecha de actualización:</strong> {{update_date}}</li>\r\n                            </ul>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <tr>\r\n                        <td>\r\n                            <p><strong>Respuesta de \r\n{{comment_author}}</strong></p>\r\n                            <p style=\"background-color:#f8f9fa; padding:15px; border-left:4px solid #8f5736; margin:10px 0\">{{comment_body}}</p>\r\n                        </td>\r\n                    </tr>\r\n                    {{attachments_list}}\r\n\r\n                    <!-- Mensaje de cierre -->\r\n                    <tr>\r\n                        <td>\r\n                            <p>Recibirás notificaciones sobre el estado de tu solicitud en este correo electrónico.</p>\r\n                            <p>Gracias por contactarnos.</p>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Footer -->\r\n                    <tr>\r\n                        <td align=\"center\">\r\n                            <hr>\r\n                            <p><em>Este es un correo automático. Por favor no respondas a este mensaje.</em></p>\r\n                        </td>\r\n                    </tr>\r\n                </table>\r\n            </td>\r\n        </tr>\r\n    </table>\r\n</body>\r\n</html>','[\"compra_number\",\"subject\",\"requester_name\",\"comment_author\",\"comment_body\",\"attachments_list\",\"compra_url\",\"agent_profile_image_url\",\"agent_name\",\"system_title\"]',1,'2026-01-05 14:43:44','2026-01-06 09:43:08'),(8,'compra_respuesta','[Compra #{{compra_number}}] Respuesta del agente','<!DOCTYPE html>\r\n<html>\r\n<head>\r\n    <meta charset=\"UTF-8\">\r\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\r\n    <style>\r\n        @import url(\'https://fonts.googleapis.com/css2?family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&display=swap\');\r\n         td {\r\n             padding: 0px;\r\n         }\r\n     </style>\r\n</head>\r\n<body style=\"font-size: 16px !important; font-family: \'Google Sans\', -apple-system, BlinkMacSystemFont, \'Segoe UI\', sans-serif;\">\r\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\">\r\n        <tr>\r\n            <td align=\"center\">\r\n                <table width=\"600\" cellpadding=\"20\" cellspacing=\"0\" border=\"0\">\r\n                    <!-- Logo -->\r\n                    <tr>\r\n                        <td align=\"left\">\r\n                            <img style=\"border-radius: 8px; height: 55px;\"\r\n                                 src=\"https://www.copcsa.com/wp-content/uploads/2026/01/compras.png\">\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Saludo -->\r\n                    <tr>\r\n                        <td>\r\n                            <p>Hola <strong>{{requester_name}}</strong>,</p>\r\n                            <p>El estado de tu compra ha sido actualizado.</p>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Detalles del Ticket -->\r\n                    <tr>\r\n                        <td>\r\n                            <p><strong>Detalles de tu compra:</strong></p>\r\n                            <ul>\r\n                                <li><strong>Número:</strong> {{compra_number}}</li>\r\n                                <li><strong>Asunto:</strong> {{subject}}</li>\r\n                                <li><strong>Fecha de actualización:</strong> {{update_date}}</li>\r\n                                {{status_change_section}}\r\n                            </ul>\r\n                        </td>\r\n                    </tr>\r\n                    {{attachments_list}}\r\n\r\n                    <tr>\r\n                        <td>\r\n                            <p><strong>Respuesta de \r\n{{comment_author}}</strong></p>\r\n                            <p style=\"background-color:#f8f9fa; padding:15px; border-left:4px solid #8f5736; margin:10px 0\">{{comment_body}}</p>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Mensaje de cierre -->\r\n                    <tr>\r\n                        <td>\r\n                            <p>Recibirás notificaciones sobre el estado de tu solicitud en este correo electrónico.</p>\r\n                            <p>Gracias por contactarnos.</p>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Footer -->\r\n                    <tr>\r\n                        <td align=\"center\">\r\n                            <hr>\r\n                            <p><em>Este es un correo automático. Por favor no respondas a este mensaje.</em></p>\r\n                        </td>\r\n                    </tr>\r\n                </table>\r\n            </td>\r\n        </tr>\r\n    </table>\r\n</body>\r\n</html>','[\"compra_number\",\"subject\",\"requester_name\",\"comment_author\",\"comment_body\",\"attachments_list\",\"status_change_section\",\"compra_url\",\"agent_profile_image_url\",\"agent_name\",\"system_title\"]',1,'2026-01-05 14:43:44','2026-01-06 09:50:03'),(9,'nuevo_pqrs','[PQRS #{{pqrs_number}}] {{subject}}','<!DOCTYPE html>\r\n<html>\r\n<head>\r\n    <meta charset=\"UTF-8\">\r\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\r\n    <style>\r\n        @import url(\'https://fonts.googleapis.com/css2?family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&display=swap\');\r\n         td {\r\n             padding: 0px;\r\n         }\r\n     </style>\r\n</head>\r\n<body style=\"font-size: 16px !important; font-family: \'Google Sans\', -apple-system, BlinkMacSystemFont, \'Segoe UI\', sans-serif;\">\r\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\">\r\n        <tr>\r\n            <td align=\"center\">\r\n                <table width=\"600\" cellpadding=\"20\" cellspacing=\"0\" border=\"0\">\r\n                    <!-- Logo -->\r\n                    <tr>\r\n                        <td align=\"left\">\r\n                            <img style=\"border-radius: 8px; height: 55px;\"\r\n                                 src=\"https://www.copcsa.com/wp-content/uploads/2026/01/servicioalcliente.png\">\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Saludo -->\r\n                    <tr>\r\n                        <td>\r\n                            <p>Hola <strong>{{requester_name}}</strong>,</p>\r\n                            <p>Hemos recibido tu solicitud y hemos creado un ticket para darle seguimiento. La solicitud puede ser redirigida a la organización de compras.</p>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Detalles del Ticket -->\r\n                    <tr>\r\n                        <td>\r\n                            <p><strong>Detalles de tu ticket:</strong></p>\r\n                            <ul>\r\n                                <li><strong>Número:</strong> {{pqrs_number}}</li>\r\n                                <li><strong>Número:</strong> {{pqrs_type}}</li>\r\n                                <li><strong>Asunto:</strong> {{subject}}</li>\r\n                                <li><strong>Fecha de creación:</strong> {{created_date}}</li>\r\n                            </ul>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Mensaje de cierre -->\r\n                    <tr>\r\n                        <td>\r\n                            <p>Recibirás notificaciones sobre el estado de tu solicitud en este correo electrónico.</p>\r\n                            <p>Gracias por contactarnos.</p>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Footer -->\r\n                    <tr>\r\n                        <td align=\"center\">\r\n                            <hr>\r\n                            <p><em>Este es un correo automático. Por favor no respondas a este mensaje.</em></p>\r\n                        </td>\r\n                    </tr>\r\n                </table>\r\n            </td>\r\n        </tr>\r\n    </table>\r\n</body>\r\n</html>\r\n','[\"pqrs_number\",\"pqrs_type\",\"subject\",\"requester_name\",\"created_date\",\"system_title\"]',1,'2026-01-05 14:43:46','2026-01-06 08:16:22'),(10,'pqrs_estado','[PQRS #{{pqrs_number}}] Cambio de estado','<!DOCTYPE html>\r\n<html>\r\n<head>\r\n    <meta charset=\"UTF-8\">\r\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\r\n    <style>\r\n        @import url(\'https://fonts.googleapis.com/css2?family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&display=swap\');\r\n         td {\r\n             padding: 0px;\r\n         }\r\n     </style>\r\n</head>\r\n<body style=\"font-size: 16px !important; font-family: \'Google Sans\', -apple-system, BlinkMacSystemFont, \'Segoe UI\', sans-serif;\">\r\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\">\r\n        <tr>\r\n            <td align=\"center\">\r\n                <table width=\"600\" cellpadding=\"20\" cellspacing=\"0\" border=\"0\">\r\n                    <!-- Logo -->\r\n                    <tr>\r\n                        <td align=\"left\">\r\n                            <img style=\"border-radius: 8px; height: 55px;\"\r\n                                 src=\"https://www.copcsa.com/wp-content/uploads/2026/01/servicialcliente.png\">\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Saludo -->\r\n                    <tr>\r\n                        <td>\r\n                            <p>Hola <strong>{{requester_name}}</strong>,</p>\r\n                            <p>El estado de tu solicitud ha sido actualizado.</p>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Detalles del Ticket -->\r\n                    <tr>\r\n                        <td>\r\n                            <p><strong>Detalles de tu solicitud:</strong></p>\r\n                            <ul>\r\n                                <li><strong>Número:</strong> {{pqrs_number}}</li>\r\n                                <li><strong>Asunto:</strong> {{subject}}</li>\r\n                                <li><strong>Tipo:</strong> {{pqrs_type}}</li>\r\n                                <li><strong>Fecha de actualización:</strong> {{update_date}}</li>\r\n                                {{status_change_section}}\r\n                            </ul>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Mensaje de cierre -->\r\n                    <tr>\r\n                        <td>\r\n                            <p>Recibirás notificaciones sobre el estado de tu solicitud en este correo electrónico.</p>\r\n                            <p>Gracias por contactarnos.</p>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Footer -->\r\n                    <tr>\r\n                        <td align=\"center\">\r\n                            <hr>\r\n                            <p><em>Este es un correo automático. Por favor no respondas a este mensaje.</em></p>\r\n                        </td>\r\n                    </tr>\r\n                </table>\r\n            </td>\r\n        </tr>\r\n    </table>\r\n</body>\r\n</html>','[\"pqrs_number\",\"pqrs_type\",\"subject\",\"requester_name\",\"status_change_section\",\"system_title\"]',1,'2026-01-05 14:43:46','2026-01-06 09:52:57'),(11,'pqrs_comentario','[PQRS #{{pqrs_number}}] Nuevo comentario','<!DOCTYPE html>\r\n<html>\r\n<head>\r\n    <meta charset=\"UTF-8\">\r\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\r\n    <style>\r\n        @import url(\'https://fonts.googleapis.com/css2?family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&display=swap\');\r\n         td {\r\n             padding: 0px;\r\n         }\r\n     </style>\r\n</head>\r\n<body style=\"font-size: 16px !important; font-family: \'Google Sans\', -apple-system, BlinkMacSystemFont, \'Segoe UI\', sans-serif;\">\r\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\">\r\n        <tr>\r\n            <td align=\"center\">\r\n                <table width=\"600\" cellpadding=\"20\" cellspacing=\"0\" border=\"0\">\r\n                    <!-- Logo -->\r\n                    <tr>\r\n                        <td align=\"left\">\r\n                            <img style=\"border-radius: 8px; height: 55px;\"\r\n                                 src=\"https://www.copcsa.com/wp-content/uploads/2026/01/servicioalcliente.png\">\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Saludo -->\r\n                    <tr>\r\n                        <td>\r\n                            <p>Hola <strong>{{requester_name}}</strong>,</p>\r\n                            <p>El estado de tu solicitud ha sido actualizada.</p>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Detalles del Ticket -->\r\n                    <tr>\r\n                        <td>\r\n                            <p><strong>Detalles de tu solicitud:</strong></p>\r\n                            <ul>\r\n                                <li><strong>Número:</strong> {{pqrs_number}}</li>\r\n                                <li><strong>Asunto:</strong> {{subject}}</li>\r\n                                <li><strong>Fecha de actualización:</strong> {{update_date}}</li>\r\n                            </ul>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <tr>\r\n                        <td>\r\n                            <p><strong>Respuesta de \r\n{{comment_author}}</strong></p>\r\n                            <p style=\"background-color:#f8f9fa; padding:15px; border-left:4px solid #8f5736; margin:10px 0\">{{comment_body}}</p>\r\n                        </td>\r\n                    </tr>\r\n                    {{attachments_list}}\r\n\r\n                    <!-- Mensaje de cierre -->\r\n                    <tr>\r\n                        <td>\r\n                            <p>Recibirás notificaciones sobre el estado de tu solicitud en este correo electrónico.</p>\r\n                            <p>Gracias por contactarnos.</p>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Footer -->\r\n                    <tr>\r\n                        <td align=\"center\">\r\n                            <hr>\r\n                            <p><em>Este es un correo automático. Por favor no respondas a este mensaje.</em></p>\r\n                        </td>\r\n                    </tr>\r\n                </table>\r\n            </td>\r\n        </tr>\r\n    </table>\r\n</body>\r\n</html>','[\"pqrs_number\",\"subject\",\"comment_author\",\"comment_body\",\"attachments_list\",\"agent_profile_image_url\",\"agent_name\",\"system_title\"]',1,'2026-01-05 14:43:46','2026-01-06 09:54:49'),(12,'pqrs_respuesta','[PQRS #{{pqrs_number}}] Respuesta del equipo','<!DOCTYPE html>\r\n<html>\r\n<head>\r\n    <meta charset=\"UTF-8\">\r\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\r\n    <style>\r\n        @import url(\'https://fonts.googleapis.com/css2?family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&display=swap\');\r\n         td {\r\n             padding: 0px;\r\n         }\r\n     </style>\r\n</head>\r\n<body style=\"font-size: 16px !important; font-family: \'Google Sans\', -apple-system, BlinkMacSystemFont, \'Segoe UI\', sans-serif;\">\r\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\">\r\n        <tr>\r\n            <td align=\"center\">\r\n                <table width=\"600\" cellpadding=\"20\" cellspacing=\"0\" border=\"0\">\r\n                    <!-- Logo -->\r\n                    <tr>\r\n                        <td align=\"left\">\r\n                            <img style=\"border-radius: 8px; height: 55px;\"\r\n                                 src=\"https://www.copcsa.com/wp-content/uploads/2026/01/servicioalcliente.png\">\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Saludo -->\r\n                    <tr>\r\n                        <td>\r\n                            <p>Hola <strong>{{requester_name}}</strong>,</p>\r\n                            <p>El estado de tu solicitud ha sido actualizada.</p>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Detalles del Ticket -->\r\n                    <tr>\r\n                        <td>\r\n                            <p><strong>Detalles de tu solicitud:</strong></p>\r\n                            <ul>\r\n                                <li><strong>Número:</strong> {{pqrs_number}}</li>\r\n                                <li><strong>Asunto:</strong> {{subject}}</li>\r\n                                <li><strong>Tipo:</strong> {{pqrs_type}}</li>\r\n                                <li><strong>Fecha de actualización:</strong> {{update_date}}</li>\r\n                                {{status_change_section}}\r\n                            </ul>\r\n                        </td>\r\n                    </tr>\r\n                    {{attachments_list}}\r\n\r\n                    <tr>\r\n                        <td>\r\n                            <p><strong>Respuesta de \r\n{{comment_author}}</strong></p>\r\n                            <p style=\"background-color:#f8f9fa; padding:15px; border-left:4px solid #8f5736; margin:10px 0\">{{comment_body}}</p>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Mensaje de cierre -->\r\n                    <tr>\r\n                        <td>\r\n                            <p>Recibirás notificaciones sobre el estado de tu solicitud en este correo electrónico.</p>\r\n                            <p>Gracias por contactarnos.</p>\r\n                        </td>\r\n                    </tr>\r\n\r\n                    <!-- Footer -->\r\n                    <tr>\r\n                        <td align=\"center\">\r\n                            <hr>\r\n                            <p><em>Este es un correo automático. Por favor no respondas a este mensaje.</em></p>\r\n                        </td>\r\n                    </tr>\r\n                </table>\r\n            </td>\r\n        </tr>\r\n    </table>\r\n</body>\r\n</html>','[\"pqrs_number\",\"pqrs_type\",\"subject\",\"requester_name\",\"comment_author\",\"comment_body\",\"attachments_list\",\"status_change_section\",\"agent_profile_image_url\",\"agent_name\",\"system_title\"]',1,'2026-01-05 14:43:46','2026-01-06 09:56:30');