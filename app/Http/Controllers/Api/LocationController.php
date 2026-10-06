<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryCount;
use App\Models\InventoryCountItem;
use App\Models\Location;
use App\Models\Product;
use App\Models\Stock;
use App\Models\WithdrawalItem;
use App\Models\WithdrawalRequest;
use App\Support\Historico;
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
                $campos = [
                    'nome'     => $nome,
                    'corredor' => $time,
                    'esteira'  => $pos,
                    'eixo_x'   => $base + ($lado === 'B' ? 1 : 0),
                    'eixo_y'   => $nivel,
                    'ativo'    => true,
                ];

                // Nome de um endereço excluído antes: reaproveita o registro.
                $loc = Location::onlyTrashed()->where('nome', $nome)->first();
                if ($loc) {
                    $loc->restore();
                    $loc->update($campos);
                } else {
                    $loc = Location::create($campos);
                }

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
     * Exclui endereços (um ou vários, ex.: um Time inteiro).
     * Só exclui endereço vazio e fora de solicitação/contagem em aberto;
     * os demais voltam em `bloqueados` com o motivo.
     *
     * POST /api/locations/excluir  { ids: [..] }
     */
    public function excluir(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        // Só enxerga endereços do CD atual.
        $locais = Location::whereIn('id', $dados['ids'])->get();
        $excluidos = [];
        $bloqueados = [];

        foreach ($locais as $loc) {
            $motivo = $this->motivoNaoExcluir($loc);
            if ($motivo) {
                $bloqueados[] = ['id' => $loc->id, 'nome' => $loc->nome, 'motivo' => $motivo];
                continue;
            }

            DB::transaction(function () use ($loc) {
                $loc->stocks()->delete(); // só registros zerados
                $loc->delete();
            });
            $excluidos[] = $loc->nome;
        }

        $msg = $excluidos
            ? count($excluidos) . ' endereço(s) excluído(s).'
            : 'Nenhum endereço excluído.';
        if ($bloqueados) {
            $msg .= ' Não excluído(s): ' . count($bloqueados) . '.';
        }

        return response()->json([
            'message'    => $msg,
            'excluidos'  => $excluidos,
            'bloqueados' => $bloqueados,
        ], $excluidos ? 200 : 422);
    }

    private function motivoNaoExcluir(Location $loc): ?string
    {
        $saldo = (float) $loc->stocks()->sum('quantidade');
        if ($saldo > 0) {
            return 'ainda tem ' . rtrim(rtrim(number_format($saldo, 2, ',', ''), '0'), ',')
                 . ' un. em estoque (zere ou movimente antes)';
        }

        $emSolicitacao = WithdrawalItem::where('location_id', $loc->id)
            ->whereHas('withdrawalRequest', fn ($q) => $q->whereIn('status', [
                WithdrawalRequest::STATUS_PENDENTE,
                WithdrawalRequest::STATUS_EM_SEPARACAO,
                WithdrawalRequest::STATUS_PAUSADA,
            ]))->exists();
        if ($emSolicitacao) {
            return 'está em uma solicitação ainda não finalizada';
        }

        $emContagem = InventoryCountItem::where('location_id', $loc->id)
            ->whereHas('inventoryCount', fn ($q) => $q->where('status', InventoryCount::STATUS_ABERTA))
            ->exists();
        if ($emContagem) {
            return 'está em uma contagem aberta';
        }

        return null;
    }

    /**
     * Zera o estoque de um endereço (todas as quantidades viram 0).
     *
     * POST /api/locations/{location}/zerar
     */
    public function zerar(Location $location): JsonResponse
    {
        // Um a um (e não update em massa) para cada zeragem entrar no histórico.
        $afetados = Historico::com('zerar_endereco', $location->nome, fn () => DB::transaction(function () use ($location) {
            $n = 0;
            foreach ($location->stocks()->where('quantidade', '!=', 0)->lockForUpdate()->get() as $st) {
                $st->update(['quantidade' => 0]);
                $n++;
            }
            return $n;
        }));

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
            'nome'            => ['required', 'string', 'min:3', 'max:191', 'regex:/\p{L}/u'],
            'codigo_barras'   => ['nullable', 'string', 'max:60'],
            'codigo_microvix' => ['nullable', 'string', 'max:60'],
            'sku'             => ['nullable', 'string', 'max:100'],
            'quantidade'      => ['nullable', 'numeric', 'min:0'],
        ], [
            'nome.regex' => 'A descrição precisa ter o nome do produto (não só números). Confira se a quantidade não foi digitada no campo Descrição.',
            'nome.min'   => 'A descrição precisa ter o nome do produto. Confira se a quantidade não foi digitada no campo Descrição.',
        ]);

        $codMicrovix = $dados['codigo_microvix'] ?? null;
        $codBarras   = $dados['codigo_barras'] ?? null;
        $sku         = trim((string) ($dados['sku'] ?? '')) ?: null;

        return DB::transaction(function () use ($dados, $location, $codMicrovix, $codBarras, $sku) {
            // Reaproveita produto existente pelo código (microvix ou barras); senão cria.
            $product = null;
            if ($codMicrovix) {
                $product = Product::where('codigo_microvix', $codMicrovix)->first();
            }
            if (! $product && $codBarras) {
                $product = Product::where('codigo_barras', $codBarras)->first();
            }
            if (! $product && $sku) {
                $product = Product::where('sku', $sku)->first();
            }

            if (! $product) {
                $product = Product::create([
                    'nome'            => $dados['nome'],
                    'codigo_microvix' => $codMicrovix ?: null,
                    'codigo_barras'   => $codBarras ?: null,
                    'sku'             => $sku,
                    'status'          => Product::STATUS_ATIVO,
                ]);
            } else {
                // Produto já existia: completa códigos que estiverem faltando
                // (ex.: veio do Microvix), sem colidir com outro produto.
                if (! $product->codigo_microvix && $codMicrovix) {
                    $product->codigo_microvix = $codMicrovix;
                }
                if (! $product->codigo_barras && $codBarras
                    && ! Product::where('codigo_barras', $codBarras)->where('id', '!=', $product->id)->exists()) {
                    $product->codigo_barras = $codBarras;
                }
                if (! $product->sku && $sku) {
                    $product->sku = $sku;
                }
                if ($product->isDirty()) {
                    $product->save();
                }
            }

            // Já existe neste endereço?
            $existe = Stock::where('location_id', $location->id)
                ->where('product_id', $product->id)->exists();
            if ($existe) {
                return response()->json(['message' => 'Este produto já está neste endereço.'], 422);
            }

            Historico::com('adicao', null, fn () => Stock::create([
                'location_id' => $location->id,
                'product_id'  => $product->id,
                'quantidade'  => $dados['quantidade'] ?? 0,
            ]));

            return response()->json(['message' => 'Produto adicionado ao endereço.'], 201);
        });
    }

    /**
     * Corrige a descrição de um produto (vale para todos os endereços).
     * PUT /api/products/{product}  { nome }
     */
    public function renomearProduto(Request $request, Product $product): JsonResponse
    {
        $dados = $request->validate([
            'nome' => ['required', 'string', 'min:3', 'max:191', 'regex:/\p{L}/u'],
        ], [
            'nome.regex' => 'A descrição precisa ter o nome do produto (não só números).',
            'nome.min'   => 'A descrição precisa ter o nome do produto (não só números).',
        ]);

        $product->update(['nome' => trim($dados['nome'])]);

        return response()->json(['message' => 'Descrição atualizada.', 'nome' => $product->nome]);
    }

    /**
     * Replica produtos (com quantidades) para este endereço.
     * Usa updateOrCreate: cria os que faltam e ajusta os já existentes.
     *
     * POST /api/locations/{location}/replicar
     *   { itens: [ { product_id, quantidade }, ... ] }
     */
    public function replicar(Request $request, Location $location): JsonResponse
    {
        $dados = $request->validate([
            'itens'                => ['required', 'array', 'min:1'],
            'itens.*.product_id'   => ['required', 'integer', 'exists:products,id'],
            'itens.*.quantidade'   => ['required', 'numeric', 'min:0'],
            'origem'               => ['nullable', 'string', 'max:120'],
        ]);

        $n = 0;
        Historico::com('replicacao', $dados['origem'] ?? null, fn () => DB::transaction(function () use ($dados, $location, &$n) {
            foreach ($dados['itens'] as $it) {
                Stock::updateOrCreate(
                    ['location_id' => $location->id, 'product_id' => $it['product_id']],
                    ['quantidade' => $it['quantidade']]
                );
                $n++;
            }
        }));

        return response()->json([
            'message' => "{$n} produto(s) replicado(s) para {$location->nome}.",
        ]);
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

        Historico::com('ajuste', null, fn () => $stock->update(['quantidade' => $dados['quantidade']]));

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

        Historico::com('remocao', null, fn () => $stock->delete());

        return response()->json(['message' => 'Produto removido do endereço.']);
    }
}
