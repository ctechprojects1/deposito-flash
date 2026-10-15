<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MicrovixBarra;
use App\Models\MicrovixProduto;
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

        $r = $this->microvix->consultar($dados['codigo']);

        // Não está no Microvix: procura na base de apoio (planilha Shopee etc.).
        if (! $r['encontrado'] && ($c = CatalogoController::porCodigo($dados['codigo']))) {
            return response()->json([
                'encontrado'    => true,
                'produto'       => CatalogoController::formatar($c),
                'sync_pendente' => false,
                'mensagem'      => null,
            ]);
        }

        return response()->json($r);
    }

    /**
     * Sincroniza um lote da base local: primeiro os códigos de barras, depois
     * os produtos (nome + referência). A tela chama em loop até concluido=true.
     * POST /api/microvix/sincronizar
     */
    public function sincronizar(): JsonResponse
    {
        if (! $this->microvix->configurado()) {
            return response()->json(['message' => 'Integração Microvix não configurada.'], 422);
        }

        $barras = $this->microvix->sincronizarBarras(3);
        if (! $barras['concluido']) {
            return response()->json($barras + ['produtos' => MicrovixProduto::count()]);
        }

        $produtos = $this->microvix->sincronizarProdutos(3);

        return response()->json([
            'paginas'   => $barras['paginas'] + $produtos['paginas'],
            'upserts'   => $barras['upserts'] + $produtos['upserts'],
            'concluido' => $produtos['concluido'],
            'total'     => $barras['total'],
            'produtos'  => $produtos['total'],
        ]);
    }

    /**
     * Situação da base local de códigos de barras.
     * GET /api/microvix/status
     */
    public function status(): JsonResponse
    {
        return response()->json([
            'total'         => MicrovixBarra::count(),
            'produtos'      => MicrovixProduto::count(),
            'ultima_sync'   => optional(MicrovixBarra::max('updated_at'), fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('d/m/Y H:i')),
            'configurado'   => $this->microvix->configurado(),
        ]);
    }
}
