<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Tabla contador para generación atómica de ticket_number.
 *
 * Resuelve la race condition descrita en la auditoría 2026-05-09 (MA-007):
 * la generación previa leía MAX(ticket_number) y formateaba +1 sin transacción,
 * permitiendo que dos requests concurrentes (Gmail webhook + creación manual)
 * produjeran el mismo TKT-YYYY-NNNNN — capturado por el validador unique pero
 * descartado silenciosamente por la ingestion.
 *
 * El flujo nuevo (NumberGenerationService::generate) usa la idiom atómica:
 *   INSERT INTO ticket_number_sequences (year, last_seq) VALUES (:y, LAST_INSERT_ID(1))
 *   ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq + 1);
 *   SELECT LAST_INSERT_ID();
 *
 * Bootstrap: se inicializa el contador con el MAX(secuencia) de cada año
 * existente, de modo que la primera generación post-migración no colisione
 * con tickets ya creados.
 */
class AddTicketNumberSequencesTable extends BaseMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        $this->table('ticket_number_sequences', ['id' => false, 'primary_key' => ['year']])
            ->addColumn('year', 'integer', [
                'comment' => 'Año al que pertenecen las secuencias (e.g., 2026)',
                'default' => null,
                'limit' => null,
                'null' => false,
                'signed' => false,
            ])
            ->addColumn('last_seq', 'integer', [
                'comment' => 'Última secuencia asignada para este año (contador atómico)',
                'default' => 0,
                'limit' => null,
                'null' => false,
                'signed' => false,
            ])
            ->create();

        // Bootstrap: extrae YYYY (chars 5..8) y NNNNN (chars 10..) de cada ticket
        // existente con formato TKT-YYYY-NNNNN. GREATEST protege ante re-runs.
        $this->execute(
            'INSERT INTO ticket_number_sequences (year, last_seq) '
            . 'SELECT '
            . '    CAST(SUBSTRING(ticket_number, 5, 4) AS UNSIGNED) AS year, '
            . '    MAX(CAST(SUBSTRING(ticket_number, 10) AS UNSIGNED)) AS last_seq '
            . 'FROM tickets '
            . "WHERE ticket_number REGEXP '^TKT-[0-9]{4}-[0-9]+$' "
            . 'GROUP BY CAST(SUBSTRING(ticket_number, 5, 4) AS UNSIGNED) '
            . 'ON DUPLICATE KEY UPDATE last_seq = GREATEST(last_seq, VALUES(last_seq))',
        );
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $this->table('ticket_number_sequences')->drop()->save();
    }
}
