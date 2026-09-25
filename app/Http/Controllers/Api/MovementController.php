<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deposito;
use App\Models\Movement;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

class MovementController extends Controller
{
    /**
     * Registra uma movimentação: dá baixa na origem e entrada no destino.
     *
     * POST /api/movements
     *   { product_id, origin_location_id, destination_location_id, quantidade, motivo }
     */
    public function store(Request $request, StockService $stockService): JsonResponse
    {
        $dados = $request->validate([
            'product_id'              => ['required', 'integer', 'exists:products,id'],
            'origin_location_id'      => ['required', 'integer', Rule::exists('locations', 'id')->where('deposito_id', Deposito::atualId())],
            'destination_location_id' => ['required', 'integer', 'different:origin_location_id', Rule::exists('locations', 'id')->where('deposito_id', Deposito::atualId())],
            'quantidade'              => ['required', 'numeric', 'gt:0'],
            'motivo'                  => ['required', 'string', 'max:255'],
        ], [
            'destination_location_id.different' => 'A origem e o destino não podem ser o mesmo endereço.',
            'motivo.required'                   => 'Informe o motivo da movimentação.',
        ]);

        $userId = $request->user()?->id;

        try {
            $movimento = DB::transaction(function () use ($dados, $stockService, $userId) {
                // Baixa na origem (valida saldo) e entrada no destino.
                $stockService->baixa($dados['origin_location_id'], $dados['product_id'], (float) $dados['quantidade']);
                $stockService->entrada($dados['destination_location_id'], $dados['product_id'], (float) $dados['quantidade']);

                return Movement::create([
                    'product_id'              => $dados['product_id'],
                    'origin_location_id'      => $dados['origin_location_id'],
                    'destination_location_id' => $dados['destination_location_id'],
                    'quantidade'              => $dados['quantidade'],
                    'motivo'                  => $dados['motivo'],
                    'user_id'                 => $userId,
                ]);
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Movimentação registrada.',
            'data'    => $this->transformar($movimento->load(['product', 'origem', 'destino', 'user'])),
        ], 201);
    }

    /**
     * Relatório de movimentações (mais recentes primeiro), com filtros opcionais.
     *
     * GET /api/movements?time=CEARA&produto=MONITOR&de=2026-09-01&ate=2026-09-30
     */
    public function index(Request $request): JsonResponse
    {
        $movs = Movement::query()
            ->with(['product', 'origem', 'destino', 'user'])
            ->when($request->query('produto'), function ($q, $termo) {
                $q->whereHas('product', fn ($p) =>
                    $p->where('nome', 'like', "%{$termo}%")->orWhere('codigo_microvix', 'like', "%{$termo}%"));
            })
            ->when($request->query('time'), function ($q, $time) {
                $q->where(function ($sub) use ($time) {
                    $sub->whereHas('origem', fn ($l) => $l->where('corredor', 'like', "%{$time}%"))
                        ->orWhereHas('destino', fn ($l) => $l->where('corredor', 'like', "%{$time}%"));
                });
            })
            ->when($request->query('de'), fn ($q, $de) => $q->whereDate('created_at', '>=', $de))
            ->when($request->query('ate'), fn ($q, $ate) => $q->whereDate('created_at', '<=', $ate))
            ->latest()
            ->limit(500)
            ->get()
            ->map(fn ($m) => $this->transformar($m));

        return response()->json(['data' => $movs]);
    }

    private function transformar(Movement $m): array
    {
        return [
            'id'         => $m->id,
            'data'       => $m->created_at?->format('d/m/Y H:i'),
            'produto'    => $m->product?->nome,
            'codigo'     => $m->product?->codigo_microvix,
            'origem'     => $m->origem?->nome,
            'destino'    => $m->destino?->nome,
            'quantidade' => (float) $m->quantidade,
            'motivo'     => $m->motivo,
            'usuario'    => $m->user?->name ?? '—',
        ];
    }
}
