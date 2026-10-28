<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MicrovixBarra;
use App\Models\MicrovixProduto;
use App\Models\Product;
use App\Services\MicrovixService;
use App\Services\VinculoMicrovix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

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
        if (! $produtos['concluido']) {
            return response()->json([
                'paginas'   => $barras['paginas'] + $produtos['paginas'],
                'upserts'   => $barras['upserts'] + $produtos['upserts'],
                'concluido' => false,
                'total'     => $barras['total'],
                'produtos'  => $produtos['total'],
            ]);
        }

        $precos = $this->microvix->sincronizarPrecos(3);

        return response()->json([
            'paginas'   => $barras['paginas'] + $produtos['paginas'] + $precos['paginas'],
            'upserts'   => $barras['upserts'] + $produtos['upserts'] + $precos['upserts'],
            'concluido' => $precos['concluido'],
            'total'     => $barras['total'],
            'produtos'  => $produtos['total'],
            'precos'    => $precos['total'],
        ]);
    }

    /**
     * Liga ao Microvix os produtos que ainda não estão nele (base Shopee ou
     * cadastro manual). Sem `aplicar`, só mostra o que seria feito.
     * POST /api/microvix/vincular  { aplicar? }   (só admin)
     */
    public function vincular(Request $request, VinculoMicrovix $vinculo): JsonResponse
    {
        @set_time_limit(300);
        $aplicar = $request->boolean('aplicar');
        $r = $aplicar ? $vinculo->aplicar() : $vinculo->analisar();

        return response()->json($r + [
            'aplicado' => $aplicar,
            'message'  => $aplicar
                ? count($r['vincular']) . ' produto(s) ligado(s) ao Microvix e ' . count($r['juntar']) . ' duplicado(s) juntado(s).'
                : null,
        ]);
    }

    /**
     * Liga UM produto ao código do Microvix escolhido na tela (para os que a
     * busca automática não achou ou achou mais de um).
     * POST /api/microvix/vincular-produto  { product_id, cod_produto }   (só admin)
     */
    public function vincularProduto(Request $request, VinculoMicrovix $vinculo): JsonResponse
    {
        $dados = $request->validate([
            'product_id'  => ['required', 'integer', 'exists:products,id'],
            'cod_produto' => ['required', 'string', 'max:30'],
        ]);

        try {
            $p = $vinculo->ligar(Product::findOrFail($dados['product_id']), $dados['cod_produto']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "Ligado ao Microvix: {$p->nome} (cód. {$p->codigo_microvix}).",
            'product' => $p->only(['id', 'nome', 'codigo_microvix', 'sku', 'codigo_barras']),
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
