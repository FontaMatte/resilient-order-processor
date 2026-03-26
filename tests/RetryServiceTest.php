<?php

declare(strict_types=1);

namespace Tests;

use App\Service\RetryService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RetryServiceTest extends TestCase
{
    public function testSucceedsOnFirstAttempt(): void
    {
        // ARRANGE: crea il RetryService.
        // baseDelayMs basso (10ms) per non rallentare i test.
        // In produzione usiamo 200ms, ma nei test vogliamo velocità.
        $retryService = new RetryService(maxAttempts: 3, baseDelayMs: 10);

        // ACT: esegui una funzione che riesce subito.
        // fn() => 'success' è una arrow function che restituisce la stringa 'success'.
        $result = $retryService->execute(
            fn() => 'success',
            'test_operation'
        );

        // ASSERT: il risultato deve essere 'success'.
        // assertEquals confronta il valore atteso (primo parametro)
        // con il valore effettivo (secondo parametro).
        // Se sono diversi, il test FALLISCE.
        $this->assertEquals('success', $result);
    }

    public function testSucceedsAfterRetry(): void
    {
        // ARRANGE
        $retryService = new RetryService(maxAttempts: 3, baseDelayMs: 10);

        // Questa variabile conta quante volte la funzione viene chiamata.
        // Usiamo &$attempts (passaggio per riferimento) perché la closure
        // deve MODIFICARE la variabile esterna, non solo leggerla.
        // Senza &, la closure lavorerebbe su una copia e $attempts
        // resterebbe sempre 0 fuori dalla closure.
        $attempts = 0;

        // ACT: la funzione fallisce al primo tentativo, riesce al secondo.
        $result = $retryService->execute(
            function () use (&$attempts) {
                $attempts++;
                if ($attempts < 2) {
                    throw new RuntimeException('Temporary failure');
                }
                return 'recovered';
            },
            'test_operation' 
        );

        // ASSERT: verifica sia il risultato che il numero di tentativi.
        // Due asserzioni nello stesso test: entrambe devono passare.
        $this->assertEquals('recovered', $result);
        $this->assertEquals(2, $attempts);
    }

    public function testFailsAfterAllAttempts(): void
    {
        // ARRANGE
        $retryService = new RetryService(maxAttempts: 3, baseDelayMs: 10);

        // ASSERT (prima dell'ACT!):
        // expectException dice a PHPUnit: "il codice che segue DEVE lanciare
        // questa eccezione. Se NON la lancia, il test FALLISCE."
        //
        // È l'unico caso in cui l'assert viene PRIMA dell'act,
        // perché PHPUnit deve sapere cosa aspettarsi prima che il codice esploda.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Persistent failure');

        // ACT: la funzione fallisce SEMPRE. Dopo 3 tentativi,
        // il RetryService deve rilanciare l'eccezione.
        $retryService->execute(
            fn() => throw new RuntimeException('Persistent failure'),
            'test_operation'
        );

        // Non serve assert qui: se arriviamo a questa riga,
        // significa che l'eccezione NON è stata lanciata → il test fallisce.
    }

    public function testRespectMaxAttempts(): void
    {
        // ARRANGE: maxAttempts = 5 (diverso dal default 3)
        $retryService = new RetryService(maxAttempts: 5, baseDelayMs: 10);

        $attempts = 0;

        // ACT: la funzione fallisce SEMPRE. Contiamo quante volte viene chiamata.
        try {
            $retryService->execute(
                function () use (&$attempts) {
                    $attempts++;
                    throw new RuntimeException('Always fails');
                },
                'test_operation'
            );
        } catch (RuntimeException) {
            // Ci aspettiamo l'eccezione — la catturiamo e non facciamo nulla.
            // Se non la catturassimo, PHPUnit la tratterebbe come un errore
            // non previsto e il test fallirebbe.
        }

        // ASSERT: deve aver provato esattamente 5 volte, non 3, non 6.
        $this->assertEquals(5, $attempts);
    }
}