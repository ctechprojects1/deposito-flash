<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use Illuminate\Http\JsonResponse;

class LocationController extends Controller
{
    /**
     * Lista todos os endereços com os produtos/quantidades que contêm.
     *
     * Formato pensado para alimentar o mapa visual: cada local traz suas
     * coordenadas (eixo_x / eixo_y) e a lista de itens armazenados.
     */
    public function index(): JsonResponse
    {
        $locations = Location::query()
            ->with(['stocks.product'])
            ->orderBy('nome')
            ->get()
            ->map(function (Location $location) {
                return [
                    'id'       => $location->id,
                    'nome'     => $location->nome,
                    'corredor' => $location->corredor,
                    'esteira'  => $location->esteira,
                    'eixo_x'   => $location->eixo_x,
                    'eixo_y'   => $location->eixo_y,
                    'ativo'    => $location->ativo,
                    'total_itens'      => $location->stocks->count(),
                    'total_quantidade' => (float) $location->stocks->sum('quantidade'),
                    'produtos' => $location->stocks->map(function ($stock) {
                        return [
                            'stock_id'        => $stock->id,
                            'product_id'      => $stock->product_id,
                            'codigo_microvix' => $stock->product?->codigo_microvix,
                            'nome'            => $stock->product?->nome,
                            'quantidade'      => (float) $stock->quantidade,
                        ];
                    })->values(),
                ];
            });

        return response()->json([
            'data' => $locations,
        ]);
    }

    /**
     * Detalha um endereço específico e seu conteúdo.
     */
    public function show(Location $location): JsonResponse
    {
        $location->load(['stocks.product']);

        return response()->json([
            'data' => [
                'id'       => $location->id,
                'nome'     => $location->nome,
                'corredor' => $location->corredor,
                'esteira'  => $location->esteira,
                'eixo_x'   => $location->eixo_x,
                'eixo_y'   => $location->eixo_y,
                'ativo'    => $location->ativo,
                'produtos' => $location->stocks->map(fn ($stock) => [
                    'stock_id'        => $stock->id,
                    'product_id'      => $stock->product_id,
                    'codigo_microvix' => $stock->product?->codigo_microvix,
                    'nome'            => $stock->product?->nome,
                    'quantidade'      => (float) $stock->quantidade,
                ])->values(),
            ],
        ]);
    }
}
