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
     * Riserva una quantità di prodotto scalando lo stock in modo atomico.
     *
     * "Atomico" significa: o l'intera operazione riesce, o non succede niente.
     * Non può succedere che lo stock venga letto ma non aggiornato,
     * o aggiornato a metà. È tutto-o-niente.
     *
     * @return array{success: bool, product: array, remaining_stock: int, error?: string}
     */
    public function reserveStock(int $productId, int $quantity): array
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

            // Verifica lo stock: c'è abbastanza?
            if ((int) $product['stock'] < $quantity) {
                $this->pdo->rollBack();
                return [
                    'success' => false,
                    'error' => sprintf(
                        'Insufficient stock: requested %d, available %d',
                        $quantity,
                        $product['stock']
                    ),
                    'available_stock' => (int) $product['stock'],
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

            // COMMIT: rendi permanenti tutte le modifiche E rilascia il blocco.
            // Solo a questo punto il secondo cliente può leggere la riga.
            $this->pdo->commit();

            return [
                'success' => true,
                'product' => $product,
                'remaining_stock' => (int) $product['stock'] - $quantity
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