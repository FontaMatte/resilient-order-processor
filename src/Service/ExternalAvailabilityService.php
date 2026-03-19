<?php

declare(strict_types=1);

namespace App\Service;

class ExternalAvailabilityService
{
    public function __construct(
        private float $failureRate = 0.3,
        private int $maxLatencyMs = 200
    ) {}

    public function checkAvailability(int $productId, int $quantity): array
    {
        // 1. Simula la latenza di rete
        $latencyMs = random_int(50, $this->maxLatencyMs);
        usleep($latencyMs * 1000);

        // 2. Simula il fallimento casuale
        if (random_int(1, 100) <= (int) ($this->failureRate * 100)) {
            $errors = [
                'Connection timeout after 5000ms',
                'Service returned HTTP 503 (Service Unavailable)',
                'Connection refused: cannot reach warehouse service',
                'Service returned HTTP 500 (Internal Server Error)',
            ];

            throw new ExternalServiceException(
                "External availability service failed: " . $errors[array_rand($errors)]
            );
        }

        // 3. Se non fallisce, restituisce la disponibilità
        return [
            'available' => $quantity <= 100,
            'source' => 'external_warehouse',
            'checked_at' => date('c'),
            'latency_ms' => $latencyMs,
        ];

    }
}