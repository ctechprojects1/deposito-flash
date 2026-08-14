<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryCount;
use App\Models\InventoryCountItem;
use App\Models\Stock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryCountController extends Controller
{
    /**
     * Lista as contagens (mais recentes primeiro).
     * GET /api/counts
     */
    public function index(): JsonResponse
    {
        $counts = InventoryCount::query()
            ->latest()
            ->get()
            ->map(fn ($c) => $this->resumo($c));

        return response()->json(['data' => $counts]);
    }

    /**
     * Inicia uma contagem: congela o estoque atual como baseline.
     * POST /api/counts   { descricao?, escopo_time? }
     *
     * escopo_time (opcional) limita a contagem a um Time (corredor).
     */
    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'descricao'   => ['nullable', 'string', 'max:255'],
            'escopo_time' => ['nullable', 'string', 'max:255'],
        ]);

        // Impede duas contagens abertas ao mesmo tempo (evita bagunça).
        if (InventoryCount::where('status', InventoryCount::STATUS_ABERTA)->exists()) {
            return response()->json([
                'message' => 'Já existe uma contagem aberta. Finalize ou cancele antes de iniciar outra.',
            ], 409);
        }

        $count = DB::transaction(function () use ($dados) {
            $count = InventoryCount::create([
                'descricao'   => $dados['descricao'] ?? null,
                'escopo_time' => $dados['escopo_time'] ?? null,
                'status'      => InventoryCount::STATUS_ABERTA,
            ]);

            // Congela o estoque atual (opcionalmente filtrando por Time).
            $stocks = Stock::query()
                ->with('location')
                ->when($dados['escopo_time'] ?? null, function ($q, $time) {
                    $q->whereHas('location', fn ($l) => $l->where('corredor', $time));
                })
                ->get();

            foreach ($stocks as $stock) {
                InventoryCountItem::create([
                    'inventory_count_id' => $count->id,
                    'location_id'        => $stock->location_id,
                    'product_id'         => $stock->product_id,
                    'qtd_sistema'        => $stock->quantidade,
                    'qtd_contada'        => 0,
                    'contada'            => false,
                ]);
            }

            $count->update(['total_itens' => $stocks->count()]);

            return $count;
        });

        return response()->json([
            'message' => 'Contagem iniciada.',
            'data'    => $this->resumo($count),
        ], 201);
    }

    /**
     * Detalha uma contagem com os itens agrupados por endereço.
     * GET /api/counts/{count}
     */
    public function show(InventoryCount $count): JsonResponse
    {
        $count->load(['items.location', 'items.product']);

        // Agrupa por endereço (location) para a tela de contagem.
        $enderecos = $count->items
            ->groupBy('location_id')
            ->map(function ($itens) {
                $loc = $itens->first()->location;
                return [
                    'location_id' => $loc?->id,
                    'endereco'    => $loc?->nome,
                    'time'        => $loc?->corredor,
                    'posicao'     => $loc?->esteira,
                    'total'       => $itens->count(),
                    'contados'    => $itens->where('contada', true)->count(),
                    'itens'       => $itens->map(fn ($i) => [
                        'item_id'     => $i->id,
                        'product_id'  => $i->product_id,
                        'codigo'      => $i->product?->codigo_microvix,
                        'produto'     => $i->product?->nome,
                        'qtd_sistema' => (float) $i->qtd_sistema,
                        'qtd_contada' => (float) $i->qtd_contada,
                        'contada'     => $i->contada,
                        'diferenca'   => $i->diferenca,
                    ])->values(),
                ];
            })
            ->sortBy('endereco')
            ->values();

        return response()->json([
            'data' => array_merge($this->resumo($count), [
                'enderecos' => $enderecos,
            ]),
        ]);
    }

    /**
     * Salva as quantidades contadas (em lote, normalmente de um endereço).
     * POST /api/counts/{count}/itens
     *   { itens: [ { item_id, qtd_contada }, ... ] }
     */
    public function salvarItens(Request $request, InventoryCount $count): JsonResponse
    {
        if ($count->status !== InventoryCount::STATUS_ABERTA) {
            return response()->json(['message' => 'Esta contagem não está aberta.'], 409);
        }

        $dados = $request->validate([
            'itens'               => ['required', 'array', 'min:1'],
            'itens.*.item_id'     => ['required', 'integer'],
            'itens.*.qtd_contada' => ['required', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($count, $dados) {
            foreach ($dados['itens'] as $linha) {
                $count->items()
                    ->whereKey($linha['item_id'])
                    ->update([
                        'qtd_contada' => $linha['qtd_contada'],
                        'contada'     => true,
                    ]);
            }

            $count->update([
                'itens_contados' => $count->items()->where('contada', true)->count(),
            ]);
        });

        return response()->json([
            'message' => 'Contagem salva.',
            'data'    => $this->resumo($count->fresh()),
        ]);
    }

    /**
     * Relatório de divergências (itens contados cujo valor difere do sistema).
     * GET /api/counts/{count}/divergencias
     */
    public function divergencias(InventoryCount $count): JsonResponse
    {
        $count->load(['items.location', 'items.product']);

        $divergencias = $count->items
            ->where('contada', true)
            ->filter(fn ($i) => abs($i->diferenca) > 0.001)
            ->map(fn ($i) => [
                'endereco'    => $i->location?->nome,
                'codigo'      => $i->product?->codigo_microvix,
                'produto'     => $i->product?->nome,
                'qtd_sistema' => (float) $i->qtd_sistema,
                'qtd_contada' => (float) $i->qtd_contada,
                'diferenca'   => $i->diferenca,
                'tipo'        => $i->diferenca > 0 ? 'sobra' : 'falta',
            ])
            ->sortByDesc(fn ($d) => abs($d['diferenca']))
            ->values();

        return response()->json([
            'data' => [
                'total_divergencias' => $divergencias->count(),
                'sobras'             => $divergencias->where('tipo', 'sobra')->count(),
                'faltas'             => $divergencias->where('tipo', 'falta')->count(),
                'itens'              => $divergencias,
            ],
        ]);
    }

    /**
     * Finaliza a contagem e AJUSTA o estoque para os valores contados.
     * POST /api/counts/{count}/finalizar
     */
    public function finalizar(InventoryCount $count): JsonResponse
    {
        if ($count->status !== InventoryCount::STATUS_ABERTA) {
            return response()->json(['message' => 'Esta contagem não está aberta.'], 409);
        }

        $ajustados = DB::transaction(function () use ($count) {
            $ajustados = 0;

            // Só ajusta itens que foram efetivamente contados.
            foreach ($count->items()->where('contada', true)->get() as $item) {
                $stock = Stock::where('location_id', $item->location_id)
                    ->where('product_id', $item->product_id)
                    ->lockForUpdate()
                    ->first();

                if ($stock && (float) $stock->quantidade !== (float) $item->qtd_contada) {
                    $stock->update(['quantidade' => $item->qtd_contada]);
                    $ajustados++;
                }
            }

            $count->update([
                'status'        => InventoryCount::STATUS_FINALIZADA,
                'finalizada_em' => now(),
            ]);

            return $ajustados;
        });

        return response()->json([
            'message' => "Contagem finalizada. {$ajustados} endereço(s)/produto(s) ajustado(s) no estoque.",
            'data'    => $this->resumo($count->fresh()),
        ]);
    }

    /**
     * Cancela a contagem (sem alterar o estoque).
     * POST /api/counts/{count}/cancelar
     */
    public function cancelar(InventoryCount $count): JsonResponse
    {
        if ($count->status !== InventoryCount::STATUS_ABERTA) {
            return response()->json(['message' => 'Esta contagem não está aberta.'], 409);
        }

        $count->update(['status' => InventoryCount::STATUS_CANCELADA]);

        return response()->json([
            'message' => 'Contagem cancelada.',
            'data'    => $this->resumo($count->fresh()),
        ]);
    }

    private function resumo(InventoryCount $c): array
    {
        return [
            'id'             => $c->id,
            'descricao'      => $c->descricao,
            'escopo_time'    => $c->escopo_time,
            'status'         => $c->status,
            'total_itens'    => $c->total_itens,
            'itens_contados' => $c->itens_contados,
            'criada_em'      => $c->created_at?->toDateTimeString(),
            'finalizada_em'  => $c->finalizada_em?->toDateTimeString(),
        ];
    }
}
