<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * Middleware per il rate limiting basato su IP.
 *
 * Usa un file JSON per tracciare le richieste per IP.
 * In produzione si userebbe Redis (molto più veloce e scalabile).
 *
 * Implementa MiddlewareInterface (PSR-15): lo standard PHP per i middleware.
 * Il metodo process() riceve la richiesta e decide se passarla al prossimo
 * handler o bloccarla con 429.
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    /**
     * @param int    $maxRequests  Richieste massime per finestra temporale
     * @param int    $windowSeconds  Durata della finestra in secondi
     * @param string $storageFile  File per salvare i contatori
     */
    public function __construct(
        private int $maxRequests = 30,
        private int $windowSeconds = 60,
        private string $storageFile = '/tmp/rate_limit.json'
    ) {}

    /**
     * PSR-15: il metodo che Slim chiama per ogni richiesta.
     *
     * $handler->handle($request) passa la richiesta al prossimo middleware
     * o alla route. Se non lo chiamiamo, la richiesta non arriva mai alla route.
     */
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        // Identifica il client dall'IP
        $clientIp = $this->getClientIp($request);

        // Carica i dati di rate limiting
        $data = $this->loadData();

        // Pulisci le richieste scadute per questo IP
        $now = time();
        $windowStart = $now - $this->windowSeconds;

        if (!isset($data[$clientIp])) {
            $data[$clientIp] = [];
        }

        // Rimuovi i timestamp più vecchi della finestra
        $data[$clientIp] = array_values(array_filter(
            $data[$clientIp],
            fn(int $timestamp) => $timestamp > $windowStart
        ));

        // Controlla il limite
        if (count($data[$clientIp]) >= $this->maxRequests) {
            $this->saveData($data);

            $response = new SlimResponse();
            $retryAfter = $data[$clientIp][0] - $windowStart;

            $body = json_encode([
                'error' => [
                    'message' => 'Too many requests. Please slow down.',
                    'code' => 429,
                    'retry_after_seconds' => max(1, $retryAfter),
                ]
            ], JSON_PRETTY_PRINT);

            $response->getBody()->write($body);

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Retry-After', (string) max(1, $retryAfter))
                ->withStatus(429);
        }

        // Registra questa richiesta
        $data[$clientIp][] = $now;
        $this->saveData($data);

        // Passa la richiesta al prossimo handler (la route)
        $response = $handler->handle($request);

        // Aggiungi header informativi alla risposta
        $remaining = $this->maxRequests - count($data[$clientIp]);

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $this->maxRequests)
            ->withHeader('X-RateLimit-Remaining', (string) $remaining)
            ->withHeader('X-RateLimit-Window', $this->windowSeconds . 's');
    }

    private function getClientIp(Request $request): string
    {
        // In produzione controlleresti anche X-Forwarded-For
        // per i client dietro un reverse proxy.
        $serverParams = $request->getServerParams();
        return $serverParams['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    private function loadData(): array
    {
        if (!file_exists($this->storageFile)) {
            return [];
        }

        $content = file_get_contents($this->storageFile);
        return json_decode($content, true) ?: [];
    }

    private function saveData(array $data): void
    {
        file_put_contents($this->storageFile, json_encode($data));
    }
}