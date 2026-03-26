<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Raccoglie e espone metriche del sistema.
 *
 * In produzione si userebbe un client Prometheus (promphp/prometheus_client_php)
 * con Redis come storage. Qui usiamo un file JSON per semplicità.
 *
 * Le metriche sono di 3 tipi:
 * - Counter: valore che solo cresce (ordini totali, errori totali)
 * - Gauge: valore che può salire e scendere (ordini in coda, stock attuale)
 * - Histogram: distribuzione di valori (tempo di risposta)
 *
 * Noi implementiamo counter e gauge.
 */
class Metrics
{
    private static string $stotageFile = '/tmp/app_metrics.json';

    /**
     * Incrementa un contatore.
     * I contatori solo crescono: ordini creati, errori, retry, ecc.
     */
    public static function increment(string $name, int $amount = 1): void
    {
        $data = self::loadData();

        if (!isset($data['counters'][$name])) {
            $data['counters'][$name] = 0;
        }

        $data['counters'][$name] = $amount;
        self::saveData($data);
    }

    /**
     * Imposta un gauge (valore che può variare).
     * Es: numero di messaggi in coda, stock di un prodotto.
     */
    public static function gauge(string $name, float $value): void
    {
        $data = self::loadData();
        $data['gauges'][$name] = $value;
        self::saveData($data);
    }

    /**
     * Registra un tempo di esecuzione.
     * Salva il totale e il conteggio per calcolare la media.
     */
    public static function timing(string $name, float $durationMs): void
    {
        $data = self::loadData();

        if (!isset($data['timings'][$name])) {
            $data['timings'][$name] = ['total_ms' => 0, 'count' => 0, 'max_ms' => 0];
        }

        $data['timings'][$name]['total_ms'] += $durationMs;
        $data['timings'][$name]['count']++;

        if ($durationMs > $data['timings'][$name]['max_ms']) {
            $data['timings'][$name]['max_ms'] = $durationMs;
        }

        self::saveData($data);
    }

    /**
     * Restituisce tutte le metriche formattate.
     */
    public static function getAll(): array
    {
        $data = self::loadData();

        // Calcola le medie per i timings
        $timings = [];
        foreach ($data['timings'] ?? [] as $name => $timing) {
            $timings[$name] = [
                'avg_ms' => $timing['count'] > 0
                    ? round($timing['total_ms'] / $timing['count'], 2)
                    : 0,
                'max_ms' => round($timing['max_ms'], 2),
                'count' => $timing['count'],
            ];
        }

        return [
            'counters' => $data['counters'] ?? [],
            'gauges' => $data['gauges'] ?? [],
            'timings' => $timings,
            'collected_at' => date('c'),
        ];
    }

    /**
     * Resetta tutte le metriche. Utile nei test.
     */
    public static function reset(): void
    {
        if (file_exists(self::$stotageFile)) {
            unlink(self::$stotageFile);
        }   
    }

    private static function loadData(): array
    {
        if (!file_exists(self::$stotageFile)) {
            return ['counters' => [], 'gauge' => [], 'timing' => []];
        }

        return json_decode(file_get_contents(self::$stotageFile), true) ?: ['counters' => [], 'gauges' => [], 'timings' => []];
    }

    private static function saveData(array $data): void
    {
        file_put_contents(self::$stotageFile, json_encode($data));
    }
}