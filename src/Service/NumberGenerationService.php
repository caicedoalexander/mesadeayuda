<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\Locator\LocatorAwareTrait;
use RuntimeException;

/**
 * NumberGenerationService
 *
 * Genera identificadores secuenciales de ticket en formato TKT-YYYY-NNNNN.
 *
 * Implementación atómica vía la tabla {@see ticket_number_sequences}: una sola
 * sentencia INSERT ... ON DUPLICATE KEY UPDATE incrementa el contador del año
 * y deja el nuevo valor disponible vía LAST_INSERT_ID() en la misma sesión MySQL.
 * Esto elimina la race condition que tenía la versión read-then-format previa.
 */
class NumberGenerationService
{
    use LocatorAwareTrait;

    private const SEQUENCE_TABLE = 'ticket_number_sequences';

    /**
     * Genera el siguiente número de ticket secuencial para el año actual.
     *
     * @return string Número generado (e.g., TKT-2026-00001)
     * @throws \RuntimeException si el contador no devuelve una secuencia válida
     */
    public function generate(): string
    {
        $year = (int)date('Y');

        return self::formatNumber($year, $this->allocateSequence($year));
    }

    /**
     * Reserva atómicamente la siguiente secuencia para el año dado.
     *
     * Usa la idiom MySQL: LAST_INSERT_ID(expr) fija el valor de sesión a expr,
     * tanto en la rama INSERT (primer ticket del año) como en la rama
     * ON DUPLICATE KEY UPDATE (años con tickets previos). Una vez fijado,
     * SELECT LAST_INSERT_ID() devuelve la secuencia recién asignada.
     */
    private function allocateSequence(int $year): int
    {
        $connection = $this->fetchTable('Tickets')->getConnection();

        $connection->execute(
            'INSERT INTO ' . self::SEQUENCE_TABLE . ' (year, last_seq) '
            . 'VALUES (:year, LAST_INSERT_ID(1)) '
            . 'ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq + 1)',
            ['year' => $year],
            ['year' => 'integer'],
        );

        $row = $connection->execute('SELECT LAST_INSERT_ID() AS seq')->fetch('assoc');
        $sequence = (int)($row['seq'] ?? 0);

        if ($sequence < 1) {
            throw new RuntimeException(
                'Failed to allocate ticket number sequence for year ' . $year,
            );
        }

        return $sequence;
    }

    /**
     * Formatea un par (año, secuencia) al string canónico TKT-YYYY-NNNNN.
     *
     * Expuesto como static para facilitar tests y reutilización (e.g., bootstrap).
     */
    public static function formatNumber(int $year, int $sequence): string
    {
        return sprintf('TKT-%d-%05d', $year, $sequence);
    }
}
