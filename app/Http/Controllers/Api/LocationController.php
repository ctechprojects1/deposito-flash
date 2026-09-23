<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Product;
use App\Models\Stock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
                            'codigo_barras'   => $stock->product?->codigo_barras,
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

    /**
     * Cria endereços para um Time (com uma ou mais posições).
     *
     * POST /api/locations
     *   { time: "SANTOS", posicoes: ["1A","1B","2A"] }
     *
     * Serve tanto para criar um Time novo quanto para adicionar posições
     * (linhas) a um Time já existente. Posições que já existem são ignoradas.
     */
    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'time'       => ['required', 'string', 'max:120'],
            'posicoes'   => ['required', 'array', 'min:1'],
            'posicoes.*' => ['required', 'string', 'max:20'],
        ]);

        $time = trim($dados['time']);

        // Base do eixo X: reaproveita a do Time se já existir; senão vai pro fim.
        $baseExistente = Location::where('corredor', $time)->min('eixo_x');
        $base = $baseExistente ?? ((int) Location::max('eixo_x') + 2);

        $criados = [];
        $ignorados = [];

        DB::transaction(function () use ($dados, $time, $base, &$criados, &$ignorados) {
            foreach ($dados['posicoes'] as $posBruta) {
                $pos = strtoupper(trim($posBruta));
                if ($pos === '') {
                    continue;
                }

                $nome = "{$time} {$pos}";
                if (Location::where('nome', $nome)->exists()) {
                    $ignorados[] = $nome;
                    continue;
                }

                $lado  = preg_match('/[AB]$/', $pos) ? substr($pos, -1) : 'A';
                $nivel = (int) preg_replace('/\D/', '', $pos);

                $loc = Location::create([
                    'nome'     => $nome,
                    'corredor' => $time,
                    'esteira'  => $pos,
                    'eixo_x'   => $base + ($lado === 'B' ? 1 : 0),
                    'eixo_y'   => $nivel,
                    'ativo'    => true,
                ]);

                $criados[] = ['id' => $loc->id, 'nome' => $loc->nome];
            }
        });

        return response()->json([
            'message'   => count($criados) . ' endereço(s) criado(s).'
                         . (count($ignorados) ? ' ' . count($ignorados) . ' já existia(m).' : ''),
            'criados'   => $criados,
            'ignorados' => $ignorados,
        ], 201);
    }

    /**
     * Zera o estoque de um endereço (todas as quantidades viram 0).
     *
     * POST /api/locations/{location}/zerar
     */
    public function zerar(Location $location): JsonResponse
    {
        $afetados = $location->stocks()->where('quantidade', '!=', 0)->update(['quantidade' => 0]);

        return response()->json([
            'message' => "Estoque de {$location->nome} zerado ({$afetados} produto(s)).",
        ]);
    }

    /**
     * Adiciona um produto a um endereço (cria o produto se necessário).
     *
     * POST /api/locations/{location}/produtos
     *   { nome, codigo_barras?, codigo_microvix?, quantidade? }
     */
    public function adicionarProduto(Request $request, Location $location): JsonResponse
    {
        $dados = $request->validate([
            'nome'            => ['required', 'string', 'max:191'],
            'codigo_barras'   => ['nullable', 'string', 'max:60'],
            'codigo_microvix' => ['nullable', 'string', 'max:60'],
            'quantidade'      => ['nullable', 'numeric', 'min:0'],
        ]);

        $codMicrovix = $dados['codigo_microvix'] ?? null;
        $codBarras   = $dados['codigo_barras'] ?? null;

        return DB::transaction(function () use ($dados, $location, $codMicrovix, $codBarras) {
            // Reaproveita produto existente pelo código (microvix ou barras); senão cria.
            $product = null;
            if ($codMicrovix) {
                $product = Product::where('codigo_microvix', $codMicrovix)->first();
            }
            if (! $product && $codBarras) {
                $product = Product::where('codigo_barras', $codBarras)->first();
            }

            if (! $product) {
                $product = Product::create([
                    'nome'            => $dados['nome'],
                    'codigo_microvix' => $codMicrovix ?: null,
                    'codigo_barras'   => $codBarras ?: null,
                    'status'          => Product::STATUS_ATIVO,
                ]);
            }

            // Já existe neste endereço?
            $existe = Stock::where('location_id', $location->id)
                ->where('product_id', $product->id)->exists();
            if ($existe) {
                return response()->json(['message' => 'Este produto já está neste endereço.'], 422);
            }

            Stock::create([
                'location_id' => $location->id,
                'product_id'  => $product->id,
                'quantidade'  => $dados['quantidade'] ?? 0,
            ]);

            return response()->json(['message' => 'Produto adicionado ao endereço.'], 201);
        });
    }

    /**
     * Ajusta o saldo de um produto num endereço (define a quantidade).
     *
     * PUT /api/locations/{location}/produtos/{stock}  { quantidade }
     */
    public function atualizarSaldo(Request $request, Location $location, Stock $stock): JsonResponse
    {
        if ($stock->location_id !== $location->id) {
            return response()->json(['message' => 'Item não pertence a este endereço.'], 404);
        }

        $dados = $request->validate([
            'quantidade' => ['required', 'numeric', 'min:0'],
        ]);

        $stock->update(['quantidade' => $dados['quantidade']]);

        return response()->json(['message' => 'Saldo atualizado.']);
    }

    /**
     * Remove um produto de um endereço (apaga o registro de estoque).
     *
     * DELETE /api/locations/{location}/produtos/{stock}
     */
    public function removerProduto(Location $location, Stock $stock): JsonResponse
    {
        if ($stock->location_id !== $location->id) {
            return response()->json(['message' => 'Item não pertence a este endereço.'], 404);
        }

        $stock->delete();

        return response()->json(['message' => 'Produto removido do endereço.']);
    }
}
