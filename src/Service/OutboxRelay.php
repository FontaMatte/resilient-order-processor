<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/**
 * Legge i messaggi dalla tabella outbox e li invia a RabbitMQ.
 *
 * Questo è il "ponte" tra il database (affidabile) e RabbitMQ (che può essere down).
 * Gira come processo separato, in un loop infinito.
 *
 * Perché non inviare direttamente a RabbitMQ nel controller?
 * Perché l'invio a RabbitMQ può fallire, e non possiamo metterlo
 * nella stessa transazione SQL (RabbitMQ non supporta transazioni SQL).
 * Con l'outbox, il messaggio è GARANTITO nel database. Il relay lo invia
 * quando RabbitMQ è disponibile. Se RabbitMQ è down, il relay riprova
 * al prossimo ciclo. Nessun messaggio si perde.
 */
class OutboxRelay
{
    public function __construct(
        private PDO $pdo,
        private QueueService $queueService
    ) {}

    /**
     * Processa tutti i messaggi outbox non ancora inviati.
     *
     * @return int Numero di messaggi processati
     */
    public function processOutbox(): int
    {
        // Leggi i messaggi non processati, ordinati per data di creazione.
        // LIMIT 10: ne processiamo 10 alla volta per non sovraccaricare
        // né il database né RabbitMQ.
        $stmt = $this->pdo->prepare(
            'SELECT id, event_type, payload
            FROM outbox
            WHERE processed = FALSE
            ORDER BY created_at ASC
            LIMIT 10'
        );
        $stmt->execute();
        $messages = $stmt->fetchAll();

        $processed = 0;

        foreach ($messages as $message) {
            try {
                // Decodifica il payload JSON
                $payload = json_decode($message['payload'], true);

                // Invia a RabbitMQ
                $this->queueService->publishPayment($payload);

                // Segna come processato nel database.
                // Questo è un UPDATE separato dalla transazione originale,
                // ma va bene: se questo UPDATE fallisce, il messaggio
                // verrà ri-inviato al prossimo ciclo. Il consumer
                // deve essere pronto a ricevere duplicati (idempotenza,
                // che implementeremo nella Fase 7).
                $stmt = $this->pdo->prepare(
                    'UPDATE outbox SET processed = TRUE, processed_at = NOW() WHERE id = ?'
                );
                $stmt->execute([$message['id']]);

                $processed++;

                error_log(sprintf(
                    '[OUTBOX] Relayed message #%d (type: %s)',
                    $message['id'],
                    $message['event_type']
                ));
            } catch (\Exception $e) {
                // Se l'invio a RabbitMQ fallisce, logghiamo e continuiamo.
                // Il messaggio resta con processed = FALSE e verrà
                // ritentato al prossimo ciclo. Non blocchiamo gli altri messaggi.
                error_log(sprintf(
                    '[OUTBOX] Failed to relay message #%d: %s',
                    $message['id'],
                    $e->getMessage()
                ));
            }
        }

        return $processed;
    }

    /**
     * Avvia il relay in un loop infinito.
     *
     * @param int $intervalSeconds Secondi di attesa tra un ciclo e l'altro
     */
    public function run(int $intervalSeconds = 2): void
    {
        error_log('[OUTBOX] Relay started. Polling every ' . $intervalSeconds . 's...');

        while (true) {
            $processed = $this->processOutbox();

            if ($processed > 0) {
                error_log(sprintf('[OUTBOX] Processed %d messages', $processed));
            }

            // Aspetta prima del prossimo ciclo.
            // sleep() blocca il processo per N secondi.
            // In un sistema più avanzato useresti un event loop
            // o un meccanismo di notifica (PostgreSQL LISTEN/NOTIFY).
            sleep($intervalSeconds);
        }
    }
}