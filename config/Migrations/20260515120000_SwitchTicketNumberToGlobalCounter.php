<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Cambia ticket_number a un contador global que arranca en 1000.
 *
 * Contexto: la migración 20260509120000_AddTicketNumberSequencesTable
 * estableció un contador atómico por año (TKT-YYYY-NNNNN). A partir de
 * 2026-05-15 los tickets nuevos se numeran como entero plano monótono
 * empezando en 1000 ("1000", "1001", ...). Los tickets ya emitidos se
 * conservan intactos: la columna es VARCHAR y ningún número existente
 * es un entero plano, así que ambos formatos coexisten sin colisión.
 *
 * Estrategia:
 *   - TRUNCATE: descarta las filas de contador por año (eran historial
 *     vivo solo mientras se usaba el formato anual; ya no aplican).
 *   - INSERT (year=0, last_seq=999): siembra el contador global. La
 *     columna se llama "year" por compatibilidad con el schema previo;
 *     el valor 0 representa "contador global post-2026-05-15".
 *
 * El primer INSERT ... ON DUPLICATE KEY UPDATE ejecutado por
 * NumberGenerationService sube last_seq a 1000 y devuelve "1000".
 *
 * Rollback (down): TRUNCATE. Solo es seguro ANTES de emitir tickets
 * nuevos. Después de emitir, revertir requiere intervención manual
 * (recalcular MAX numérico). Ver spec 2026-05-15.
 */
class SwitchTicketNumberToGlobalCounter extends BaseMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        $this->execute('TRUNCATE TABLE ticket_number_sequences');
        $this->execute(
            'INSERT INTO ticket_number_sequences (year, last_seq) VALUES (0, 999)',
        );
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $this->execute('TRUNCATE TABLE ticket_number_sequences');
    }
}
