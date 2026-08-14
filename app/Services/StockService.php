<?php

namespace App\Services;

use App\Models\Stock;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Centraliza as regras de movimentação de estoque.
 *
 * Toda alteração de quantidade passa por aqui, garantindo trava de linha
 * (lockForUpdate) e transação para evitar condição de corrida quando dois
 * separadores mexem no mesmo endereço ao mesmo tempo.
 */
class StockService
{
    /**
     * Dá entrada (soma quantidade) de um produto em um endereço.
     * Cria o registro de estoque caso ainda não exista.
     */
    public function entrada(int $locationId, int $productId, float $quantidade): Stock
    {
        $this->validarQuantidade($quantidade);

        return DB::transaction(function () use ($locationId, $productId, $quantidade) {
            $stock = Stock::query()
                ->where('location_id', $locationId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if (! $stock) {
                $stock = new Stock([
                    'location_id' => $locationId,
                    'product_id'  => $productId,
                    'quantidade'  => 0,
                ]);
            }

            $stock->quantidade += $quantidade;
            $stock->save();

            return $stock->fresh(['location', 'product']);
        });
    }

    /**
     * Dá baixa (reduz quantidade) de um produto em um endereço.
     *
     * @throws RuntimeException se não houver estoque suficiente.
     */
    public function baixa(int $locationId, int $productId, float $quantidade): Stock
    {
        $this->validarQuantidade($quantidade);

        return DB::transaction(function () use ($locationId, $productId, $quantidade) {
            $stock = Stock::query()
                ->where('location_id', $locationId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if (! $stock) {
                throw new RuntimeException('Não há estoque deste produto neste endereço.');
            }

            if ($stock->quantidade < $quantidade) {
                throw new RuntimeException(
                    "Estoque insuficiente. Disponível: {$stock->quantidade}, solicitado: {$quantidade}."
                );
            }

            $stock->quantidade -= $quantidade;
            $stock->save();

            return $stock->fresh(['location', 'product']);
        });
    }

    private function validarQuantidade(float $quantidade): void
    {
        if ($quantidade <= 0) {
            throw new RuntimeException('A quantidade deve ser maior que zero.');
        }
    }
}
