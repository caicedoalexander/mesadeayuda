<?php
declare(strict_types=1);

namespace App\Service\Exception;

use RuntimeException;

/**
 * Thrown when an attempt is made to transition a ticket to a status
 * that is not allowed by the domain state machine.
 */
class InvalidStatusTransitionException extends RuntimeException
{
    /**
     * @param string $from Current status
     * @param string $to Attempted target status
     */
    public static function for(string $from, string $to): self
    {
        return new self(sprintf(
            'Invalid ticket status transition: "%s" -> "%s"',
            $from,
            $to,
        ));
    }
}
