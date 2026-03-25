<?php

declare(strict_types=1);

namespace App\Service;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Channel\AMQPChannel;

/**
 * Gestisce la connessione a RabbitMQ e l'invio/ricezione di messaggi.
 *
 * Concetti chiave di RabbitMQ:
 *
 * PRODUCER → EXCHANGE → QUEUE → CONSUMER
 *
 * - Producer: chi invia il messaggio (la nostra API)
 * - Exchange: lo "smistatore". Riceve i messaggi e decide in quale coda metterli.
 *   Noi usiamo il "default exchange" che manda direttamente alla coda specificata.
 * - Queue: la coda vera e propria. I messaggi si accumulano qui finché un consumer li prende.
 * - Consumer: chi legge i messaggi dalla coda (il nostro payment worker).
 *
 * ACKNOWLEDGMENT (ack):
 *   Quando il consumer prende un messaggio, RabbitMQ non lo cancella subito.
 *   Aspetta che il consumer dica "ho finito, puoi cancellarlo" (ack).
 *   Se il consumer crolla prima di fare ack, RabbitMQ rimette il messaggio
 *   in coda e lo consegna a un altro consumer (o allo stesso quando riparte).
 *   Questo garantisce che nessun messaggio si perda.
 */
class QueueService
{
    private ?AMQPStreamConnection $connection = null;
    private ?AMQPChannel $channel = null;

    public const QUEUE_PAYMENTS = 'order_payments';
    public const QUEUE_PAYMENTS_DLQ = 'order_payments_dlq';

    public function __construct(
        private string $host = 'rabbitmq',
        private int $port = 5672,
        private string $user = 'guest',
        private string $password = 'guest'
    ) {}

    /**
     * Restituisce il canale AMQP, creando la connessione se necessario.
     *
     * Un "canale" in AMQP è come una sessione di lavoro dentro una connessione TCP.
     * Puoi avere più canali sulla stessa connessione (come più tab nello stesso browser).
     * Questo è efficiente perché aprire una connessione TCP è costoso.
     */
    public function getChannel(): AMQPChannel
    {
        if ($this->channel === null || !$this->channel->is_open()) {
            $this->connection = new AMQPStreamConnection(
                $this->host,
                $this->port,
                $this->user,
                $this->password
            );

            $this->channel = $this->connection->channel();

            // Dichiara la coda. queue_declare è "idempotente":
            // se la coda esiste già, non fa nulla. Se non esiste, la crea.
            // Parametri:
            //   - nome della coda
            //   - passive (false): non verificare solo l'esistenza, creala se serve
            //   - durable (true): la coda SOPRAVVIVE al restart di RabbitMQ
            //   - exclusive (false): altri consumer possono connettersi
            //   - auto_delete (false): non cancellare la coda quando l'ultimo consumer si disconnette
            $this->channel->queue_declare(
                self::QUEUE_PAYMENTS,
                false,   // passive
                true,    // durable: la coda persiste dopo il restart di RabbitMQ
                false,   // exclusive
                false    // auto_delete
            );

            $this->channel->queue_declare(
                self::QUEUE_PAYMENTS_DLQ,
                false,
                true,
                false,
                false
            );
        }

        return $this->channel;
    }

    /**
     * Pubblica un messaggio nella coda dei pagamenti.
     *
     * @param array $data I dati da inviare (verranno serializzati in JSON)
     */
    public function publishPayment(array $data): void
    {
        $channel = $this->getChannel();

        // Creiamo il messaggio AMQP.
        // delivery_mode = 2 significa "persistente": il messaggio viene scritto su disco.
        // Se RabbitMQ crolla e riparte, il messaggio sarà ancora in coda.
        // Con delivery_mode = 1, il messaggio starebbe solo in memoria e andrebbe perso.
        $message = new AMQPMessage(
            json_encode($data),
            [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'content_type' => 'application/json'
            ]
        );

        // basic_publish invia il messaggio.
        // Parametri:
        //   - il messaggio
        //   - exchange ('' = default exchange, che manda direttamente alla coda)
        //   - routing_key = nome della coda di destinazione
        $channel->basic_publish($message, '', self::QUEUE_PAYMENTS);
    }

    public function publishToDeadLetter(array $data): void
    {
        $channel = $this->getChannel();

        $message = new AMQPMessage(
            json_encode($data),
            [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'content_type' => 'application/json'
            ]
        );

        $channel->basic_publish($message, '', self::QUEUE_PAYMENTS_DLQ);
    }

    /**
     * Verifica che RabbitMQ sia raggiungibile.
     */
    public function isHealthy(): bool
    {
        try {
            $this->getChannel();
            return true;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Chiude la connessione in modo pulito.
     */
    public function close(): void
    {
        if ($this->channel !== null && $this->channel->is_open()) {
            $this->channel->close();
        }
        if ($this->connection !== null && $this->connection->isConnected()) {
            $this->connection->close();
        }
    }
}