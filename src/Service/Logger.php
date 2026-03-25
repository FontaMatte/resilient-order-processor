<?php

declare(strict_types=1);

namespace App\Service;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Psr\Log\LoggerInterface;

/**
 * Factory per il logger dell'applicazione.
 *
 * Restituisce un'istanza PSR-3 LoggerInterface configurata per:
 * - Scrivere su stdout (così Docker cattura i log)
 * - Formato JSON (parsabile da sistemi di monitoring)
 * - Contesto strutturato (puoi aggiungere dati a ogni messaggio)
 *
 * PSR-3 definisce 8 livelli di log:
 *   emergency > alert > critical > error > warning > notice > info > debug
 *
 * Usando l'interfaccia PSR-3, puoi sostituire Monolog con qualsiasi
 * altro logger compatibile senza cambiare il codice applicativo.
 */
class Logger
{
    private static ?LoggerInterface $instance = null;

    public static function getInstance(): LoggerInterface
    {
        if (self::$instance === null) {
            $logger = new MonologLogger('app');

            // StreamHandler scrive su uno stream. 'php://stdout' = output standard.
            // Docker cattura tutto ciò che va su stdout e lo mostra nei log.
            // Level::DEBUG = logga tutto (in produzione useresti Level::INFO o WARNING).
            $handler = new StreamHandler('php://stdout', Level::Debug);

            // JsonFormatter produce log tipo:
            // {"message":"Order created","context":{"order_id":"abc-123"},"level_name":"INFO",...}
            // Molto più utile di "Order abc-123 created" in testo libero.
            $handler->setFormatter(new JsonFormatter());

            $logger->pushHandler($handler);

            self::$instance = $logger;
        }

        return self::$instance;
    }
}