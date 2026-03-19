<?php

declare(strict_types=1);

namespace App\Service;

use Exception;

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
                    error_log(sprintf(
                        '[RETRY] %s succeeded on attempt %d/%d',
                        $operationName, $attempt, $this->maxAttempts
                    ));
                }

                return $result;

            } catch (\Exception $e) {
                $lastException = $e;

                if ($attempt === $this->maxAttempts) {
                    error_log(sprintf(
                        '[RETRY] %s FAILED after %d attempts. Last error: %s',
                        $operationName, $this->maxAttempts, $e->getMessage()
                    ));
                    break;
                }

                $delay = $this->calculateDelay($attempt);

                error_log(sprintf(
                    '[RETRY] %s attempt %d/%d failed (%s). Retrying in %dms...',
                    $operationName, $attempt, $this->maxAttempts, $e->getMessage(), $delay
                ));

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