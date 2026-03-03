<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * NumberGenerationService
 *
 * Centralized generation of sequential entity numbers.
 * Format: {PREFIX}-{YEAR}-{SEQUENCE} (e.g., TKT-2026-00001)
 */
class NumberGenerationService
{
    use LocatorAwareTrait;

    /**
     * Entity prefix configuration
     *
     * @var array<string, array{table: string, column: string, prefix: string}>
     */
    private const ENTITY_CONFIG = [
        'ticket' => ['table' => 'Tickets', 'column' => 'ticket_number', 'prefix' => 'TKT'],
        'compra' => ['table' => 'Compras', 'column' => 'compra_number', 'prefix' => 'CPR'],
        'pqrs' => ['table' => 'Pqrs', 'column' => 'pqrs_number', 'prefix' => 'PQRS'],
    ];

    /**
     * Generate the next sequential number for an entity type
     *
     * @param string $entityType 'ticket', 'compra', or 'pqrs'
     * @return string Generated number (e.g., TKT-2026-00001)
     * @throws \InvalidArgumentException If entity type is not configured
     */
    public function generate(string $entityType): string
    {
        if (!isset(self::ENTITY_CONFIG[$entityType])) {
            throw new \InvalidArgumentException("Unknown entity type: {$entityType}");
        }

        $config = self::ENTITY_CONFIG[$entityType];
        $year = date('Y');
        $prefix = "{$config['prefix']}-{$year}-";

        $table = $this->fetchTable($config['table']);
        $column = $config['column'];

        $last = $table->find()
            ->select([$column])
            ->where(["{$column} LIKE" => "{$prefix}%"])
            ->orderBy([$column => 'DESC'])
            ->first();

        $sequence = 1;
        if ($last) {
            $parts = explode('-', $last->{$column});
            $sequence = (int)end($parts) + 1;
        }

        return $prefix . str_pad((string)$sequence, 5, '0', STR_PAD_LEFT);
    }
}
