<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\WithdrawalRequest;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class WithdrawalRequestController extends Controller
{
    /**
     * Cria uma solicitação de retirada com seus itens e o anexo da Nota de Saída.
     *
     * POST /api/withdrawal-requests   (multipart/form-data — por causa do arquivo)
     */
    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'solicitante_id' => ['required', 'integer', 'exists:users,id'],
            'destino'        => ['required', 'string', 'max:255'],
            'observacao'     => ['nullable', 'string'],

            // Itens da solicitação.
            'itens'                           => ['required', 'array', 'min:1'],
            'itens.*.product_id'              => ['required', 'integer', 'exists:products,id'],
            'itens.*.location_id'             => ['nullable', 'integer', 'exists:locations,id'],
            'itens.*.quantidade_solicitada'   => ['required', 'numeric', 'gt:0'],

            // Anexo da Nota de Saída: obrigatório, PDF ou imagem, até 5 MB.
            'anexo_nota' => [
                'required',
                'file',
                'max:5120', // 5 MB em KB
                // 'mimes' checa a extensão; 'mimetypes' checa o conteúdo real do arquivo.
                'mimes:pdf,jpg,jpeg,png,webp',
                'mimetypes:application/pdf,image/jpeg,image/png,image/webp',
            ],
        ], [
            'anexo_nota.mimes'     => 'A nota deve ser um PDF ou imagem (jpg, jpeg, png, webp).',
            'anexo_nota.mimetypes' => 'O conteúdo do arquivo não corresponde a um PDF/imagem válido.',
            'anexo_nota.max'       => 'A nota não pode exceder 5 MB.',
        ]);

        // Camada extra de segurança: valida a imagem de verdade (evita arquivo
        // renomeado com extensão de imagem). PDFs não passam por isso.
        $arquivo = $request->file('anexo_nota');
        if (str_starts_with($arquivo->getMimeType(), 'image/') && @getimagesize($arquivo->getRealPath()) === false) {
            return response()->json([
                'message' => 'O arquivo de imagem enviado está corrompido ou é inválido.',
            ], 422);
        }

        // Persiste solicitação + itens de forma atômica; se algo falhar,
        // ainda removemos o arquivo já gravado para não deixar lixo.
        $caminhoAnexo = null;

        try {
            $solicitacao = DB::transaction(function () use ($dados, $arquivo, &$caminhoAnexo) {
                // Salva em storage/app/public/notas (acessível via `php artisan storage:link`).
                $caminhoAnexo = $arquivo->store('notas', 'public');

                $solicitacao = WithdrawalRequest::create([
                    'solicitante_id'  => $dados['solicitante_id'],
                    'destino'         => $dados['destino'],
                    'observacao'      => $dados['observacao'] ?? null,
                    'anexo_nota_path' => $caminhoAnexo,
                    'status'          => WithdrawalRequest::STATUS_PENDENTE,
                ]);

                foreach ($dados['itens'] as $item) {
                    $solicitacao->items()->create([
                        'product_id'            => $item['product_id'],
                        'location_id'           => $item['location_id'] ?? null,
                        'quantidade_solicitada' => $item['quantidade_solicitada'],
                        'quantidade_separada'   => 0,
                    ]);
                }

                return $solicitacao;
            });
        } catch (\Throwable $e) {
            // Rollback do arquivo caso a transação tenha falhado após o upload.
            if ($caminhoAnexo && Storage::disk('public')->exists($caminhoAnexo)) {
                Storage::disk('public')->delete($caminhoAnexo);
            }

            report($e);

            return response()->json([
                'message' => 'Não foi possível registrar a solicitação. Tente novamente.',
            ], 500);
        }

        return response()->json([
            'message' => 'Solicitação registrada com sucesso.',
            'data'    => $this->transformar($solicitacao),
        ], 201);
    }

    /**
     * Lista as solicitações — por padrão, as pendentes (fila do separador).
     *
     * GET /api/withdrawal-requests?status=pendente
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', WithdrawalRequest::STATUS_PENDENTE);

        $solicitacoes = WithdrawalRequest::query()
            ->when($status !== 'todas', fn ($q) => $q->where('status', $status))
            ->with(['items.product.stocks.location', 'items.location', 'solicitante', 'separador'])
            ->latest()
            ->get()
            ->map(fn ($s) => $this->transformar($s));

        return response()->json(['data' => $solicitacoes]);
    }

    /**
     * Detalha uma solicitação.
     *
     * GET /api/withdrawal-requests/{withdrawalRequest}
     */
    public function show(WithdrawalRequest $withdrawalRequest): JsonResponse
    {
        return response()->json([
            'data' => $this->transformar($withdrawalRequest),
        ]);
    }

    /**
     * O separador inicia a separação: assume a solicitação e muda o status.
     *
     * POST /api/withdrawal-requests/{withdrawalRequest}/iniciar
     */
    public function iniciar(Request $request, WithdrawalRequest $withdrawalRequest): JsonResponse
    {
        // Em produção o separador vem do usuário autenticado (auth()->id()).
        $dados = $request->validate([
            'separador_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        if ($withdrawalRequest->status !== WithdrawalRequest::STATUS_PENDENTE) {
            return response()->json([
                'message' => 'Esta solicitação não está mais pendente.',
            ], 409);
        }

        $withdrawalRequest->update([
            'separador_id' => $dados['separador_id'],
            'status'       => WithdrawalRequest::STATUS_EM_SEPARACAO,
        ]);

        return response()->json([
            'message' => 'Separação iniciada.',
            'data'    => $this->transformar($withdrawalRequest->fresh()),
        ]);
    }

    /**
     * Confirma a retirada: dá baixa no estoque de cada item e conclui.
     *
     * POST /api/withdrawal-requests/{withdrawalRequest}/confirmar
     *
     * Para cada item, usa o local informado ou escolhe automaticamente um
     * endereço com estoque suficiente. Se algum item não tiver estoque,
     * nada é baixado (transação atômica) e o erro é reportado.
     */
    public function confirmar(WithdrawalRequest $withdrawalRequest, StockService $stockService): JsonResponse
    {
        if ($withdrawalRequest->status === WithdrawalRequest::STATUS_CONCLUIDA) {
            return response()->json(['message' => 'Solicitação já concluída.'], 409);
        }
        if ($withdrawalRequest->status === WithdrawalRequest::STATUS_CANCELADA) {
            return response()->json(['message' => 'Solicitação cancelada não pode ser separada.'], 409);
        }

        $withdrawalRequest->load('items');

        try {
            DB::transaction(function () use ($withdrawalRequest, $stockService) {
                foreach ($withdrawalRequest->items as $item) {
                    $quantidade = (float) $item->quantidade_solicitada;

                    // Local a retirar: o já definido, ou o de maior estoque com saldo suficiente.
                    $locationId = $item->location_id ?? $this->melhorLocalPara($item->product_id, $quantidade);

                    if (! $locationId) {
                        throw new RuntimeException(
                            "Sem estoque suficiente para o produto #{$item->product_id}."
                        );
                    }

                    $stockService->baixa($locationId, $item->product_id, $quantidade);

                    $item->update([
                        'location_id'         => $locationId,
                        'quantidade_separada' => $quantidade,
                    ]);
                }

                $withdrawalRequest->update(['status' => WithdrawalRequest::STATUS_CONCLUIDA]);
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Retirada confirmada e baixa realizada no estoque.',
            'data'    => $this->transformar($withdrawalRequest->fresh(['items.product', 'items.location'])),
        ]);
    }

    /**
     * Retorna o endereço com maior saldo que comporte a quantidade pedida.
     */
    private function melhorLocalPara(int $productId, float $quantidade): ?int
    {
        return Stock::query()
            ->where('product_id', $productId)
            ->where('quantidade', '>=', $quantidade)
            ->orderByDesc('quantidade')
            ->value('location_id');
    }

    /**
     * Serializa uma solicitação para a resposta da API, incluindo, por item,
     * os locais onde o produto tem estoque (para o separador saber aonde ir).
     */
    private function transformar(WithdrawalRequest $s): array
    {
        $s->loadMissing(['items.product.stocks.location', 'items.location', 'solicitante', 'separador']);

        return [
            'id'          => $s->id,
            'status'      => $s->status,
            'destino'     => $s->destino,
            'observacao'  => $s->observacao,
            'anexo_url'   => $s->anexo_nota_path ? Storage::disk('public')->url($s->anexo_nota_path) : null,
            'solicitante' => $s->solicitante?->name,
            'separador'   => $s->separador?->name,
            'criada_em'   => $s->created_at?->toDateTimeString(),
            'itens'       => $s->items->map(function ($i) {
                // Locais com estoque para este produto (sugestão de coleta).
                $locais = ($i->product?->stocks ?? collect())
                    ->where('quantidade', '>', 0)
                    ->sortByDesc('quantidade')
                    ->map(fn ($st) => [
                        'location_id' => $st->location_id,
                        'nome'        => $st->location?->nome,
                        'quantidade'  => (float) $st->quantidade,
                    ])->values();

                return [
                    'item_id'               => $i->id,
                    'product_id'            => $i->product_id,
                    'produto'               => $i->product?->nome,
                    'codigo_microvix'       => $i->product?->codigo_microvix,
                    'quantidade_solicitada' => (float) $i->quantidade_solicitada,
                    'quantidade_separada'   => (float) $i->quantidade_separada,
                    'location_id'           => $i->location_id,
                    'local_definido'        => $i->location?->nome,
                    'locais_disponiveis'    => $locais,
                ];
            })->values(),
        ];
    }
}
