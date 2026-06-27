<?php
declare(strict_types=1);

namespace App\Service\Resilience;

use Cake\Cache\Cache;
use Cake\Log\Log;

/**
 * Circuit breaker per remote host.
 *
 * States: closed (normal), open (rejecting), half_open (probing).
 * State is persisted in CakePHP cache so it is shared across PHP-FPM workers.
 *
 * Two workers may race on read/write; the loss is bounded (one extra request
 * may slip through before the breaker opens). No distributed locking — the
 * cost would exceed the value for this use case.
 */
final class CircuitBreaker
{
    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half_open';

    /**
     * @param string $cacheConfig Cache config name used to persist breaker state.
     * @param int $failureThreshold Failures before the breaker opens.
     * @param int $cooldownSeconds Seconds the breaker stays open before probing.
     */
    public function __construct(
        private readonly string $cacheConfig,
        private readonly int $failureThreshold = 5,
        private readonly int $cooldownSeconds = 30,
    ) {
    }

    /**
     * @param string $host Remote host to check.
     * @return bool True when a request may proceed.
     */
    public function isAvailable(string $host): bool
    {
        $state = $this->readState($host);

        if ($state['state'] === self::STATE_CLOSED) {
            return true;
        }

        if ($state['state'] === self::STATE_OPEN) {
            $elapsed = time() - (int)$state['openedAt'];
            if ($elapsed < $this->cooldownSeconds) {
                return false;
            }
            $this->writeState($host, [
                'state' => self::STATE_HALF_OPEN,
                'failures' => $state['failures'],
                'openedAt' => $state['openedAt'],
            ]);
            Log::info('circuit_breaker.half_open', ['host' => $host, 'opened_at' => $state['openedAt']]);

            return true;
        }

        return true;
    }

    /**
     * @param string $host Remote host that responded successfully.
     * @return void
     */
    public function recordSuccess(string $host): void
    {
        $state = $this->readState($host);
        if ($state['state'] !== self::STATE_CLOSED || $state['failures'] > 0) {
            if ($state['state'] !== self::STATE_CLOSED) {
                Log::info('circuit_breaker.closed', ['host' => $host]);
            }
            $this->writeState($host, [
                'state' => self::STATE_CLOSED,
                'failures' => 0,
                'openedAt' => null,
            ]);
        }
    }

    /**
     * @param string $host Remote host that failed.
     * @return void
     */
    public function recordFailure(string $host): void
    {
        $state = $this->readState($host);
        $failures = $state['failures'] + 1;

        if ($state['state'] === self::STATE_HALF_OPEN || $failures >= $this->failureThreshold) {
            $this->writeState($host, [
                'state' => self::STATE_OPEN,
                'failures' => $failures,
                'openedAt' => time(),
            ]);
            Log::error('circuit_breaker.opened', ['host' => $host, 'failures' => $failures]);

            return;
        }

        $this->writeState($host, [
            'state' => self::STATE_CLOSED,
            'failures' => $failures,
            'openedAt' => null,
        ]);
    }

    /**
     * @return int Seconds since this host's breaker opened (0 if not open).
     */
    public function secondsOpen(string $host): int
    {
        $state = $this->readState($host);
        if ($state['state'] !== self::STATE_OPEN || $state['openedAt'] === null) {
            return 0;
        }

        return max(0, time() - (int)$state['openedAt']);
    }

    /**
     * @return array{state: string, failures: int, openedAt: int|null}
     */
    private function readState(string $host): array
    {
        $data = Cache::read($this->keyFor($host), $this->cacheConfig);
        if (!is_array($data) || !isset($data['state'])) {
            return ['state' => self::STATE_CLOSED, 'failures' => 0, 'openedAt' => null];
        }

        return [
            'state' => (string)$data['state'],
            'failures' => (int)($data['failures'] ?? 0),
            'openedAt' => isset($data['openedAt']) ? (int)$data['openedAt'] : null,
        ];
    }

    /**
     * @param array{state: string, failures: int, openedAt: int|null} $state
     */
    private function writeState(string $host, array $state): void
    {
        Cache::write($this->keyFor($host), $state, $this->cacheConfig);
    }

    /**
     * @param string $host Remote host to build a cache key for.
     * @return string Cache key for this host's breaker state.
     */
    private function keyFor(string $host): string
    {
        return 'cb_' . preg_replace('/[^a-z0-9_.-]/i', '_', $host);
    }
}
