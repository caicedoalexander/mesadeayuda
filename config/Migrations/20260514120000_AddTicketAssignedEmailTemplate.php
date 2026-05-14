<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddTicketAssignedEmailTemplate extends BaseMigration
{
    /**
     * Seeds the default `ticket_asignacion` email template used by
     * TicketNotificationListener::onAssigned() to notify a newly assigned
     * agent. Idempotent: skipped if a template with the same key already
     * exists (admin may have created it manually).
     *
     * Audit reference: docs/audits/2026-05-14-tickets-module-audit.md (HIGH-2).
     */
    public function up(): void
    {
        $existing = $this->fetchRow(
            "SELECT id FROM email_templates WHERE template_key = 'ticket_asignacion' LIMIT 1",
        );
        if ($existing) {
            return;
        }

        $availableVariables = json_encode([
            'ticket_number',
            'subject',
            'requester_name',
            'assignee_name',
            'ticket_url',
            'system_title',
        ], JSON_THROW_ON_ERROR);

        $bodyHtml = <<<'HTML'
<p>Hola {{assignee_name}},</p>
<p>Se te ha asignado el ticket <strong>#{{ticket_number}}</strong>: {{subject}}.</p>
<p>Solicitante: {{requester_name}}.</p>
<p><a href="{{ticket_url}}">Ver ticket</a></p>
<p style="color:#6c757d;font-size:12px;margin-top:24px;">{{system_title}}</p>
HTML;

        $this->table('email_templates')
            ->insert([
                'template_key' => 'ticket_asignacion',
                'subject' => 'Ticket #{{ticket_number}} asignado: {{subject}}',
                'body_html' => $bodyHtml,
                'available_variables' => $availableVariables,
                'is_active' => 1,
                'created' => date('Y-m-d H:i:s'),
                'modified' => date('Y-m-d H:i:s'),
            ])
            ->saveData();
    }

    public function down(): void
    {
        $this->execute("DELETE FROM email_templates WHERE template_key = 'ticket_asignacion'");
    }
}
