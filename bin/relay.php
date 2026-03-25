<?php

/**
 * Script di avvio dell'Outbox Relay.
 *
 * Questo processo gira in background, separato dall'API web.
 * Legge la tabella outbox e invia i messaggi a RabbitMQ.
 *
 * Uso: docker compose exec app php bin/relay.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Service\Database;
use App\Service\QueueService;
use App\Service\OutboxRelay;

echo "Starting Outbox Relay...\n";

$pdo = Database::getConnection();
$queueService = new QueueService(
    host: getenv('RABBITMQ_HOST') ?: 'rabbitmq',
    port: (int) (getenv('RABBITMQ_PORT') ?: 5672),
    user: getenv('RABBITMQ_USER') ?: 'guest',
    password: getenv('RABBITMQ_PASSWORD') ?: 'guest'
);

$relay = new OutboxRelay($pdo, $queueService);
$relay->run();