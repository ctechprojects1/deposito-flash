<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MicrovixBarra;
use App\Services\MicrovixService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MicrovixController extends Controller
{
    public function __construct(private readonly MicrovixService $microvix)
    {
    }

    /**
     * Consulta um código (barras ou interno) no Microvix.
     * GET /api/microvix/consultar?codigo=...
     */
    public function consultar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'codigo' => ['required', 'string', 'max:60'],
        ]);

        return response()->json($this->microvix->consultar($dados['codigo']));
    }

    /**
     * Sincroniza um lote da base de códigos de barras (a tela chama em loop
     * até concluido=true).
     * POST /api/microvix/sincronizar
     */
    public function sincronizar(): JsonResponse
    {
        if (! $this->microvix->configurado()) {
            return response()->json(['message' => 'Integração Microvix não configurada.'], 422);
        }

        return response()->json($this->microvix->sincronizarBarras(3));
    }

    /**
     * Situação da base local de códigos de barras.
     * GET /api/microvix/status
     */
    public function status(): JsonResponse
    {
        return response()->json([
            'total'         => MicrovixBarra::count(),
            'ultima_sync'   => optional(MicrovixBarra::max('updated_at'), fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('d/m/Y H:i')),
            'configurado'   => $this->microvix->configurado(),
        ]);
    }
}
