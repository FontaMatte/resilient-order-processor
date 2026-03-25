<?php

declare(strict_types=1);

namespace App\Service;

use Exception;
use App\Service\Logger;

class RetryService
{
    public function __construct(
        private int $maxAttempts = 3,
        private int $baseDelayMs = 200,
        private float $jitterFactor = 0.5
    ) {}

    public function execute(callable $operation, string $operationName = 'operation'): mixed
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {

            try {
                $result = $operation();

                if ($attempt > 1) {
                    Logger::getInstance()->info('Operation succeeded after retry', [
                        'operation' => $operationName,
                        'attempt' => $attempt,
                        'max_attempts' => $this->maxAttempts,
                    ]);
                }

                return $result;

            } catch (\Exception $e) {
                $lastException = $e;

                if ($attempt === $this->maxAttempts) {
                    Logger::getInstance()->error('Operation failed after all attempts', [
                        'operation' => $operationName,
                        'attempts_made' => $this->maxAttempts,
                        'last_error' => $e->getMessage(),
                    ]);
                    break;
                }

                $delay = $this->calculateDelay($attempt);

                Logger::getInstance()->warning('Retry attempt failed', [
                    'operation' => $operationName,
                    'attempt' => $attempt,
                    'max_attempts' => $this->maxAttempts,
                    'error' => $e->getMessage(),
                    'retry_delay_ms' => $delay,
                ]);

                usleep($delay * 1000);
            }
        }

        throw $lastException;
    }

    private function calculateDelay(int $attempt): int
    {
        $exponentialDelay = $this->baseDelayMs * (2 ** ($attempt - 1));

        $minFactor = 1.0 - $this->jitterFactor;
        $maxFactor = 1.0 + $this->jitterFactor;
        $jitter = $minFactor + (mt_rand() / mt_getrandmax()) * ($maxFactor - $minFactor);

        return (int) ($exponentialDelay * $jitter);
    }
}