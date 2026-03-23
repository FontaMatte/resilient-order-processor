<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/**
 * Processa i pagamenti leggendo i messaggi dalla coda RabbitMQ.
 *
 * In un sistema reale, qui chiameresti un gateway di pagamento (Stripe, PayPal, ecc.).
 * Noi simuliamo il pagamento con una probabilità di successo del 90%.
 *
 * Questo è il "consumer" nella terminologia delle code di messaggi:
 *   Producer (la nostra API) → Queue (RabbitMQ) → Consumer (questo file)
 */
class PaymentProcessor
{
    public function __construct(
        private PDO $pdo,
        private QueueService $queueService,
        private float $successRate = 0.9
    ) {}

    /**
     * Avvia il consumer: ascolta la coda e processa i messaggi.
     *
     * basic_consume dice a RabbitMQ: "mandami i messaggi appena arrivano".
     * È diverso dal polling (chiedere periodicamente "ci sono messaggi?"):
     * con basic_consume, RabbitMQ PUSH i messaggi al consumer.
     * Più efficiente e con latenza più bassa.
     */
    public function run(): void
    {
        $channel = $this->queueService->getChannel();

        error_log('[PAYMENT] Worker started. Waiting for messages...');

        // basic_consume registra una "callback" sulla coda.
        // Quando arriva un messaggio, RabbitMQ chiama la nostra funzione.
        // Parametri:
        //   - queue: nome della coda
        //   - consumer_tag ('') : identificativo del consumer ('' = generato da RabbitMQ)
        //   - no_local (false): ricevi anche messaggi pubblicati da questa connessione
        //   - no_ack (false): IMPORTANTE! false = devo fare ack manualmente.
        //     Se fosse true, RabbitMQ cancellerebbe il messaggio appena consegnato,
        //     anche se il nostro codice crolla prima di processarlo. Con false,
        //     il messaggio resta in coda finché non facciamo ack esplicito.
        //   - exclusive (false): altri consumer possono leggere dalla stessa coda
        //   - nowait (false): aspetta la conferma da RabbitMQ
        //   - callback: la funzione da chiamare quando arriva un messaggio
        $channel->basic_consume(
            QueueService::QUEUE_PAYMENTS,
            '',
            false,
            false,   // no_ack = false → ack manuale (fondamentale per la resilienza!)
            false,
            false,
            function ($msg) {
                $this->processMessage($msg);
            }
        );

        // wait() blocca il processo e aspetta messaggi.
        // Il ciclo while gira finché il canale ha consumer attivi.
        while ($channel->is_consuming()) {
            $channel->wait();
        }
    }


    /**
     * Processa un singolo messaggio dalla coda.
     */
    public function processMessage($msg): void
    {
        $payload = json_decode($msg->getBody(), true);
        $orderId = $payload['order_id'] ?? 'unknown';

        error_log(sprintf('[PAYMENT] Processing payment for order %s...', $orderId));

        try {
            // Simula il tempo di processamento (come una vera chiamata a Stripe)
            usleep(random_int(100, 500) * 1000);

            // Simula successo/fallimento del pagamento
            $success = random_int(1, 100) <= (int) ($this->successRate * 100);

            if ($success) {
                $this->updateOrderStatus($orderId, 'completed');
                error_log(sprintf('[PAYMENT] Order %s completed successfully', $orderId));
            } else {
                $this->updateOrderStatus($orderId, 'failed', 'Payment declined by provider');
                error_log(sprintf('[PAYMENT] Order %s payment failed', $orderId));
            }

            // ACK: "ho processato il messaggio, puoi cancellarlo".
            // Questo è il momento critico: facciamo ack SOLO dopo aver aggiornato
            // il database. Se il processo crolla PRIMA di questa riga,
            // RabbitMQ rimette il messaggio in coda → verrà riprocessato.
            // Se crolla DOPO, il messaggio è stato cancellato ma l'ordine
            // è già aggiornato → tutto ok.
            $msg->ack();

        } catch (\Exception $e) {
            error_log(sprintf(
                '[PAYMENT] Error processing order %s: %s',
                $orderId,
                $e->getMessage()
            ));

            // NACK: "non sono riuscito a processare il messaggio".
            // Il terzo parametro (true) dice a RabbitMQ: "rimettilo in coda".
            // Così verrà riconsegnato per un nuovo tentativo.
            // In un sistema più avanzato, dopo N tentativi falliti
            // manderesti il messaggio in una "dead letter queue" (coda dei messaggi
            // impossibili da processare) per analisi manuale.
            $msg->nack(false, true);
        }
    }

    /**
     * Aggiorna lo stato di un ordine nel database.
     */
    private function updateOrderStatus(
        string $orderId,
        string $status, 
        ?string $failureReason = null
    ): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE orders
            SET status = ?, failure_reason = ?, updated_at = NOW()
            WHERE id = ?'
        );
        $stmt->execute([$status, $failureReason, $orderId]);
    }
} 