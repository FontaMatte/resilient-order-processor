<?php

/**
 * ENTRY POINT dell'applicazione.
 * 
 * Ogni richiesta HTTP che arriva al nostro server passa da qui.
 * Questo file fa 3 cose:
 *   1. Carica l'autoloader di Composer (così possiamo usare "use" per importare classi)
 *   2. Crea l'applicazione Slim
 *   3. Definisce le route (gli endpoint API)
 *   4. Avvia l'applicazione (ascolta le richieste)
 * 
 * "declare(strict_types=1)" è una direttiva PHP che forza il type checking.
 * Senza: PHP converte silenziosamente "123" (stringa) in 123 (intero).
 * Con: PHP lancia un TypeError. Questo previene bug subdoli.
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// Crea l'app Slim.
// Slim è un "micro-framework": ti dà routing e gestione request/response,
// ma non ti obbliga a usare un ORM, un template engine, ecc.
// È l'opposto di Laravel che ti dà tutto preconfezionato.
$app = AppFactory::create();

// Middleware per gestione errori: converte le eccezioni in risposte HTTP.
// I 3 parametri sono:
//   - displayErrorDetails: mostra dettagli (true in dev, false in prod!)
//   - logErrors: scrive nel log
//   - logErrorDetails: scrive anche i dettagli nel log
$app->addErrorMiddleware(true, true, true);

// Middleware per il parsing del body JSON.
// Senza questo, quando mandi un POST con body JSON,
// $request->getParsedBody() restituirebbe null.
$app->addBodyParsingMiddleware();

// ============================================================
// ROUTE: HEALTH CHECK
// ============================================================
// Questo endpoint serve a verificare che il sistema sia vivo.
// In produzione, i load balancer e i sistemi di monitoring
// chiamano /health ogni pochi secondi. Se non risponde 200,
// il container viene riavviato automaticamente (es. Kubernetes).
//
// Per ora controlla solo che l'app risponda. Nelle fasi successive
// aggiungeremo il check della connessione a PostgreSQL e RabbitMQ.
$app->get('/health', function (Request $request, Response $response): Response {
    $data = [
        'status' => 'ok',
        'timestamp' => date('c'),  // Formato ISO 8601: "2026-03-17T14:30:00+00:00"
        'service' => 'resilient-order-processor',
        'checks' => [
            'database' => 'not_checked',   // Lo implementeremo nella Fase 2
            'rabbitmq' => 'not_checked',   // Lo implementeremo nella Fase 6
        ]
    ];

    $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});

// Avvia l'app: inizia ad ascoltare le richieste HTTP.
$app->run();
