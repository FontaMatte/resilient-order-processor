<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Database;
use App\Service\InventoryService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Service\ExternalAvailabilityService;
use App\Service\ExternalServiceException;
use App\Service\RetryService;

class OrderController
{
    public function create(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();

        if (!is_array($body)) {
            return $this->jsonError($response, 'Request body must be valid JSON', 400);
        }

        $productId = $body['product_id'] ?? null;
        $quantity = $body['quantity'] ?? null;

        if ($productId === null || $quantity === null) {
            return $this->jsonError($response, 'Missing required fields: product_id and quantity', 422);
        }

        $productId = filter_var($productId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $quantity = filter_var($quantity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($productId === false) {
            return $this->jsonError($response, 'product_id must be a positive integer', 422);
        }
        if ($quantity === false) {
            return $this->jsonError($response, 'quantity must be a positive integer', 422);
        }

        $pdo = Database::getConnection();

        $stmt = $pdo->prepare('SELECT id, name, price, stock FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();

        if ($product === false) {
            return $this->jsonError($response, "Product with id {$productId} not found", 404);
        }

        // === STEP 3: Verifica disponibilità con servizio esterno + retry ===
        $availabilityService = new ExternalAvailabilityService(failureRate: 0.3);
        $retryService = new RetryService(maxAttempts: 3, baseDelayMs: 200);

        try {
            $availability = $retryService->execute(
                operation: fn() => $availabilityService->checkAvailability($productId, $quantity),
                operationName: 'availability_check'
            );

            if (!$availability['available']) {
                return $this->jsonError($response, 'Product not available in requested quantity', 409);
            }
            
        } catch (ExternalServiceException $e) {
            return $this->jsonError(
                $response,
                'Unable to verify product availability. Please try again later.',
                503
            );
        }

        // === STEP 4: Riserva stock + crea ordine + outbox (transazione atomica) ===
        $totalPrice = bcmul((string) $product['price'], (string) $quantity, 2);

        $inventoryService = new InventoryService($pdo);
        $result = $inventoryService->reserveAndCreateOrder($productId, $quantity, $totalPrice);

        if (!$result['success']) {
            return $this->jsonError($response, $result['error'], 409);
        }

        $order = $result['order'];

        $responseData = [
            'order' => [
                'id' => $order['id'],
                'product_id' => (int) $order['product_id'],
                'product_name' => $product['name'],
                'quantity' => (int) $order['quantity'],
                'total_price' => $order['total_price'],
                'status' => $order['status'],
                'created_at' => $order['created_at'],
            ],
        ];

        $response->getBody()->write(json_encode($responseData, JSON_PRETTY_PRINT));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(201);
    }

    private function jsonError(Response $response, string $message, int $statusCode): Response
    {
        $data = [
            'error' => [
                'message' => $message,
                'code' => $statusCode,
            ]
        ];

        $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($statusCode);
    }
}
