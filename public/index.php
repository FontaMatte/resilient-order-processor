<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Service\Database;
use App\Controller\OrderController;
use App\Service\QueueService;
use App\Middleware\RateLimitMiddleware;
use App\Service\Metrics;

$app = AppFactory::create();
$app->addErrorMiddleware(true, true, true);
$app->addBodyParsingMiddleware();

// Rate limiting: max 30 richieste al minuto per IP
$app->add(new RateLimitMiddleware(maxRequests: 30, windowSeconds: 60));

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

$app->get('/orders/list', function (Request $request, Response $response): Response {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare(
        'SELECT o.*, p.name as product_name
         FROM orders o
         JOIN products p ON o.product_id = p.id
         ORDER BY o.created_at DESC
         LIMIT 20'
    );
    $stmt->execute();
    $orders = $stmt->fetchAll();

    $response->getBody()->write(json_encode(['orders' => $orders], JSON_PRETTY_PRINT));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});

$app->get('/orders/{id}', function (Request $request, Response $response, array $args): Response {
    $pdo = Database::getConnection();

    $stmt = $pdo->prepare(
        'SELECT o.*, p.name as product_name 
        FROM orders o 
        JOIN products p ON o.product_id = p.id 
        WHERE o.id = ?'
    );
    $stmt->execute([$args['id']]);
    $order = $stmt->fetch();

    if ($order === false) {
        $data = ['error' => ['message' => 'Order not found', 'code' => 404]];
        $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
    }

    $data = [
        'order' => [
            'id' => $order['id'],
            'product_id' => (int) $order['product_id'],
            'product_name' => $order['product_name'],
            'quantity' => (int) $order['quantity'],
            'total_price' => $order['total_price'],
            'status' => $order['status'],
            'failure_reason' => $order['failure_reason'],
            'created_at' => $order['created_at'],
            'updated_at' => $order['updated_at'],
        ]
    ];

    $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
});

$app->get('/metrics', function (Request $request, Response $response): Response {
    $metrics = Metrics::getAll();

    $response->getBody()->write(json_encode($metrics, JSON_PRETTY_PRINT));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});

$app->run();
