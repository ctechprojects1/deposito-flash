<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\WithdrawalItem;
use App\Models\WithdrawalRequest;
use App\Services\NotaExtractorService;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Solicitação de retirada a partir de nota/pedido + separação por checklist.
 *
 * Status: pendente -> em_separacao <-> pausada -> concluida
 * A baixa no estoque acontece ao FINALIZAR. Só admin reabre (com estorno).
 */
class WithdrawalRequestController extends Controller
{
    private const REGRA_PDF = ['required', 'file', 'max:10240', 'mimes:pdf', 'mimetypes:application/pdf'];

    /* ======================================================================
     |  Solicitante
     ====================================================================== */

    /**
     * Lê o PDF da nota/pedido e devolve os itens cruzados com o depósito.
     * POST /api/withdrawal-requests/extrair  (multipart: documento)
     */
    public function extrair(Request $request, NotaExtractorService $extrator): JsonResponse
    {
        $request->validate(['documento' => self::REGRA_PDF], [
            'documento.mimes' => 'Envie a nota ou o pedido em PDF.',
            'documento.max'   => 'O PDF não pode passar de 10 MB.',
        ]);

        $pdf = base64_encode(file_get_contents($request->file('documento')->getRealPath()));
        $lido = $extrator->extrair($pdf);

        if ($lido['erro']) {
            return response()->json(['message' => $lido['erro']], 422);
        }

        $itens = array_map(fn ($it) => $this->cruzarComDeposito($it), $lido['itens']);

        return response()->json([
            'data' => [
                'tipo'    => $lido['tipo'],
                'numero'  => $lido['numero'],
                'destino' => $lido['destino'],
                'itens'   => $itens,
            ],
        ]);
    }

    /**
     * Cria a solicitação só com os itens marcados no checklist.
     * POST /api/withdrawal-requests  (multipart: anexo_nota + campos + itens[])
     */
    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'destino'          => ['required', 'string', 'max:255'],
            'observacao'       => ['nullable', 'string'],
            'tipo_documento'   => ['nullable', 'string', 'max:30'],
            'numero_documento' => ['nullable', 'string', 'max:100'],

            'itens'                         => ['required', 'array', 'min:1'],
            'itens.*.product_id'            => ['required', 'integer', 'exists:products,id'],
            'itens.*.codigo_microvix'       => ['nullable', 'string', 'max:60'],
            'itens.*.descricao'             => ['nullable', 'string', 'max:255'],
            'itens.*.quantidade_documento'  => ['nullable', 'numeric', 'min:0'],
            'itens.*.quantidade_solicitada' => ['required', 'numeric', 'gt:0'],

