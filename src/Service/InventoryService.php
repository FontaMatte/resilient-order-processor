<?php

declare(strict_types=1);

namespace App\Service;

use Exception;
use PDO;

class InventoryService
{
    public function __construct(private PDO $pdo)
    {}

    /**
     * Riserva lo stock, crea l'ordine, e scrive nell'outbox.
     * Tutto in un'unica transazione atomica.
     *
     * @return array{success: bool, order?: array, error?: string}
     */
    public function reserveAndCreateOrder(
        int $productId, 
        int $quantity,
        string $totalPrice
    ): array
    {
        try {
            // BEGIN: apri la transazione.
            // Da questo momento, tutte le operazioni sono "provvisorie".
            // Diventano permanenti solo con COMMIT.
            $this->pdo->beginTransaction();

            // SELECT ... FOR UPDATE: leggi il prodotto E blocca la riga.
            // Qualsiasi altra transazione che prova a leggere FOR UPDATE
            // la stessa riga ASPETTERÀ qui finché non facciamo COMMIT o ROLLBACK.
            //
            // Senza FOR UPDATE, due transazioni possono leggere lo stesso stock
            // contemporaneamente e sovrascriversi a vicenda (race condition).
            $stmt = $this->pdo->prepare("
                SELECT id, name, price, stock FROM products WHERE id = ? FOR UPDATE
            ");
            $stmt->execute([$productId]);
            $product = $stmt->fetch();

            // Il prodotto non esiste (non dovrebbe mai succedere qui,
            // perché l'abbiamo già verificato nel controller, ma la difesa
            // in profondità è un principio importante: non fidarti degli step precedenti)
            if ($product === false) {
                $this->pdo->rollBack();
                return [
                    'success' => false,
                    'error' => "Product {$productId} not found"
                ];
            }

            // Verifica lo stock
            if ((int) $product['stock'] < $quantity) {
                $this->pdo->rollBack();
                return [
                    'success' => false,
                    'error' => sprintf(
                        'Insufficient stock: requested %d, available %d',
                        $quantity,
                        $product['stock']
                    )
                ];
            }

            // Scala lo stock. Nota: "stock = stock - ?" è meglio di "stock = ?"
            // perché lavora sul valore corrente della riga, non su un valore
            // letto in precedenza. Anche se il FOR UPDATE ci protegge già,
            // è buona pratica essere difensivi.
            //
            // updated_at = NOW() tiene traccia di quando lo stock è cambiato.
            $stmt = $this->pdo->prepare('
                UPDATE products SET stock = stock - ?, updated_at = NOW() WHERE id = ?
            ');
            $stmt->execute([$quantity, $productId]);

            // Crea l'ordine — nella STESSA transazione
            $stmt = $this->pdo->prepare(
                'INSERT INTO orders (product_id, quantity, total_price, status)
                VALUES (?, ?, ?, ?)
                RETURNING *'
            );
            $stmt->execute([$productId, $quantity, $totalPrice, 'confirmed']);
            $order = $stmt->fetch();

            // Scrivi nell'outbox — nella STESSA transazione
            // Questo è il cuore del Transactional Outbox Pattern.
            // Non inviamo il messaggio a RabbitMQ ora (potrebbe fallire).
            // Lo "parcheggiamo" nel database, che è affidabile.
            // Il relay lo invierà a RabbitMQ dopo.
            $outboxPayload = [
                'order_id' => $order['id'],
                'product_id' => $productId,
                'quantity' => $quantity,
                'total_price' => $totalPrice,
                'event' => 'order_confirmed'
            ];

            $stmt = $this->pdo->prepare(
                'INSERT INTO outbox (event_type, payload)
                VALUES (?, ?)'
            );
            $stmt->execute([
                'order_confirmed',
                json_encode($outboxPayload)
            ]);

            // COMMIT: tutto è andato bene.
            // Stock scalato + ordine creato + outbox scritto.
            // Se una qualsiasi delle operazioni sopra fosse fallita,
            // il catch avrebbe fatto rollBack e NIENTE sarebbe stato salvato.
            $this->pdo->commit();

            return [
                'success' => true,
                'order' => $order,
                'product' => $product,
            ];

        } catch (\Exception $e) {
            // Se QUALSIASI cosa va storto, annulla tutto.
            // rollBack() riporta il database allo stato prima del BEGIN.
            // Il "if" controlla che ci sia effettivamente una transazione attiva
            // (se beginTransaction() stesso fallisce, non c'è nulla da annullare).
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }
}