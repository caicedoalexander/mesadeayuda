<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\Locator\LocatorAwareTrait;
use RuntimeException;

/**
 * NumberGenerationService
 *
 * Genera identificadores secuenciales de ticket como entero plano monótono
 * empezando en 1000 ("1000", "1001", "1002", ...).
 *
 * Implementación atómica vía la tabla {@see ticket_number_sequences}, fila
 * global (year=0): una sola sentencia INSERT ... ON DUPLICATE KEY UPDATE
 * incrementa el contador y deja el nuevo valor disponible vía
 * LAST_INSERT_ID() en la misma sesión MySQL. Esto evita la race condition
 * que tenía la versión read-then-format previa.
 *
 * La fila global se siembra con last_seq=999 por la migration
 * 20260515120000_SwitchTicketNumberToGlobalCounter, por lo que la primera
 * llamada a generate() devuelve "1000".
 *
 * Los tickets emitidos antes de 2026-05-15 conservan su formato
 * TKT-YYYY-NNNNN; la columna ticket_number es VARCHAR y ambos formatos
 * coexisten sin colisión (ningún ticket previo es un entero plano).
 */
class NumberGenerationService
{
    use LocatorAwareTrait;

    private const SEQUENCE_TABLE = 'ticket_number_sequences';

    /**
     * Genera el siguiente número de ticket secuencial global.
     *
     * @return string Número generado (e.g., "1000")
     * @throws \RuntimeException si el contador no devuelve una secuencia válida
     */
    public function generate(): string
    {
        $connection = $this->fetchTable('Tickets')->getConnection();

        $connection->execute(
            'INSERT INTO ' . self::SEQUENCE_TABLE . ' (year, last_seq) '
            . 'VALUES (0, LAST_INSERT_ID(1)) '
            . 'ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq + 1)',
        );

        $row = $connection->execute('SELECT LAST_INSERT_ID() AS seq')->fetch('assoc');
        $sequence = (int)($row['seq'] ?? 0);

        if ($sequence < 1) {
            throw new RuntimeException('Failed to allocate ticket number sequence');
        }

        return (string)$sequence;
    }
}