            'anexo_nota' => self::REGRA_PDF,
        ], [
            'itens.required'  => 'Marque pelo menos um item para retirar do depósito.',
            'anexo_nota.mimes' => 'O documento deve ser um PDF.',
        ]);

        $caminho = null;

        try {
            $solicitacao = DB::transaction(function () use ($dados, $request, &$caminho) {
                $caminho = $request->file('anexo_nota')->store('notas', 'public');

                $s = WithdrawalRequest::create([
                    'solicitante_id'   => $request->user()->id,
                    'destino'          => $dados['destino'],
                    'observacao'       => $dados['observacao'] ?? null,
                    'tipo_documento'   => $dados['tipo_documento'] ?? null,
                    'numero_documento' => $dados['numero_documento'] ?? null,
                    'anexo_nota_path'  => $caminho,
                    'status'           => WithdrawalRequest::STATUS_PENDENTE,
                ]);

                foreach ($dados['itens'] as $it) {
                    $qtd = (float) $it['quantidade_solicitada'];
                    $s->items()->create([
                        'product_id'            => $it['product_id'],
                        'codigo_microvix'       => $it['codigo_microvix'] ?? null,
                        'descricao'             => $it['descricao'] ?? null,
                        'quantidade_documento'  => $it['quantidade_documento'] ?? 0,
                        'quantidade_solicitada' => $qtd,
                        'quantidade_separada'   => 0,
                        'location_id'           => $this->enderecoSugerido((int) $it['product_id'], $qtd),
                    ]);
                }

                return $s;
            });
        } catch (\Throwable $e) {
            if ($caminho && Storage::disk('public')->exists($caminho)) {
                Storage::disk('public')->delete($caminho);
            }
            report($e);

            return response()->json(['message' => 'Não foi possível registrar a solicitação. Tente novamente.'], 500);
        }

        return response()->json([
            'message' => 'Solicitação enviada para separação.',
            'data'    => $this->detalhe($solicitacao),
        ], 201);
    }

    /* ======================================================================
     |  Separador
     ====================================================================== */

    /**
     * Fila de separação. ?status=pendente,pausada (vírgula) ou "todas".
     * GET /api/withdrawal-requests
     */
    public function index(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', 'pendente,em_separacao,pausada');

        $lista = WithdrawalRequest::query()
            ->when($status !== 'todas', fn ($q) => $q->whereIn('status', explode(',', $status)))
            ->withCount([
                'items',
                'items as itens_retirados' => fn ($q) => $q->where('retirado', true),
            ])
            ->with(['solicitante', 'separador'])
            ->latest()
            ->limit(200)
            ->get()
            ->map(fn ($s) => $this->resumo($s));

        return response()->json(['data' => $lista]);
    }

    /** GET /api/withdrawal-requests/{withdrawalRequest} */
    public function show(WithdrawalRequest $withdrawalRequest): JsonResponse
    {
        return response()->json(['data' => $this->detalhe($withdrawalRequest)]);
    }

    /**
     * PDF anexado. Servido pela API (com token) porque no HostGator não há
     * o link public/storage. GET .../{id}/documento
     */
    public function documento(WithdrawalRequest $withdrawalRequest)
    {
        $path = $withdrawalRequest->anexo_nota_path;
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return response()->json(['message' => 'Documento não encontrado.'], 404);
        }

        return Storage::disk('public')->response($path, "solicitacao-{$withdrawalRequest->id}.pdf", [
            'Content-Type' => 'application/pdf',
        ], 'inline');
    }

    /** Inicia ou retoma. POST .../{id}/iniciar */
    public function iniciar(Request $request, WithdrawalRequest $withdrawalRequest): JsonResponse
    {
        $s = $withdrawalRequest;
        if (! in_array($s->status, [WithdrawalRequest::STATUS_PENDENTE, WithdrawalRequest::STATUS_PAUSADA], true)) {
            return response()->json(['message' => 'Esta separação não pode ser iniciada agora.'], 409);
        }

        $s->update([
            'status'       => WithdrawalRequest::STATUS_EM_SEPARACAO,
            'separador_id' => $request->user()->id,
            'iniciada_em'  => $s->iniciada_em ?? now(),
        ]);

        return response()->json(['message' => 'Separação em andamento.', 'data' => $this->detalhe($s->fresh())]);
    }

    /** POST .../{id}/pausar */
    public function pausar(WithdrawalRequest $withdrawalRequest): JsonResponse
    {
        $s = $withdrawalRequest;
        if ($s->status !== WithdrawalRequest::STATUS_EM_SEPARACAO) {
            return response()->json(['message' => 'Só dá para pausar uma separação em andamento.'], 409);
        }

        $s->update(['status' => WithdrawalRequest::STATUS_PAUSADA]);

        return response()->json(['message' => 'Separação pausada.', 'data' => $this->detalhe($s->fresh())]);
    }

    /**
     * Marca/desmarca um item como retirado e/ou troca o endereço de retirada.
     * PUT .../{id}/itens/{item}  { retirado?, location_id? }
     */
    public function atualizarItem(Request $request, WithdrawalRequest $withdrawalRequest, WithdrawalItem $item): JsonResponse
    {
        if ($item->withdrawal_request_id !== $withdrawalRequest->id) {
            return response()->json(['message' => 'Item não pertence a esta solicitação.'], 404);
        }
        if ($withdrawalRequest->status !== WithdrawalRequest::STATUS_EM_SEPARACAO) {
            return response()->json(['message' => 'Inicie ou retome a separação para marcar os itens.'], 409);
        }

        $dados = $request->validate([
            'retirado'    => ['sometimes', 'boolean'],
            'location_id' => ['sometimes', 'nullable', 'integer', 'exists:locations,id'],
        ]);

        if (array_key_exists('location_id', $dados)) {
            $item->location_id = $dados['location_id'];
        }
        if (array_key_exists('retirado', $dados)) {
            $item->retirado = $dados['retirado'];
            $item->retirado_em = $dados['retirado'] ? now() : null;
        }
        $item->save();

        return response()->json(['data' => $this->detalhe($withdrawalRequest->fresh())]);
    }

    /**
     * Finaliza: todos os itens marcados -> baixa no estoque (tudo ou nada).
     * POST .../{id}/finalizar
     */
    public function finalizar(WithdrawalRequest $withdrawalRequest, StockService $stock): JsonResponse
    {
        $s = $withdrawalRequest->load('items.product', 'items.location');

        if ($s->status !== WithdrawalRequest::STATUS_EM_SEPARACAO) {
            return response()->json(['message' => 'Só dá para finalizar uma separação em andamento.'], 409);
        }

        $faltando = $s->items->where('retirado', false)->count();
        if ($faltando > 0) {
            return response()->json(['message' => "Ainda há {$faltando} item(ns) sem check de retirado."], 422);
        }
        if ($semEndereco = $s->items->firstWhere('location_id', null)) {
            return response()->json(['message' => "Defina o endereço de retirada de: {$semEndereco->product?->nome}."], 422);
        }

        try {
            DB::transaction(function () use ($s, $stock) {
                foreach ($s->items as $item) {
                    $qtd = (float) $item->quantidade_solicitada;
                    try {
                        $stock->baixa($item->location_id, $item->product_id, $qtd);
                    } catch (RuntimeException $e) {
                        throw new RuntimeException("{$item->product?->nome} em {$item->location?->nome}: {$e->getMessage()}");
                    }
                    $item->update(['quantidade_separada' => $qtd]);
                }

                $s->update([
                    'status'        => WithdrawalRequest::STATUS_CONCLUIDA,
                    'finalizada_em' => now(),
                ]);
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Separação finalizada e estoque baixado.',
            'data'    => $this->detalhe($s->fresh()),
        ]);
    }

    /**
     * Admin reabre uma separação finalizada: devolve o estoque (estorno).
     * POST .../{id}/reabrir   (perm admin)
     */
    public function reabrir(Request $request, WithdrawalRequest $withdrawalRequest, StockService $stock): JsonResponse
    {
        $s = $withdrawalRequest->load('items');

        if ($s->status !== WithdrawalRequest::STATUS_CONCLUIDA) {
            return response()->json(['message' => 'Só dá para reabrir uma separação finalizada.'], 409);
        }

        DB::transaction(function () use ($s, $stock, $request) {
            foreach ($s->items as $item) {
                $qtd = (float) $item->quantidade_separada;
                if ($qtd > 0 && $item->location_id) {
                    $stock->entrada($item->location_id, $item->product_id, $qtd);
                }
                $item->update(['quantidade_separada' => 0]);
            }

            $s->update([
                'status'          => WithdrawalRequest::STATUS_EM_SEPARACAO,
                'finalizada_em'   => null,
                'reaberta_em'     => now(),
                'reaberta_por_id' => $request->user()->id,
            ]);
        });

        return response()->json([
            'message' => 'Separação reaberta e estoque devolvido. Finalize de novo quando terminar.',
            'data'    => $this->detalhe($s->fresh()),
        ]);
    }

    /* ======================================================================
     |  Apoio
     ====================================================================== */

    /** Cruza um item lido do documento com o cadastro/estoque do depósito. */
    private function cruzarComDeposito(array $it): array
    {
        $cod = $it['codigo'];
        $product = Product::where('codigo_microvix', $cod)->first()
            ?? (ltrim($cod, '0') !== $cod ? Product::where('codigo_microvix', ltrim($cod, '0'))->first() : null);

        $locais = $product ? $this->locaisComSaldo($product) : [];

        return [
            'codigo'           => $cod,
            'descricao'        => $it['descricao'],
            'quantidade'       => $it['quantidade'],
            'no_deposito'      => (bool) $product,
            'product_id'       => $product?->id,
            'nome_deposito'    => $product?->nome,
            'estoque_total'    => array_sum(array_column($locais, 'quantidade')),
            'locais'           => $locais,
        ];
    }

    private function locaisComSaldo(Product $product): array
    {
        $product->loadMissing('stocks.location');

        return $product->stocks
            ->where('quantidade', '>', 0)
            ->sortByDesc('quantidade')
            ->map(fn ($st) => [
                'location_id' => $st->location_id,
                'nome'        => $st->location?->nome,
                'quantidade'  => (float) $st->quantidade,
            ])
            ->values()
            ->all();
    }

    /** Endereço com mais saldo que comporte a quantidade (ou o de mais saldo). */
    private function enderecoSugerido(int $productId, float $qtd): ?int
    {
        $locais = $this->locaisComSaldo(Product::findOrFail($productId));
        foreach ($locais as $l) {
            if ($l['quantidade'] >= $qtd) {
                return $l['location_id'];
            }
        }

        return $locais[0]['location_id'] ?? null;
    }

    private function resumo(WithdrawalRequest $s): array
    {
        return [
            'id'               => $s->id,
            'status'           => $s->status,
            'destino'          => $s->destino,
            'tipo_documento'   => $s->tipo_documento,
            'numero_documento' => $s->numero_documento,
            'solicitante'      => $s->solicitante?->name,
            'separador'        => $s->separador?->name,
            'total_itens'      => (int) ($s->items_count ?? 0),
            'itens_retirados'  => (int) ($s->itens_retirados ?? 0),
            'criada_em'        => $s->created_at?->format('d/m/Y H:i'),
            'finalizada_em'    => $s->finalizada_em?->format('d/m/Y H:i'),
        ];
    }

    private function detalhe(WithdrawalRequest $s): array
    {
        $s->load(['items.product.stocks.location', 'items.location', 'solicitante', 'separador', 'reabertaPor']);

        $itens = $s->items->map(function (WithdrawalItem $i) {
            $locais = $i->product ? $this->locaisComSaldo($i->product) : [];

            // O endereço escolhido aparece na lista mesmo se ficou sem saldo.
            if ($i->location_id && ! collect($locais)->contains('location_id', $i->location_id)) {
                $locais[] = ['location_id' => $i->location_id, 'nome' => $i->location?->nome, 'quantidade' => 0];
            }

            return [
                'item_id'               => $i->id,
                'product_id'            => $i->product_id,
                'produto'               => $i->product?->nome,
                'codigo_microvix'       => $i->codigo_microvix ?: $i->product?->codigo_microvix,
                'descricao_documento'   => $i->descricao,
                'quantidade_documento'  => (float) $i->quantidade_documento,
                'quantidade_solicitada' => (float) $i->quantidade_solicitada,
                'quantidade_separada'   => (float) $i->quantidade_separada,
                'location_id'           => $i->location_id,
                'endereco'              => $i->location?->nome,
                'locais'                => $locais,
                'retirado'              => (bool) $i->retirado,
            ];
        })->values();

        return array_merge($this->resumo($s), [
            'observacao'      => $s->observacao,
            'tem_documento'   => (bool) $s->anexo_nota_path,
            'iniciada_em'     => $s->iniciada_em?->format('d/m/Y H:i'),
            'reaberta_em'     => $s->reaberta_em?->format('d/m/Y H:i'),
            'reaberta_por'    => $s->reabertaPor?->name,
            'total_itens'     => $itens->count(),
            'itens_retirados' => $itens->where('retirado', true)->count(),
            'itens'           => $itens,
        ]);
    }
}
