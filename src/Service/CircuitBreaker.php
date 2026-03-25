<?php

declare(strict_types=1);

namespace App\Service;

class CircuitBreaker
{
    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half_open';

    private string $state = self::STATE_CLOSED;
    private int $failureCount = 0;
    private float $lastFailureTime = 0;

    public function __construct(
        private int $failureThreshold = 5,
        private float $recoveryTimeout = 30.0,
        private string $stateFile = '/tmp/circuit_breaker_state.json'
    ) {
        $this->loadState();
    }

    public function execute(callable $operation): mixed
    {
        if ($this->state === self::STATE_OPEN) {
            if ($this->shouldAttemptRecovery()) {
                $this->state = self::STATE_HALF_OPEN;
                $this->saveState();
                error_log('[CIRCUIT-BREAKER] State: OPEN → HALF_OPEN (attempting recovery)');
            } else {
                $remainingSeconds = (int) ($this->recoveryTimeout - (microtime(true) - $this->lastFailureTime));
                error_log(sprintf(
                    '[CIRCUIT-BREAKER] OPEN — rejecting call (retry in %ds)',
                    $remainingSeconds
                ));
                throw new CircuitBreakerOpenException(
                    'Circuit breaker is OPEN. Service is unavailable.'
                );
            }
        }

        try {
            $result = $operation();
            $this->onSuccess();
            return $result;

        } catch (\Exception $e) {
            $this->onFailure();
            throw $e;
        }
    }

    private function onSuccess(): void
    {
        if ($this->state === self::STATE_HALF_OPEN) {
            error_log('[CIRCUIT-BREAKER] State: HALF_OPEN → CLOSED (service recovered)');
        }

        $this->failureCount = 0;
        $this->state = self::STATE_CLOSED;
        $this->saveState();
    }

    private function onFailure(): void
    {
        $this->failureCount++;
        $this->lastFailureTime = microtime(true);

        if ($this->state === self::STATE_HALF_OPEN) {
            $this->state = self::STATE_OPEN;
            $this->saveState();
            error_log('[CIRCUIT-BREAKER] State: HALF_OPEN → OPEN (recovery failed)');
            return;
        }

        if ($this->failureCount >= $this->failureThreshold) {
            $this->state = self::STATE_OPEN;
            error_log(sprintf(
                '[CIRCUIT-BREAKER] State: CLOSED → OPEN (threshold %d reached)',
                $this->failureThreshold
            ));
        } else {
            error_log(sprintf(
                '[CIRCUIT-BREAKER] Failure %d/%d',
                $this->failureCount,
                $this->failureThreshold
            ));
        }

        $this->saveState();
    }

    private function shouldAttemptRecovery(): bool
    {
        return (microtime(true) - $this->lastFailureTime) >= $this->recoveryTimeout;
    }

    /**
     * Salva lo stato su file.
     * In produzione useresti Redis per condividere lo stato tra processi.
     * Per il nostro caso, un file è sufficiente.
     */
    private function saveState(): void
    {
        $data = [
            'state' => $this->state,
            'failure_count' => $this->failureCount,
            'last_failure_time' => $this->lastFailureTime,
        ];

        file_put_contents($this->stateFile, json_encode($data));
    }

    /**
     * Carica lo stato dal file. Se il file non esiste, parte da CLOSED.
     */
    private function loadState(): void
    {
        if (!file_exists($this->stateFile)) {
            return;
        }

        $data = json_decode(file_get_contents($this->stateFile), true);

        if (is_array($data)) {
            $this->state = $data['state'] ?? self::STATE_CLOSED;
            $this->failureCount = $data['failure_count'] ?? 0;
            $this->lastFailureTime = $data['last_failure_time'] ?? 0;
        }
    }

    public function getState(): string
    {
        if ($this->state === self::STATE_OPEN && $this->shouldAttemptRecovery()) {
            return self::STATE_HALF_OPEN;
        }
        return $this->state;
    }

    public function getFailureCount(): int
    {
        return $this->failureCount;
    }
}