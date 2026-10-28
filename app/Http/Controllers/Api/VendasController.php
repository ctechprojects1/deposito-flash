<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Product;
use App\Models\VendaPedido;
use App\Models\WithdrawalRequest;
use App\Services\AlocacaoEnderecos;
use App\Services\VinculoProdutos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Vendas da Shopee/TikTok: a planilha de pedidos "A Enviar" vira separação
 * (por endereço, com baixa ao finalizar no Painel do Separador) e lista de
 * embalagem (um pacote por pedido, com rastreio).
 *
 * A planilha é lida no navegador e chega aqui só com os campos necessários.
 */
class VendasController extends Controller
{
    public function __construct(
        private readonly VinculoProdutos $vinculo,
        private readonly AlocacaoEnderecos $alocacao,
    ) {
    }

    /**
     * Grava/atualiza os pedidos da planilha. Pedido já separado só atualiza
     * status/rastreio (os itens ficam como foram separados).
     * POST /api/vendas/importar  { canal, pedidos: [{pedido, status, rastreio, envio, cliente, criado_em, pago_em, itens: [...]}] }
     */
    public function importar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'canal'                          => ['required', Rule::in(['shopee', 'tiktok'])],
            'pedidos'                        => ['required', 'array', 'min:1', 'max:3000'],
            'pedidos.*.pedido'               => ['required', 'string', 'max:60'],
            'pedidos.*.status'               => ['nullable', 'string', 'max:60'],
            'pedidos.*.rastreio'             => ['nullable', 'string', 'max:60'],
            'pedidos.*.envio'                => ['nullable', 'string', 'max:100'],
            'pedidos.*.cliente'              => ['nullable', 'string', 'max:191'],
            'pedidos.*.criado_em'            => ['nullable', 'string', 'max:30'],
            'pedidos.*.pago_em'              => ['nullable', 'string', 'max:30'],
            'pedidos.*.itens'                => ['required', 'array', 'min:1'],
            'pedidos.*.itens.*.sku_principal' => ['nullable', 'string', 'max:100'],
            'pedidos.*.itens.*.sku'          => ['nullable', 'string', 'max:100'],
            'pedidos.*.itens.*.nome'         => ['required', 'string', 'max:255'],
            'pedidos.*.itens.*.variacao'     => ['nullable', 'string', 'max:191'],
            'pedidos.*.itens.*.quantidade'   => ['required', 'numeric', 'gt:0'],
        ], [
            'pedidos.required' => 'Nenhum pedido encontrado na planilha.',
        ]);

        $r = ['novos' => 0, 'atualizados' => 0, 'ja_separados' => 0, 'itens_sem_produto' => 0];

        DB::transaction(function () use ($dados, &$r) {
            foreach ($dados['pedidos'] as $p) {
                $pedido = VendaPedido::firstOrNew(['canal' => $dados['canal'], 'pedido' => trim($p['pedido'])]);
                $novo = ! $pedido->exists;

                $pedido->fill([
                    'status'    => $this->limpo($p['status'] ?? null),
                    'envio'     => $this->limpo($p['envio'] ?? null),
                    'cliente'   => $this->limpo($p['cliente'] ?? null),
                    'criado_em' => $this->data($p['criado_em'] ?? null) ?? $pedido->criado_em,
                    'pago_em'   => $this->data($p['pago_em'] ?? null) ?? $pedido->pago_em,
                ]);
                // O rastreio só aparece depois que a Shopee processa: nunca apaga um que já veio.
                if ($rastreio = $this->limpo($p['rastreio'] ?? null)) {
                    $pedido->rastreio = $rastreio;
                }
                $pedido->save();

                if ($pedido->separado_em) {
                    $r['ja_separados']++;
                } else {
                    $pedido->itens()->delete();
                    foreach ($p['itens'] as $it) {
                        $product = $this->vinculo->resolver($it['sku'] ?? null, $it['sku_principal'] ?? null);
                        $pedido->itens()->create([
                            'sku_principal' => $this->limpo($it['sku_principal'] ?? null),
                            'sku'           => $this->limpo($it['sku'] ?? null),
                            'nome'          => trim($it['nome']),
                            'variacao'      => $this->limpo($it['variacao'] ?? null),
                            'quantidade'    => (float) $it['quantidade'],
                            'product_id'    => $product?->id,
                        ]);
                        if (! $product) {
                            $r['itens_sem_produto']++;
                        }
                    }
                }

                $novo ? $r['novos']++ : $r['atualizados']++;
            }
        });

        return response()->json(['resumo' => $r] + $this->dadosPainel());
    }

    /**
     * Situação das vendas do CD: a separar, a embalar e os pedidos pendentes.
     * GET /api/vendas
     */
    public function painel(): JsonResponse
    {
        return response()->json($this->dadosPainel());
    }

    /**
     * Lista de separação dos pedidos ainda não separados. Com simular=true só
     * monta a lista (não cria nada); senão cria a separação para o Painel do
     * Separador (a baixa sai de cada endereço quando o separador finaliza).
     * POST /api/vendas/separacao  { modo: novos|pagos_apos, pago_apos?, simular? }
     */
    public function separacao(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'modo'      => ['required', Rule::in(['novos', 'pagos_apos'])],
            'pago_apos' => ['nullable', 'required_if:modo,pagos_apos', 'date'],
            'simular'   => ['boolean'],
        ], [
            'pago_apos.required_if' => 'Informe a partir de que horário de pagamento.',
        ]);

        $pedidos = VendaPedido::query()
            ->whereNull('separado_em')
            ->when($dados['modo'] === 'pagos_apos', fn ($q) => $q->where('pago_em', '>', Carbon::parse($dados['pago_apos'])))
            ->with('itens.product')
            ->orderBy('pago_em')
            ->get()
            ->reject(fn (VendaPedido $p) => $p->cancelado())
            ->values();

        if ($pedidos->isEmpty()) {
            return response()->json(['message' => 'Nenhum pedido a separar com esse filtro.'], 422);
        }

        // Soma por produto; itens sem produto cadastrado vão à parte.
        $porProduto = [];
        $semProduto = [];
        foreach ($pedidos as $p) {
            foreach ($p->itens as $it) {
                if (! $it->product) {
                    $semProduto[] = ['pedido' => $p->pedido, 'nome' => $it->nomeCompleto(), 'sku' => $it->sku ?: $it->sku_principal, 'quantidade' => $it->quantidade];
                    continue;
                }
                $porProduto[$it->product_id] ??= ['product' => $it->product, 'quantidade' => 0.0, 'pedidos' => []];
                $porProduto[$it->product_id]['quantidade'] += $it->quantidade;
                $porProduto[$it->product_id]['pedidos'][] = $p->pedido;
            }
        }

        // Endereços de cada produto (mesma regra da solicitação pela nota).
        $linhas = [];
        foreach ($porProduto as $pid => $g) {
            foreach ($this->alocacao->alocar($g['product'], $g['quantidade']) as $parte) {
                $linhas[] = ['product' => $g['product'], 'location_id' => $parte['location_id'], 'quantidade' => $parte['quantidade'], 'pedidos' => array_values(array_unique($g['pedidos']))];
            }
        }

        $simular = (bool) ($dados['simular'] ?? false);
        $separacao = null;

        if (! $simular) {
            if (! $linhas) {
                return response()->json(['message' => 'Nenhum item destes pedidos está cadastrado no sistema. Cadastre os produtos antes de separar.'], 422);
            }

            $separacao = DB::transaction(function () use ($request, $pedidos, $linhas, $semProduto) {
                $canais = $pedidos->pluck('canal')->unique()->map(fn ($c) => $c === 'tiktok' ? 'TikTok' : 'Shopee')->implode(' + ');
                $obs = $semProduto
                    ? "Itens sem cadastro (separar à parte):\n" . collect($semProduto)->map(fn ($s) => "- {$s['nome']} (SKU {$s['sku']}) x{$s['quantidade']} · pedido {$s['pedido']}")->implode("\n")
                    : null;

                $s = WithdrawalRequest::create([
                    'solicitante_id'   => $request->user()->id,
                    'destino'          => "Vendas {$canais} — {$pedidos->count()} pedido(s)",
                    'observacao'       => $obs,
                    'tipo_documento'   => $pedidos->first()->canal,
                    'numero_documento' => now()->format('d/m H:i'),
                    'status'           => WithdrawalRequest::STATUS_PENDENTE,
                ]);
                foreach ($linhas as $l) {
                    $s->items()->create([
                        'product_id'            => $l['product']->id,
                        'codigo_microvix'       => $l['product']->codigo_microvix,
                        'descricao'             => $l['product']->nome,
                        'quantidade_documento'  => $l['quantidade'],
                        'quantidade_solicitada' => $l['quantidade'],
                        'quantidade_separada'   => 0,
                        'location_id'           => $l['location_id'],
                    ]);
                }

                VendaPedido::whereIn('id', $pedidos->pluck('id'))->update([
                    'withdrawal_request_id' => $s->id,
                    'separado_em'           => now(),
                    'separado_por_id'       => $request->user()->id,
                ]);

                return $s;
            });
        }

        return response()->json([
            'message'      => $separacao ? "Separação #{$separacao->id} criada: aparece no Painel do Separador." : null,
            'separacao_id' => $separacao?->id,
            'simulacao'    => $simular,
            'pedidos'      => $pedidos->count(),
            'unidades'     => array_sum(array_column($linhas, 'quantidade')) + array_sum(array_column($semProduto, 'quantidade')),
            'linhas'       => $this->formatarLinhas($linhas),
            'sem_produto'  => $semProduto,
        ] + ($separacao ? $this->dadosPainel() : []));
    }

    /**
     * Pacotes a embalar (um por pedido), com rastreio e itens.
     * GET /api/vendas/embalagem?todos=0&ordem=cliente|produto|rastreio
     */
    public function embalagem(Request $request): JsonResponse
    {
        $todos = $request->boolean('todos');
        $ordem = $request->query('ordem', 'cliente');

        $pedidos = VendaPedido::query()
            ->when(! $todos, fn ($q) => $q->whereNull('embalado_em'))
            ->with('itens.product')
            ->get()
            ->reject(fn (VendaPedido $p) => $p->cancelado());

        $pacotes = $pedidos->map(fn (VendaPedido $p) => [
            'id'         => $p->id,
            'canal'      => $p->canal,
            'pedido'     => $p->pedido,
            'cliente'    => $p->cliente,
            'rastreio'   => $p->rastreio,
            'envio'      => $p->envio,
            'separado'   => (bool) $p->separado_em,
            'embalado'   => (bool) $p->embalado_em,
            'quantidade' => $p->itens->sum('quantidade'),
            'itens'      => $p->itens->map(fn ($it) => [
                'nome'       => $it->nomeCompleto(),
                'sku'        => $it->sku ?: $it->sku_principal,
                'quantidade' => $it->quantidade,
                'cadastrado' => (bool) $it->product_id,
            ])->values(),
        ]);

        $porNome = fn ($a, $b) => strnatcasecmp(Str::ascii((string) $a), Str::ascii((string) $b));
        $pacotes = match ($ordem) {
            'produto'  => $pacotes->sort(fn ($a, $b) => $porNome($a['itens'][0]['nome'] ?? '', $b['itens'][0]['nome'] ?? '')),
            'rastreio' => $pacotes->sort(fn ($a, $b) => [! $a['rastreio'], $a['rastreio']] <=> [! $b['rastreio'], $b['rastreio']]),
            default    => $pacotes->sort(fn ($a, $b) => $porNome($a['cliente'] ?: 'zzz', $b['cliente'] ?: 'zzz')),
        };

        return response()->json([
            'pacotes'      => $pacotes->values(),
            'itens'        => $pacotes->sum('quantidade'),
            'com_rastreio' => $pacotes->filter(fn ($p) => $p['rastreio'])->count(),
            'sem_rastreio' => $pacotes->reject(fn ($p) => $p['rastreio'])->count(),
            'ja_embalados' => VendaPedido::whereNotNull('embalado_em')->count(),
        ]);
    }

    /**
     * Marca (ou desmarca) pacotes como embalados.
     * POST /api/vendas/embalar  { pedidos: [ids], desfazer?: bool }
     */
    public function embalar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'pedidos'   => ['required', 'array', 'min:1'],
            'pedidos.*' => ['integer'],
            'desfazer'  => ['boolean'],
        ]);

        $desfazer = (bool) ($dados['desfazer'] ?? false);
        $n = VendaPedido::whereIn('id', $dados['pedidos'])
            ->when(! $desfazer, fn ($q) => $q->whereNull('embalado_em'))
            ->update($desfazer
                ? ['embalado_em' => null, 'embalado_por_id' => null]
                : ['embalado_em' => now(), 'embalado_por_id' => $request->user()->id]);

        return response()->json([
            'message' => $desfazer ? "{$n} pacote(s) voltaram para a lista de embalagem." : "{$n} pacote(s) marcados como embalados.",
        ] + $this->dadosPainel());
    }

    /* ====================================================================== */

    private function dadosPainel(): array
    {
        // Só o que ainda tem trabalho: falta separar ou falta embalar.
        $ativos = VendaPedido::query()
            ->where(fn ($q) => $q->whereNull('separado_em')->orWhereNull('embalado_em'))
            ->with('itens')
            ->get()
            ->reject(fn (VendaPedido $p) => $p->cancelado());
        $aSeparar = $ativos->whereNull('separado_em');

        return [
            'a_separar'          => $aSeparar->count(),
            'a_separar_unidades' => $aSeparar->sum(fn ($p) => $p->itens->sum('quantidade')),
            'a_embalar'          => $ativos->whereNull('embalado_em')->count(),
            'sem_rastreio'       => $ativos->whereNull('embalado_em')->whereNull('rastreio')->count(),
            'itens_sem_produto'  => $aSeparar->sum(fn ($p) => $p->itens->whereNull('product_id')->count()),
            'ultima_importacao'  => optional(VendaPedido::max('updated_at'), fn ($d) => Carbon::parse($d)->format('d/m/Y H:i')),
            'pedidos_a_separar'  => $aSeparar->sortBy('pago_em')->map(fn (VendaPedido $p) => [
                'id'       => $p->id,
                'canal'    => $p->canal,
                'pedido'   => $p->pedido,
                'pago_em'  => $p->pago_em?->format('d/m H:i'),
                'itens'    => $p->itens->map(fn ($it) => [
                    'nome'       => $it->nomeCompleto(),
                    'sku'        => $it->sku ?: $it->sku_principal,
                    'quantidade' => $it->quantidade,
                    'cadastrado' => (bool) $it->product_id,
                ])->values(),
            ])->values(),
        ];
    }

    /**
     * Linhas da lista de separação, na ordem dos endereços (sem endereço por último),
     * com o saldo do endereço: "estoque X · retira Y · fica Z".
     *
     * @param  array<int, array{product: Product, location_id: ?int, quantidade: float, pedidos: string[]}>  $linhas
     */
    private function formatarLinhas(array $linhas): Collection
    {
        $locais = Location::whereIn('id', array_filter(array_column($linhas, 'location_id')))->get()->keyBy('id');

        return collect($linhas)->map(function ($l) use ($locais) {
            $loc = $l['location_id'] ? $locais->get($l['location_id']) : null;
            $saldo = $loc ? (float) $l['product']->stocks->firstWhere('location_id', $loc->id)?->quantidade : 0.0;

            return [
                'endereco'        => $loc?->nome,
                'time'            => $loc?->corredor,
                'posicao'         => $loc?->esteira,
                'product_id'      => $l['product']->id,
                'nome'            => $l['product']->nome,
                'sku'             => $l['product']->sku,
                'codigo_microvix' => $l['product']->codigo_microvix,
                'estoque'         => $saldo,
                'retira'          => $l['quantidade'],
                'fica'            => round($saldo - $l['quantidade'], 2),
                'pedidos'         => $l['pedidos'],
            ];
        })->sort(function ($a, $b) {
            if (! $a['endereco'] !== ! $b['endereco']) {
                return $a['endereco'] ? -1 : 1;
            }

            // Time (coluna) e posição; endereço sem Time cadastrado vai pelo nome.
            return strnatcasecmp(Str::ascii((string) ($a['time'] ?: $a['endereco'])), Str::ascii((string) ($b['time'] ?: $b['endereco'])))
                ?: strnatcasecmp((string) $a['posicao'], (string) $b['posicao'])
                ?: strnatcasecmp($a['nome'], $b['nome']);
        })->values();
    }

    private function limpo(?string $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }

    /** "2026-10-26 13:22" (Shopee) ou "26/10/2026 13:22" -> data; inválida -> null. */
    private function data(?string $v): ?Carbon
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        try {
            return preg_match('#^\d{1,2}/\d{1,2}/\d{4}#', $v)
                ? Carbon::createFromFormat('d/m/Y H:i', substr($v . ' 00:00', 0, 16))
                : Carbon::parse($v);
        } catch (\Throwable) {
            return null;
        }
    }
}
