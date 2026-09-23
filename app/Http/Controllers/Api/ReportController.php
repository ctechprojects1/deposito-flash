<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * Relatório: Produto × Localização.
     * Busca produto por código Microvix, código de barras ou descrição e
     * retorna o total e a quantidade em cada endereço.
     *
     * GET /api/reports/produto-localizacao?q=...
     */
    public function produtoLocalizacao(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if ($q === '') {
            return response()->json(['data' => []]);
        }

        $produtos = Product::query()
            ->where(function ($w) use ($q) {
                $w->where('nome', 'like', "%{$q}%")
                  ->orWhere('codigo_microvix', 'like', "%{$q}%")
                  ->orWhere('codigo_barras', 'like', "%{$q}%");
            })
            ->with(['stocks.location'])
            ->limit(50)
            ->get()
            ->map(function (Product $p) {
                $locs = $p->stocks
                    ->sortByDesc('quantidade')
                    ->map(fn ($s) => [
                        'endereco'   => $s->location?->nome,
                        'time'       => $s->location?->corredor,
                        'posicao'    => $s->location?->esteira,
                        'quantidade' => (float) $s->quantidade,
                    ])
                    ->values();

                return [
                    'product_id'      => $p->id,
                    'nome'            => $p->nome,
                    'codigo_microvix' => $p->codigo_microvix,
                    'codigo_barras'   => $p->codigo_barras,
                    'total'           => (float) $p->stocks->sum('quantidade'),
                    'localizacoes'    => $locs,
                ];
            });

        return response()->json(['data' => $produtos]);
    }
}
