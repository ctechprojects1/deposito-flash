<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            ->whereHas('stocks')
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
