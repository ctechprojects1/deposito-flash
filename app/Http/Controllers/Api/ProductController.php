<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\MicrovixService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(private readonly MicrovixService $microvix)
    {
    }

    /**
     * Valida um código de produto para a solicitação de retirada.
     *
     * GET /api/products/validar-microvix?codigo=MVX-001
     *
     * REGRA: só se retira o que existe no estoque local (importado do Microvix
     * via CSV). Por isso a fonte de verdade é a tabela `products`. A consulta
     * à WebAPI do Microvix é apenas COMPLEMENTAR — e não bloqueia o fluxo se
     * estiver indisponível/ainda não integrada.
     */
    public function validarMicrovix(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'codigo' => ['required', 'string', 'max:60'],
        ]);

        $codigo = $dados['codigo'];

        // 1) Fonte de verdade: produto no estoque local.
        $produtoLocal = Product::where('codigo_microvix', $codigo)->first();

        if ($produtoLocal) {
            return response()->json([
                'data' => [
                    'valido'        => true,
                    'product_id'    => $produtoLocal->id,
                    'nome'          => $produtoLocal->nome,
                    'ja_cadastrado' => true,
                    'origem'        => 'local',
                    'mensagem'      => null,
                ],
            ], 200);
        }

        // 2) Não está no estoque local: consulta o Microvix só para informar.
        //    (Se a integração não estiver ativa, retorna inválido sem quebrar.)
        $resultado = $this->microvix->validarProduto($codigo);

        return response()->json([
            'data' => array_merge($resultado, [
                'ja_cadastrado' => false,
                'product_id'    => null,
                'origem'        => 'microvix',
                'mensagem'      => $resultado['valido']
                    ? 'Produto existe no Microvix, mas ainda não está no estoque local.'
                    : ($resultado['mensagem'] ?? 'Produto não encontrado no estoque.'),
            ]),
        ], 404);
    }

    /**
     * Lista os endereços (Times) em que um produto tem estoque disponível.
     *
     * GET /api/products/{product}/locais
     *
     * Usado pelo separador para saber em qual Time ir buscar o item.
     */
    public function locais(Product $product): JsonResponse
    {
        $product->load(['stocks' => fn ($q) => $q->where('quantidade', '>', 0)->with('location')]);

        $locais = $product->stocks
            ->sortByDesc('quantidade')
            ->map(fn ($stock) => [
                'location_id' => $stock->location_id,
                'nome'        => $stock->location?->nome,
                'corredor'    => $stock->location?->corredor,
                'esteira'     => $stock->location?->esteira,
                'quantidade'  => (float) $stock->quantidade,
            ])->values();

        return response()->json(['data' => $locais]);
    }
}
