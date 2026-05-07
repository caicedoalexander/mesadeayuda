<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * NumberGenerationService
 *
 * Generates sequential ticket numbers in the format TKT-YYYY-NNNNN.
 */
class NumberGenerationService
{
    use LocatorAwareTrait;

    /**
     * Generate the next sequential ticket number.
     *
     * @return string Generated number (e.g., TKT-2026-00001)
     */
    public function generate(): string
    {
        $year = date('Y');
        $prefix = "TKT-{$year}-";

        $table = $this->fetchTable('Tickets');

        $last = $table->find()
            ->select(['ticket_number'])
            ->where(['ticket_number LIKE' => "{$prefix}%"])
            ->orderBy(['ticket_number' => 'DESC'])
            ->first();

        $sequence = 1;
        if ($last) {
            $parts = explode('-', $last->ticket_number);
            $sequence = (int)end($parts) + 1;
        }

        return $prefix . str_pad((string)$sequence, 5, '0', STR_PAD_LEFT);
    }
}
