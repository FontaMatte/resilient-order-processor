<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Service\Database;
use App\Controller\OrderController;
use App\Service\QueueService;

$app = AppFactory::create();
$app->addErrorMiddleware(true, true, true);
$app->addBodyParsingMiddleware();

// Health check
$app->get('/health', function (Request $request, Response $response): Response {
    $dbHealthy = Database::isHealthy();

    $queueService = new QueueService(
        host: getenv('RABBITMQ_HOST') ?: 'rabbitmq',
        port: (int) (getenv('RABBITMQ_PORT') ?: 5672),
        user: getenv('RABBITMQ_USER') ?: 'guest',
        password: getenv('RABBITMQ_PASSWORD') ?: 'guest'
    );
    $rabbitHealthy = $queueService->isHealthy();

    $allHealthy = $dbHealthy && $rabbitHealthy;

    $data = [
        'status' => $allHealthy ? 'ok' : 'degraded',
        'timestamp' => date('c'),
        'service' => 'resilient-order-processor',
        'checks' => [
            'database' => $dbHealthy ? 'ok' : 'failing',
            'rabbitmq' => $rabbitHealthy ? 'ok' : 'failing',
        ]
    ];

    $statusCode = $allHealthy ? 200 : 503;
    $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus($statusCode);
});

// Lista prodotti
$app->get('/products', function (Request $request, Response $response): Response {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare('SELECT id, name, price, stock FROM products ORDER BY id');
    $stmt->execute();
    $products = $stmt->fetchAll();

    $response->getBody()->write(json_encode($products, JSON_PRETTY_PRINT));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});

// Crea ordine
$app->post('/orders', [new OrderController(), 'create']);

$app->run();
