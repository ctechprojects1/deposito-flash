<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deposito;
use App\Models\Stock;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class StockController extends Controller
{
    public function __construct(private readonly StockService $stockService)
    {
    }

    /**
     * Dá entrada de um produto em um endereço (soma quantidade).
     *
     * POST /api/stock/entrada
     */
    public function entrada(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'location_id' => ['required', 'integer', Rule::exists('locations', 'id')->where('deposito_id', Deposito::atualId())],
            'product_id'  => ['required', 'integer', 'exists:products,id'],
            'quantidade'  => ['required', 'numeric', 'gt:0'],
        ]);

        try {
            $stock = $this->stockService->entrada(
                $dados['location_id'],
                $dados['product_id'],
                (float) $dados['quantidade'],
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Entrada registrada com sucesso.',
            'data'    => $this->formatarStock($stock),
        ], 200);
    }

    /**
     * Dá baixa de um produto em um endereço (reduz quantidade).
     *
     * POST /api/stock/baixa
     */
    public function baixa(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'location_id' => ['required', 'integer', Rule::exists('locations', 'id')->where('deposito_id', Deposito::atualId())],
            'product_id'  => ['required', 'integer', 'exists:products,id'],
            'quantidade'  => ['required', 'numeric', 'gt:0'],
        ]);

        try {
            $stock = $this->stockService->baixa(
                $dados['location_id'],
                $dados['product_id'],
                (float) $dados['quantidade'],
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Baixa registrada com sucesso.',
            'data'    => $this->formatarStock($stock),
        ], 200);
    }

    private function formatarStock($stock): array
    {
        return [
            'stock_id'    => $stock->id,
            'location_id' => $stock->location_id,
            'local'       => $stock->location?->nome,
            'product_id'  => $stock->product_id,
            'produto'     => $stock->product?->nome,
            'quantidade'  => (float) $stock->quantidade,
        ];
    }

    /**
     * Zera TODO o estoque (todos os endereços). Ação destrutiva.
     *
     * POST /api/stock/zerar-tudo
     */
    public function zerarTudo(): JsonResponse
    {
        $afetados = Stock::where('quantidade', '!=', 0)->update(['quantidade' => 0]);

        return response()->json([
            'message' => "Estoque geral zerado ({$afetados} registro(s) de estoque).",
        ]);
    }
}
