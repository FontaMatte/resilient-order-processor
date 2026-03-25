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
     * Avvia il relay con LISTEN/NOTIFY + polling di fallback.
     *
     * Il relay ascolta le notifiche PostgreSQL per reagire in tempo reale.
     * Ogni 5 secondi fa comunque un poll di sicurezza per catturare
     * eventuali messaggi persi (se una notifica non arriva per qualsiasi motivo).
     *
     * @param int $fallbackIntervalSeconds Secondi tra un poll di fallback e l'altro
     */
    public function run(int $fallbackIntervalSeconds = 5): void
    {
        // Registra l'ascolto sul canale PostgreSQL.
        // LISTEN è un comando SQL: dice a PostgreSQL "avvisami quando qualcuno
        // fa NOTIFY su questo canale".
        $this->pdo->exec('LISTEN new_outbox_message');

        error_log('[OUTBOX] Relay started with LISTEN/NOTIFY. Fallback poll every ' . $fallbackIntervalSeconds . 's...');

        // Processa eventuali messaggi già presenti all'avvio
        $this->processOutbox();

        $lastPollTime = time();

        while (true) {

            // pdo_pgsql ha un metodo specifico per controllare le notifiche.
            // pgsqlGetNotify() controlla se ci sono notifiche pendenti.
            // Il parametro è il timeout in millisecondi:
            //   - 0 = non bloccare, controlla e basta
            //   - 1000 = aspetta fino a 1 secondo
            // Usiamo 1000ms così non facciamo busy-waiting (loop continuo senza pausa).
            $notification = $this->pdo->pgsqlGetNotify(PDO::FETCH_ASSOC, 1000);

            if ($notification !== false) {
                // Notifica ricevuta! Processa immediatamente.
                error_log(sprintf(
                    '[OUTBOX] Notification received (message id: %s). Processing...',
                    $notification['payload'] ?? 'unknown'
                ));
                $this->processOutbox();
                $lastPollTime = time();
            }

            // Poll di fallback: ogni N secondi, controlla comunque il database.
            // Difesa in profondità: se una notifica si perde (raro ma possibile
            // in caso di problemi di connessione), il polling la recupera.
            if (time() - $lastPollTime >= $fallbackIntervalSeconds) {
                $processed = $this->processOutbox();
                if ($processed > 0) {
                    error_log(sprintf('[OUTBOX] Fallback poll: processed %d messages', $processed));
                }
                $lastPollTime = time();
            }
        }
    }
}