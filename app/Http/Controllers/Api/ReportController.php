<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\MicrovixPreco;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockLog;
use App\Models\User;
use App\Services\BuscaProdutos;
use App\Services\MicrovixService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    /**
     * Relatório: Produto × Localização (também usado pela busca do mapa).
     * Busca por código Microvix, código de barras ou descrição, tolerante a
     * erro: `data` = encontrados; `sugestoes` = parecidos ("você quis dizer").
     *
     * GET /api/reports/produto-localizacao?q=...   (perm relatorios)
     * GET /api/products/buscar?q=...               (perm ver_mapa)
     */
    public function produtoLocalizacao(Request $request, BuscaProdutos $busca): JsonResponse
    {
        $r = $busca->buscar((string) $request->query('q', ''));

        return response()->json([
            'data'      => $r['exatos']->map(fn ($x) => $busca->formatar($x))->values(),
            'sugestoes' => $r['parecidos']->map(fn ($x) => $busca->formatar($x))->values(),
        ]);
    }

    /**
     * Mapa do CD atual para planilha: uma linha por produto em cada endereço
     * (só saldo > 0), com descrição, código, referência, quantidade e o preço
     * de venda do Microvix (base local; atualize antes com POST .../precos).
     *
     * GET /api/reports/mapa   (perm relatorios)
     */
    public function mapa(): JsonResponse
    {
        $stocks = Stock::query()
            ->where('quantidade', '>', 0)
            ->whereHas('location')
            ->with(['location', 'product'])
            ->get();

        $precos = MicrovixPreco::whereIn('cod_produto', $stocks->pluck('product.codigo_microvix')->filter()->unique()->values())
            ->pluck('preco_venda', 'cod_produto');

        $linhas = $stocks->map(function (Stock $s) use ($precos) {
            $preco = $precos[(string) $s->product?->codigo_microvix] ?? null;
            $preco = $preco !== null ? (float) $preco : null;
            $qtd = (float) $s->quantidade;

            return [
                'endereco'    => $s->location->nome,
                'time'        => $s->location->corredor,
                'posicao'     => $s->location->esteira,
                'descricao'   => $s->product?->nome,
                'codigo'      => $s->product?->codigo_microvix,
                'referencia'  => $s->product?->sku,
                'quantidade'  => $qtd,
                'preco'       => $preco,
                'valor_total' => $preco !== null ? round($preco * $qtd, 2) : null,
            ];
        })->sort(fn ($a, $b) => strnatcasecmp($a['endereco'], $b['endereco']) ?: strnatcasecmp((string) $a['descricao'], (string) $b['descricao']))
            ->values();

        return response()->json([
            'data'       => $linhas,
            'com_preco'  => MicrovixPreco::exists(),
            'sem_preco'  => $linhas->whereNull('preco')->count(),
        ]);
    }

    /**
     * Atualiza a base local de preços do Microvix (um lote; repita até
     * `concluido`). Usado pela exportação do mapa antes de gerar a planilha.
     *
     * POST /api/reports/mapa/precos   (perm relatorios)
     */
    public function atualizarPrecos(MicrovixService $microvix): JsonResponse
    {
        if (! $microvix->configurado()) {
            return response()->json(['concluido' => true, 'total' => MicrovixPreco::count(), 'configurado' => false]);
        }

        return response()->json($microvix->sincronizarPrecos(3) + ['configurado' => true]);
    }

    /**
     * Histórico de estoque do CD atual (mais recentes primeiro), 100 por página.
     *
     * GET /api/reports/historico?de=2026-09-01&ate=2026-09-30&produto=450
     *     &endereco=CORINTHIANS&tipo=separacao&user_id=3&pagina=1
     */
    public function historico(Request $request): JsonResponse
    {
        $f = $request->validate([
            'de'       => ['nullable', 'date'],
            'ate'      => ['nullable', 'date'],
            'produto'  => ['nullable', 'string', 'max:120'],
            'endereco' => ['nullable', 'string', 'max:120'],
            'tipo'     => ['nullable', Rule::in(array_keys(StockLog::TIPOS))],
            'user_id'  => ['nullable', 'integer'],
            'pagina'   => ['nullable', 'integer', 'min:1'],
        ]);

        $porPagina = 100;
        $pagina = (int) ($f['pagina'] ?? 1);

        $q = StockLog::query()
            ->when($f['de'] ?? null, fn ($w, $d) => $w->where('created_at', '>=', "{$d} 00:00:00"))
            ->when($f['ate'] ?? null, fn ($w, $d) => $w->where('created_at', '<=', "{$d} 23:59:59"))
            ->when($f['tipo'] ?? null, fn ($w, $t) => $w->where('tipo', $t))
            ->when($f['user_id'] ?? null, fn ($w, $u) => $w->where('user_id', $u))
            ->when(trim($f['produto'] ?? ''), fn ($w, $p) => $w->whereHas('product', fn ($x) => $x
                ->where('nome', 'like', "%{$p}%")
                ->orWhere('codigo_microvix', 'like', "%{$p}%")
                ->orWhere('codigo_barras', 'like', "%{$p}%")))
            ->when(trim($f['endereco'] ?? ''), fn ($w, $e) => $w->whereHas('location', fn ($x) => $x
                ->where('nome', 'like', "%{$e}%")));

        $total = (clone $q)->count();
        $linhas = $q->with(['location', 'product', 'user'])
            ->orderByDesc('id')
            ->skip(($pagina - 1) * $porPagina)
            ->take($porPagina)
            ->get()
            ->map(fn (StockLog $l) => $this->linhaHistorico($l));

        return response()->json([
            'data'     => $linhas,
            'total'    => $total,
            'pagina'   => $pagina,
            'tem_mais' => $pagina * $porPagina < $total,
            'tipos'    => StockLog::TIPOS,
            'usuarios' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Últimas alterações de um endereço (popup do mapa).
     * GET /api/locations/{location}/historico
     */
    public function historicoEndereco(Location $location): JsonResponse
    {
        $linhas = StockLog::where('location_id', $location->id)
            ->with(['location', 'product', 'user'])
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn (StockLog $l) => $this->linhaHistorico($l));

        return response()->json(['data' => $linhas]);
    }

    private function linhaHistorico(StockLog $l): array
    {
        return [
            'id'          => $l->id,
            'data'        => $l->created_at?->format('d/m/Y H:i'),
            'endereco'    => $l->location?->nome . ($l->location?->trashed() ? ' (excluído)' : ''),
            'produto'     => $l->product?->nome,
            'codigo'      => $l->product?->codigo_microvix,
            'tipo'        => $l->tipo,
            'tipo_label'  => StockLog::TIPOS[$l->tipo] ?? $l->tipo,
            'antes'       => (float) $l->quantidade_anterior,
            'depois'      => (float) $l->quantidade_nova,
            'diferenca'   => (float) $l->diferenca,
            'usuario'     => $l->user?->name,
            'referencia'  => $l->referencia,
            'observacao'  => $l->observacao,
        ];
    }
}
