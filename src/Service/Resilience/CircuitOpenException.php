<?php
declare(strict_types=1);

namespace App\Service\Resilience;

use RuntimeException;

/**
 * Thrown by ResilientHttpClient when the circuit breaker is OPEN for the
 * target host and the cooldown has not elapsed. Captured inside
 * SecureHttpTrait::secureCurlPost — does NOT escape to service callers.
 */
final class CircuitOpenException extends RuntimeException
{
    /**
     * @param string $host Host whose breaker is open.
     * @param int $secondsOpen Seconds elapsed since the breaker opened.
     */
    public function __construct(
        public readonly string $host,
        public readonly int $secondsOpen,
    ) {
        parent::__construct(sprintf(
            'Circuit breaker open for host "%s" (%d seconds since open).',
            $host,
            $secondsOpen,
        ));
    }
}
