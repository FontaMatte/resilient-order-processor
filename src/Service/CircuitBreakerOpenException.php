<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

class CircuitBreakerOpenException extends RuntimeException
{
}