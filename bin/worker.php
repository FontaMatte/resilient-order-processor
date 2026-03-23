<?php

/**
 * Script di avvio del Payment Worker.
 *
 * Questo processo gira in background, separato dall'API web.
 * Legge i messaggi dalla coda RabbitMQ e processa i pagamenti.
 *
 * Uso: docker compose exec app php bin/worker.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Service\Database;
use App\Service\QueueService;
use App\Service\PaymentProcessor;

echo "Starting Payment Worker...\n";

$pdo = Database::getConnection();
$queueService = new QueueService(
    host: getenv('RABBITMQ_HOST') ?: 'rabbitmq',
    port: (int) (getenv('RABBITMQ_PORT') ?: 5672),
    user: getenv('RABBITMQ_USER') ?: 'guest',
    password: getenv('RABBITMQ_PASSWORD') ?: 'guest'
);

$processor = new PaymentProcessor($pdo, $queueService);
$processor->run();