<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CatalogoProduto;
use App\Services\BuscaProdutos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Base de produtos de apoio (planilhas Shopee etc.).
 * A planilha é lida no navegador e chega aqui em lotes já mapeados.
 */
class CatalogoController extends Controller
{
    /**
     * Recebe um lote de linhas e grava/atualiza (reimportar a mesma planilha
     * atualiza os itens, não duplica).
     * POST /api/catalogo/importar  { fonte, itens: [{chave, nome, variacao, sku, sku_pai, ean, externo_id}] }
     */
    public function importar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'fonte'              => ['required', 'string', 'max:30'],
            'itens'              => ['required', 'array', 'min:1', 'max:1000'],
            'itens.*.chave'      => ['required', 'string', 'max:191'],
            'itens.*.nome'       => ['required', 'string', 'max:255'],
            'itens.*.variacao'   => ['nullable', 'string', 'max:191'],
            'itens.*.sku'        => ['nullable', 'string', 'max:100'],
            'itens.*.sku_pai'    => ['nullable', 'string', 'max:100'],
            'itens.*.ean'        => ['nullable', 'string', 'max:60'],
            'itens.*.externo_id' => ['nullable', 'string', 'max:60'],
        ]);

        $fonte = mb_strtolower(trim($dados['fonte']));
        $agora = now();
        $linhas = array_map(fn ($i) => [
            'fonte'      => $fonte,
            'chave'      => $i['chave'],
            'nome'       => trim($i['nome']),
            'variacao'   => $this->limpo($i['variacao'] ?? null),
            'sku'        => $this->limpo($i['sku'] ?? null),
            'sku_pai'    => $this->limpo($i['sku_pai'] ?? null),
            'ean'        => $this->eanValido($i['ean'] ?? null),
            'externo_id' => $this->limpo($i['externo_id'] ?? null),
            'created_at' => $agora,
            'updated_at' => $agora,
        ], $dados['itens']);

        DB::table('catalogo_produtos')->upsert(
            $linhas,
            ['fonte', 'chave'],
            ['nome', 'variacao', 'sku', 'sku_pai', 'ean', 'externo_id', 'updated_at']
        );

        return response()->json(['gravados' => count($linhas)]);
    }

    /** GET /api/catalogo/status — quantos itens por fonte e quando atualizou. */
    public function status(): JsonResponse
    {
        $fontes = CatalogoProduto::query()
            ->selectRaw('fonte, COUNT(*) as total, MAX(updated_at) as atualizado')
            ->groupBy('fonte')
            ->get()
            ->map(fn ($f) => [
                'fonte'      => $f->fonte,
                'total'      => (int) $f->total,
                'atualizado' => $f->atualizado ? \Illuminate\Support\Carbon::parse($f->atualizado)->format('d/m/Y H:i') : null,
            ]);

        return response()->json(['data' => $fontes]);
    }

    /**
     * Busca na base de apoio por SKU, EAN ou nome (tolerante a erro).
     * GET /api/catalogo/buscar?q=...
     */
    public function buscar(Request $request, BuscaProdutos $busca): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return response()->json(['data' => [], 'sugestoes' => []]);
        }

        $r = $busca->ranquear(
            CatalogoProduto::query()->get(['id', 'fonte', 'nome', 'variacao', 'sku', 'sku_pai', 'ean']),
            fn (CatalogoProduto $c) => $c->nomeCompleto(),
            fn (CatalogoProduto $c) => [$c->sku, $c->sku_pai, $c->ean],
            $q,
            15,
            10
        );

        $fmt = fn ($x) => self::formatar($x['item']);

        return response()->json([
            'data'      => $r['exatos']->map($fmt)->values(),
            'sugestoes' => $r['parecidos']->map($fmt)->values(),
        ]);
    }

    /** Procura exata por código (SKU, SKU de referência ou EAN). */
    public static function porCodigo(string $codigo): ?CatalogoProduto
    {
        $c = trim($codigo);
        if ($c === '') {
            return null;
        }

        $exato = CatalogoProduto::query()
            ->where(fn ($w) => $w->where('ean', $c)->orWhere('sku', $c))
            ->orderByRaw('CASE WHEN ean = ? THEN 0 ELSE 1 END', [$c])
            ->first();
        if ($exato) {
            return $exato;
        }

        // SKU de referência (produto pai): só resolve sozinho se tiver uma
        // única variação; com várias, a tela mostra a lista para escolher.
        $porPai = CatalogoProduto::where('sku_pai', $c)->limit(2)->get();

        return $porPai->count() === 1 ? $porPai->first() : null;
    }

    /** Formato igual ao da consulta Microvix, para a tela de adicionar produto. */
    public static function formatar(CatalogoProduto $c): array
    {
        return [
            'nome'        => $c->nomeCompleto(),
            'cod_produto' => '',
            'cod_barra'   => $c->ean ?? '',
            'sku'         => $c->skuPrincipal() ?? '',
            'fonte'       => $c->fonte,
        ];
    }

    private function limpo(?string $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }

    /** Só aceita EAN/GTIN de verdade (8 a 14 dígitos); a Shopee manda "00" etc. */
    private function eanValido(?string $v): ?string
    {
        $v = preg_replace('/\D/', '', (string) $v);

        return strlen($v) >= 8 && strlen($v) <= 14 && (int) ltrim($v, '0') > 0 ? $v : null;
    }
}
