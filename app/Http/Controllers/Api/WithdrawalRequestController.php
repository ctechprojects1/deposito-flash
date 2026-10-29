<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deposito;
use App\Models\Product;
use App\Models\VendaPedido;
use App\Models\VendaPedidoItem;
use App\Models\WithdrawalItem;
use App\Models\WithdrawalRequest;
use App\Services\AlocacaoEnderecos;
use App\Services\BuscaProdutos;
use App\Services\NotaExtractorService;
use App\Services\StockService;
use App\Support\Historico;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
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
    public function extrair(Request $request, NotaExtractorService $extrator, BuscaProdutos $busca): JsonResponse
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

        $itens = array_map(function ($it) use ($busca) {
            $item = $this->cruzarComDeposito($it);
            // Não bateu o código: sugere os parecidos do depósito para vincular.
            $item['sugestoes'] = $item['no_deposito'] ? [] : $busca
                ->sugerirParaItem((string) $it['codigo'], (string) $it['descricao'])
                ->map(fn ($x) => $busca->formatar($x))
                ->values()
                ->all();

            return $item;
        }, $lido['itens']);

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

                // Um item pode virar várias linhas (uma por endereço) quando
                // nenhum endereço sozinho tem a quantidade toda.
                $reservado = []; // product_id => [location_id => qtd]
                foreach ($dados['itens'] as $it) {
                    $pid = (int) $it['product_id'];
                    $partes = $this->alocarEnderecos(
                        Product::findOrFail($pid),
                        (float) $it['quantidade_solicitada'],
                        $reservado[$pid] ?? []
                    );

                    foreach ($partes as $p) {
                        $s->items()->create([
                            'product_id'            => $pid,
                            'codigo_microvix'       => $it['codigo_microvix'] ?? null,
                            'descricao'             => $it['descricao'] ?? null,
                            'quantidade_documento'  => $it['quantidade_documento'] ?? 0,
                            'quantidade_solicitada' => $p['quantidade'],
                            'quantidade_separada'   => 0,
                            'location_id'           => $p['location_id'],
                        ]);
                        if ($p['location_id']) {
                            $reservado[$pid][$p['location_id']] = ($reservado[$pid][$p['location_id']] ?? 0) + $p['quantidade'];
                        }
                    }
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
                // Resolvidos = retirados + marcados como não separados.
                'items as itens_retirados' => fn ($q) => $q->where(fn ($w) => $w->where('retirado', true)->orWhere('nao_separado', true)),
                'items as itens_nao_separados' => fn ($q) => $q->where('nao_separado', true)
                    ->where(fn ($w) => $w->whereNull('motivo_nao_separado')->orWhere('motivo_nao_separado', 'not like', WithdrawalItem::MOTIVO_LOJA . '%')),
                'items as itens_na_loja' => fn ($q) => $q->where('nao_separado', true)->where('motivo_nao_separado', 'like', WithdrawalItem::MOTIVO_LOJA . '%'),
            ])
            ->with(['solicitante', 'separador', 'excluidaPor'])
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
            'retirado'            => ['sometimes', 'boolean'],
            // Do CD atual ou de um CD no mesmo lugar físico (ex.: Commerce tirando de Goiânia).
            'location_id'         => ['sometimes', 'nullable', 'integer', Rule::exists('locations', 'id')->whereIn('deposito_id', Deposito::acessiveisNaSeparacao())->whereNull('deleted_at')],
            'nao_separado'        => ['sometimes', 'boolean'],
            'motivo_nao_separado' => ['required_if_accepted:nao_separado', 'nullable', 'string', 'max:255'],
        ], [
            'motivo_nao_separado.required_if_accepted' => 'Informe o motivo de o item não ter sido separado.',
            'location_id.exists' => 'Este endereço não é deste CD nem de um CD do mesmo lugar.',
        ]);

        if (array_key_exists('location_id', $dados)) {
            $item->location_id = $dados['location_id'];
        }
        if (array_key_exists('retirado', $dados)) {
            if ($dados['retirado'] && ! $item->location_id) {
                return response()->json([
                    'message' => 'Este item não tem endereço com estoque. Escolha um endereço ou marque como "Não separado".',
                ], 422);
            }
            $item->retirado = $dados['retirado'];
            $item->retirado_em = $dados['retirado'] ? now() : null;
            if ($dados['retirado']) {
                $item->nao_separado = false;
                $item->motivo_nao_separado = null;
            }
        }
        if (array_key_exists('nao_separado', $dados)) {
            $item->nao_separado = $dados['nao_separado'];
            $item->motivo_nao_separado = $dados['nao_separado'] ? trim($dados['motivo_nao_separado']) : null;
            if ($dados['nao_separado']) {
                $item->retirado = false;
                $item->retirado_em = null;
            }
        }
        $item->save();

        return response()->json(['data' => $this->detalhe($withdrawalRequest->fresh())]);
    }

    /**
     * O endereço não tem a quantidade toda: divide a linha entre endereços
     * (mesma regra da criação). Ex.: 20 un. em PAYSANDU 1A (10) -> 10 + 10.
     * POST .../{id}/itens/{item}/dividir
     */
    public function dividirItem(WithdrawalRequest $withdrawalRequest, WithdrawalItem $item): JsonResponse
    {
        if ($item->withdrawal_request_id !== $withdrawalRequest->id) {
            return response()->json(['message' => 'Item não pertence a esta solicitação.'], 404);
        }
        if ($withdrawalRequest->status !== WithdrawalRequest::STATUS_EM_SEPARACAO) {
            return response()->json(['message' => 'Inicie ou retome a separação para dividir o item.'], 409);
        }
        if ($item->retirado) {
            return response()->json(['message' => 'Desmarque o item antes de dividir.'], 422);
        }

        $partes = $this->alocarEnderecos(
            $item->product,
            (float) $item->quantidade_solicitada,
            $this->reservadoNaSolicitacao($withdrawalRequest, $item->product_id, $item->id)
        );

        if (count($partes) < 2) {
            $unica = $partes[0] ?? null;
            if (! $unica || ! $unica['location_id'] || $unica['location_id'] === $item->location_id) {
                return response()->json([
                    'message' => 'Não há outro endereço com saldo para completar este item neste CD.',
                ], 422);
            }

            // Um endereço sozinho dá conta: só troca.
            $item->update(['location_id' => $unica['location_id']]);
            return response()->json([
                'message' => 'Endereço trocado para ' . $item->fresh()->location?->nome . ', que tem a quantidade toda.',
                'data'    => $this->detalhe($withdrawalRequest->fresh()),
            ]);
        }

        DB::transaction(function () use ($item, $partes) {
            $primeira = array_shift($partes);
            $item->update([
                'location_id'           => $primeira['location_id'],
                'quantidade_solicitada' => $primeira['quantidade'],
            ]);

            foreach ($partes as $p) {
                $nova = $item->replicate(['retirado', 'retirado_em']);
                $nova->location_id = $p['location_id'];
                $nova->quantidade_solicitada = $p['quantidade'];
                $nova->quantidade_separada = 0;
                $nova->retirado = false;
                $nova->save();
            }
        });

        return response()->json([
            'message' => 'Item dividido em ' . count($partes) . ' endereços.',
            'data'    => $this->detalhe($withdrawalRequest->fresh()),
        ]);
    }

    /**
     * Finaliza: itens retirados -> baixa no estoque (tudo ou nada).
     * Itens ainda pendentes só com `motivo_pendentes` (pedido cancelado no meio
     * do processo, falha...): viram "não separado" com esse motivo, sem baixa.
     * POST .../{id}/finalizar   { motivo_pendentes? }
     */
    public function finalizar(Request $request, WithdrawalRequest $withdrawalRequest, StockService $stock): JsonResponse
    {
        $dados = $request->validate([
            'motivo_pendentes' => ['nullable', 'string', 'max:200'],
        ]);
        $motivoPendentes = trim((string) ($dados['motivo_pendentes'] ?? ''));

        $s = $withdrawalRequest->load(['items.product', 'items.location' => fn ($q) => $q->withoutGlobalScope('deposito')]);

        if ($s->status !== WithdrawalRequest::STATUS_EM_SEPARACAO) {
            return response()->json(['message' => 'Só dá para finalizar uma separação em andamento.'], 409);
        }

        $pendentes = $s->items->filter(fn ($i) => ! $i->retirado && ! $i->nao_separado);
        if ($pendentes->isNotEmpty() && $motivoPendentes === '') {
            return response()->json(['message' => "Ainda há {$pendentes->count()} item(ns) sem check de retirado. Marque-os ou informe o motivo para finalizar com pendências."], 422);
        }
        $retirados = $s->items->where('retirado', true);
        if ($semEndereco = $retirados->firstWhere('location_id', null)) {
            return response()->json(['message' => "Defina o endereço de retirada de: {$semEndereco->product?->nome}."], 422);
        }

        try {
            DB::transaction(function () use ($s, $stock, $retirados, $pendentes, $motivoPendentes) {
                foreach ($pendentes as $item) {
                    $item->update([
                        'nao_separado'        => true,
                        'motivo_nao_separado' => "Pendente ao finalizar — {$motivoPendentes}",
                    ]);
                }

                foreach ($retirados as $item) {
                    $qtd = (float) $item->quantidade_solicitada;
                    try {
                        Historico::com('separacao', "Solicitação #{$s->id}",
                            fn () => $stock->baixa($item->location_id, $item->product_id, $qtd), $s->destino);
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
            'message' => $pendentes->isNotEmpty()
                ? "Separação finalizada com {$pendentes->count()} item(ns) pendente(s), sem baixa. O estoque dos retirados foi baixado."
                : 'Separação finalizada e estoque baixado.',
            'data'    => $this->detalhe($s->fresh()),
        ]);
    }

    /**
     * Exclui (cancela) uma separação que ainda não foi finalizada, com
     * justificativa obrigatória. Não apaga: fica em "Excluídas" com o motivo,
     * quem excluiu e quando. Nada foi baixado do estoque antes de finalizar,
     * então não há estorno. Finalizada: o admin precisa reabrir antes.
     * DELETE .../{id}  { motivo }   (perm excluir_separacoes)
     */
    public function excluir(Request $request, WithdrawalRequest $withdrawalRequest): JsonResponse
    {
        $dados = $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'motivo.required' => 'Informe o motivo da exclusão.',
            'motivo.min'      => 'Descreva melhor o motivo da exclusão (mínimo 5 caracteres).',
        ]);

        $s = $withdrawalRequest;
        if ($s->status === WithdrawalRequest::STATUS_CONCLUIDA) {
            return response()->json([
                'message' => 'Esta separação já foi finalizada e baixou o estoque. Um administrador precisa reabri-la antes de excluir.',
            ], 409);
        }
        if ($s->status === WithdrawalRequest::STATUS_CANCELADA) {
            return response()->json(['message' => 'Esta separação já foi excluída.'], 409);
        }

        $s->update([
            'status'          => WithdrawalRequest::STATUS_CANCELADA,
            'excluida_em'     => now(),
            'excluida_por_id' => $request->user()->id,
            'motivo_exclusao' => trim($dados['motivo']),
        ]);

        // Separação de vendas (Shopee/TikTok) excluída: os pedidos voltam a "a separar".
        VendaPedido::where('withdrawal_request_id', $s->id)->update([
            'withdrawal_request_id' => null,
            'separado_em'           => null,
            'separado_por_id'       => null,
        ]);

        return response()->json([
            'message' => "Separação #{$s->id} excluída.",
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
                    Historico::com('estorno', "Solicitação #{$s->id}",
                        fn () => $stock->entrada($item->location_id, $item->product_id, $qtd), 'Reaberta por ' . $request->user()->name);
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
        return app(AlocacaoEnderecos::class)->locaisComSaldo($product);
    }

    /** Divide a quantidade entre endereços (ver AlocacaoEnderecos::alocar). */
    private function alocarEnderecos(Product $product, float $qtd, array $reservado = []): array
    {
        return app(AlocacaoEnderecos::class)->alocar($product, $qtd, $reservado);
    }

    /** Quanto as OUTRAS linhas da solicitação já vão tirar de cada endereço, para este produto. */
    private function reservadoNaSolicitacao(WithdrawalRequest $s, int $productId, ?int $ignorarItemId = null): array
    {
        $res = [];
        foreach ($s->items()->where('product_id', $productId)->get() as $i) {
            if ($i->id === $ignorarItemId || ! $i->location_id) {
                continue;
            }
            $res[$i->location_id] = ($res[$i->location_id] ?? 0) + (float) $i->quantidade_solicitada;
        }

        return $res;
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
            'itens_nao_separados' => (int) ($s->itens_nao_separados ?? 0),
            'itens_na_loja'    => (int) ($s->itens_na_loja ?? 0),
            'criada_em'        => $s->created_at?->format('d/m/Y H:i'),
            'finalizada_em'    => $s->finalizada_em?->format('d/m/Y H:i'),
            'excluida_em'      => $s->excluida_em?->format('d/m/Y H:i'),
            'excluida_por'     => $s->excluidaPor?->name,
            'motivo_exclusao'  => $s->motivo_exclusao,
        ];
    }

    private function detalhe(WithdrawalRequest $s): array
    {
        $s->load([
            'items.product.stocks.location',
            'items.location' => fn ($q) => $q->withoutGlobalScope('deposito'), // pode ser de um CD do mesmo lugar
            'solicitante', 'separador', 'reabertaPor', 'excluidaPor',
        ]);

        // CDs no mesmo lugar físico: o item pode sair de um endereço deles.
        $vizinhos = Deposito::vizinhos();
        $rotuloCd = fn (?string $nome) => $nome && ! preg_match('/^(cd|dep[oó]sito)\b/iu', $nome) ? "CD {$nome}" : $nome;
        $nomeEndereco = fn ($loc) => $loc && isset($vizinhos[$loc->deposito_id])
            ? $rotuloCd($vizinhos[$loc->deposito_id]) . ' · ' . $loc->nome
            : $loc?->nome;

        // Linhas do mesmo produto (item dividido entre endereços) ficam juntas,
        // na ordem em que o produto apareceu, com "parte 1 de 2", "2 de 2"...
        $primeiroId = $s->items->groupBy('product_id')->map(fn ($g) => $g->min('id'));
        $partes = $s->items->groupBy('product_id')->map->count();
        $ordem = [];
        $ordenados = $s->items->sortBy([
            fn ($a, $b) => $primeiroId[$a->product_id] <=> $primeiroId[$b->product_id],
            fn ($a, $b) => $a->id <=> $b->id,
        ]);

        // Separação gerada de vendas (Shopee/TikTok): SKU do anúncio de cada produto.
        $skusVenda = VendaPedidoItem::whereIn('vendas_pedido_id', VendaPedido::where('withdrawal_request_id', $s->id)->select('id'))
            ->whereNotNull('product_id')
            ->get(['product_id', 'sku', 'sku_principal'])
            ->groupBy('product_id')
            ->map(fn ($g) => $g->map(fn ($v) => $v->sku ?: $v->sku_principal)->filter()->unique()->values()->all());

        $itens = $ordenados->map(function (WithdrawalItem $i) use ($partes, &$ordem, $skusVenda, $vizinhos, $rotuloCd, $nomeEndereco) {
            $ordem[$i->product_id] = ($ordem[$i->product_id] ?? 0) + 1;
            $locais = $i->product ? $this->locaisComSaldo($i->product) : [];
            if ($i->product && $vizinhos) {
                foreach (app(AlocacaoEnderecos::class)->locaisOutrosCds($i->product) as $l) {
                    $locais[] = ['nome' => $rotuloCd($l['cd']) . ' · ' . $l['nome']] + $l;
                }
            }

            // O endereço escolhido aparece na lista mesmo se ficou sem saldo.
            if ($i->location_id && ! collect($locais)->contains('location_id', $i->location_id)) {
                $locais[] = ['location_id' => $i->location_id, 'nome' => $nomeEndereco($i->location), 'quantidade' => 0];
            }

            return [
                'item_id'               => $i->id,
                'product_id'            => $i->product_id,
                'produto'               => $i->product?->nome,
                'codigo_microvix'       => $i->codigo_microvix ?: $i->product?->codigo_microvix,
                'sku'                   => $i->product?->sku,
                'skus_venda'            => $skusVenda->get($i->product_id, []),
                'descricao_documento'   => $i->descricao,
                'quantidade_documento'  => (float) $i->quantidade_documento,
                'quantidade_solicitada' => (float) $i->quantidade_solicitada,
                'quantidade_separada'   => (float) $i->quantidade_separada,
                'location_id'           => $i->location_id,
                'endereco'              => $nomeEndereco($i->location),
                'locais'                => $locais,
                'retirado'              => (bool) $i->retirado,
                'nao_separado'          => (bool) $i->nao_separado,
                'motivo_nao_separado'   => $i->motivo_nao_separado,
                'parte'                 => $ordem[$i->product_id],
                'partes'                => $partes[$i->product_id],
            ];
        })->values();

        return array_merge($this->resumo($s), [
            'observacao'      => $s->observacao,
            'tem_documento'   => (bool) $s->anexo_nota_path,
            'iniciada_em'     => $s->iniciada_em?->format('d/m/Y H:i'),
            'reaberta_em'     => $s->reaberta_em?->format('d/m/Y H:i'),
            'reaberta_por'    => $s->reabertaPor?->name,
            'excluida_em'     => $s->excluida_em?->format('d/m/Y H:i'),
            'excluida_por'    => $s->excluidaPor?->name,
            'motivo_exclusao' => $s->motivo_exclusao,
            'total_itens'     => $itens->count(),
            'itens_retirados' => $itens->filter(fn ($i) => $i['retirado'] || $i['nao_separado'])->count(),
            'itens_nao_separados' => $itens->filter(fn ($i) => $i['nao_separado'] && ! $this->naLoja($i))->count(),
            'itens_na_loja'   => $itens->filter(fn ($i) => $i['nao_separado'] && $this->naLoja($i))->count(),
            'itens'           => $itens,
        ]);
    }

    /** Item marcado como "Separado na loja" (saiu da loja, sem baixa no depósito). */
    private function naLoja(array $item): bool
    {
        return str_starts_with((string) $item['motivo_nao_separado'], WithdrawalItem::MOTIVO_LOJA);
    }
}
