<?php

declare(strict_types=1);

namespace Tests;

use App\Service\CircuitBreaker;
use App\Service\CircuitBreakerOpenException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CircuitBreakerTest extends TestCase
{
    private string $testStateFile;

    /**
     * setUp() è un metodo speciale di PHPUnit: viene chiamato
     * PRIMA di ogni test. Serve per preparare l'ambiente.
     *
     * Qui creiamo un file di stato temporaneo unico per ogni test.
     * uniqid() genera un ID univoco, così test paralleli non si
     * sovrascrivono i file a vicenda.
     *
     * Senza setUp, ogni test dovrebbe ripetere questa preparazione.
     */
    protected function setUp(): void
    {
        $this->testStateFile = '/tmp/circuit_breaker_test_' . uniqid() . '.json';
    }

    /**
     * tearDown() è il contrario di setUp: viene chiamato DOPO ogni test.
     * Serve per pulire: cancelliamo il file temporaneo.
     *
     * Senza tearDown, i file si accumulerebbero in /tmp.
     */
    protected function tearDown(): void
    {
        if (file_exists($this->testStateFile)) {
            unlink($this->testStateFile);
        }
    }

    public function testAllowsCallsWhenClosed(): void
    {
        // ARRANGE: circuit breaker nuovo, stato CLOSED (default)
        $cb = new CircuitBreaker(
            failureThreshold: 3,
            recoveryTimeout: 10.0,
            stateFile: $this->testStateFile
        );

        // ACT: esegui una funzione che riesce
        $result = $cb->execute(fn() => 'ok');

        // ASSERT: la chiamata è passata e lo stato è ancora CLOSED
        $this->assertEquals('ok', $result);
        $this->assertEquals('closed', $cb->getState());
    }

    public function testOpensAfterThresholdFailures(): void
    {
        // ARRANGE: soglia a 3 fallimenti
        $cb = new CircuitBreaker(
            failureThreshold: 3,
            recoveryTimeout: 10.0,
            stateFile: $this->testStateFile
        );

        // ACT: forza 3 fallimenti consecutivi.
        // Ogni fallimento viene catturato perché non ci interessa l'eccezione,
        // ci interessa l'EFFETTO sul circuit breaker.
        for ($i = 0; $i < 3; $i++) {
            try {
                $cb->execute(fn() => throw new RuntimeException('fail'));
            } catch (RuntimeException) {
                // Atteso — lo ignoriamo, ci interessa solo lo stato del CB
            }
        }

        // ASSERT: dopo 3 fallimenti, il circuito deve essere OPEN
        $this->assertEquals('open', $cb->getState());
    }

    public function testRejectedCallsWhenOpen(): void
    {
        // ARRANGE: soglia a 2 per raggiungere OPEN più velocemente
        $cb = new CircuitBreaker(
            failureThreshold: 2,
            recoveryTimeout: 60.0,
            stateFile: $this->testStateFile
        );

        // Apri il circuito con 2 fallimenti
        for ($i = 0; $i < 2; $i++) {
            try {
                $cb->execute(fn() => throw new RuntimeException('fail'));
            } catch (RuntimeException) {

            }
        }

        // ACT + ASSERT: la prossima chiamata deve lanciare
        // CircuitBreakerOpenException — NON RuntimeException.
        // Il circuit breaker non prova nemmeno a eseguire la funzione:
        // la rifiuta immediatamente con la sua eccezione specifica.
        $this->expectException(CircuitBreakerOpenException::class);

        $cb->execute(fn() => 'should not reach here');
    }

    public function testResetsOnSuccess(): void
    {
        // ARRANGE: soglia a 5
        $cb = new CircuitBreaker(
            failureThreshold: 5,
            recoveryTimeout: 10.0,
            stateFile: $this->testStateFile
        );

        // Accumula 3 fallimenti (sotto la soglia di 5)
        for ($i = 0; $i < 3; $i++) {
            try {
                $cb->execute(fn() => throw new RuntimeException('fail'));
            } catch (RuntimeException) {
            }
        }

        // Verifica che il contatore sia a 3
        $this->assertEquals(3, $cb->getFailureCount());

        // ACT: una chiamata che riesce
        $cb->execute(fn() => 'ok');

        // ASSERT: il successo deve azzerare il contatore e mantenere CLOSED.
        // Questo è importante: senza il reset, basterebbero fallimenti
        // "sparsi" nel tempo per aprire il circuito, anche se il servizio
        // funziona la maggior parte delle volte.
        $this->assertEquals(0, $cb->getFailureCount());
        $this->assertEquals('closed', $cb->getState());
    }
}