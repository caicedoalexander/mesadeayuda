<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class ConsolidateLegacyTicketStatuses extends BaseMigration
{
    /**
     * Consolida tickets con estados legacy (convertido/cerrado/en_progreso)
     * en `resuelto`. Estados eliminados como parte de Críticos 1-3.
     *
     * Spec: docs/superpowers/specs/2026-05-07-criticos-1-3-design.md §3 Fase B.5
     */
    public function up(): void
    {
        $this->execute("
            UPDATE tickets
            SET status = 'resuelto',
                resolved_at = COALESCE(resolved_at, modified)
            WHERE status IN ('convertido', 'cerrado', 'en_progreso')
        ");
    }

    public function down(): void
    {
        throw new \RuntimeException(
            'ConsolidateLegacyTicketStatuses is not reversible. Restore from backup.',
        );
    }
}
