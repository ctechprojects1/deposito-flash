<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MicrovixBarra;
use App\Models\MicrovixProduto;
use App\Services\BuscaProdutos;
use App\Services\MicrovixService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Busca de produtos para cadastrar em um endereço: só o cadastro do Microvix
 * (base local sincronizada). A antiga base de apoio (planilhas da Shopee)
 * saiu: os produtos que vieram dela foram ligados ao Microvix.
 */
class CatalogoController extends Controller
{
    /**
     * Busca pelo nome, referência ou código (tolerante a erro) na base local
     * do Microvix.
     * GET /api/catalogo/buscar?q=...
     */
    public function buscar(Request $request, BuscaProdutos $busca, MicrovixService $microvix): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return response()->json(['data' => [], 'sugestoes' => []]);
        }

        $mvx = $microvix->buscarTexto($q, $busca);

        $barras = MicrovixBarra::query()
            ->whereIn('cod_produto', $mvx['exatos']->merge($mvx['parecidos'])->map(fn ($x) => $x['item']->cod_produto)->all())
            ->whereColumn('cod_barra', '!=', 'cod_produto')
            ->pluck('cod_barra', 'cod_produto');
        $fmt = fn ($x) => self::formatarMicrovix($x['item'], $barras[$x['item']->cod_produto] ?? '');

        return response()->json([
            'data'      => $mvx['exatos']->map($fmt)->take(25)->values(),
            'sugestoes' => $mvx['parecidos']->map($fmt)->take(15)->values(),
        ]);
    }

    /** Produto do Microvix no mesmo formato da consulta por código. */
    public static function formatarMicrovix(MicrovixProduto $m, string $codBarra = ''): array
    {
        return [
            'nome'        => $m->nome,
            'cod_produto' => $m->cod_produto,
            'cod_barra'   => $codBarra,
            'sku'         => $m->referencia ?? '',
            'desativado'  => $m->desativado,
            'fonte'       => null,
        ];
    }
}
