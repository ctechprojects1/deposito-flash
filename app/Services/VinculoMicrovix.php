<?php

namespace App\Services;

use App\Models\CatalogoProduto;
use App\Models\MicrovixBarra;
use App\Models\MicrovixProduto;
use App\Models\Product;
use App\Models\ProdutoSku;
use App\Models\Stock;
use App\Support\Historico;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Liga ao Microvix os produtos que ainda não estão nele (vieram da base de
 * apoio da Shopee, com o SKU no lugar do código interno, ou foram
 * cadastrados à mão), para o Microvix virar a única base de produtos.
 *
 * Acha o produto do Microvix por: código de barras -> EAN da base Shopee
 * para aquele SKU -> referência do Microvix igual ao SKU. Só aceita quando
 * as pistas apontam para UM produto só; o resto a tela liga à mão.
 *
 * Ao ligar, o produto passa a ter código interno, referência e descrição do
 * Microvix, e o SKU antigo da Shopee fica em produto_skus (as vendas seguem
 * achando o produto). Se o produto do Microvix já existe no sistema
 * (duplicado), os dois viram um: estoque, histórico e separações passam para ele.
 */
class VinculoMicrovix
{
    public function __construct(private MicrovixService $microvix)
    {
    }

    /**
     * O que seria feito, sem mudar nada.
     *
     * @return array{total_sem_vinculo:int, vincular:array, juntar:array, ambiguos:array, nao_encontrados:array}
     */
    public function analisar(): array
    {
        $vincular = $juntar = $ambiguos = $naoEncontrados = [];
        $donos = Product::whereIn('codigo_microvix', $this->ativos())->pluck('id', 'codigo_microvix')->all();

        foreach ($this->semVinculo() as $p) {
            $linha = $this->linha($p);
            $cods = $this->candidatos($p);

            if (count($cods) === 0) {
                $naoEncontrados[] = $linha;
                continue;
            }
            if (count($cods) > 1) {
                $ambiguos[] = $linha + ['opcoes' => MicrovixProduto::whereIn('cod_produto', $cods)->get(['cod_produto', 'nome', 'referencia'])->toArray()];
                continue;
            }

            $cod = reset($cods);
            $mvx = MicrovixProduto::where('cod_produto', $cod)->first();
            $linha += [
                'novo_codigo'     => $cod,
                'nova_referencia' => $mvx->referencia,
                'novo_nome'       => mb_substr($mvx->nome, 0, 191),
            ];

            $dono = $donos[$cod] ?? null;
            if ($dono && $dono !== $p->id) {
                $juntar[] = $linha + ['juntar_com' => $dono, 'juntar_com_nome' => Product::whereKey($dono)->value('nome')];
            } else {
                $donos[$cod] = $p->id; // um segundo produto com o mesmo código vai ser juntado a este
                $vincular[] = $linha;
            }
        }

        return [
            'total_sem_vinculo' => count($vincular) + count($juntar) + count($ambiguos) + count($naoEncontrados),
            'vincular'          => $vincular,
            'juntar'            => $juntar,
            'ambiguos'          => $ambiguos,
            'nao_encontrados'   => $naoEncontrados,
        ];
    }

    /** Liga tudo o que a análise achou sozinha (tudo ou nada). */
    public function aplicar(): array
    {
        $plano = $this->analisar();

        DB::transaction(function () use ($plano) {
            foreach (array_merge($plano['vincular'], $plano['juntar']) as $l) {
                $this->ligar(Product::findOrFail($l['product_id']), $l['novo_codigo']);
            }
        });

        return $plano;
    }

    /**
     * Liga um produto a um código do Microvix (automático ou escolhido na tela).
     * Devolve o produto que ficou (o próprio ou o que já tinha esse código).
     */
    public function ligar(Product $p, string $cod): Product
    {
        $mvx = MicrovixProduto::where('cod_produto', $cod)->first();
        if (! $mvx) {
            throw new RuntimeException("O código {$cod} não está na base do Microvix. Sincronize e tente de novo.");
        }
        if ($mvx->desativado) {
            throw new RuntimeException("O produto {$cod} está desativado no Microvix. Escolha o produto ativo.");
        }

        return DB::transaction(fn () => Historico::com('ajuste', 'Vínculo Microvix', function () use ($p, $mvx) {
            $this->guardarSkus($p, $mvx);

            $dono = Product::where('codigo_microvix', $mvx->cod_produto)->where('id', '!=', $p->id)->first();
            if ($dono) {
                $this->juntar($p, $dono);
                $alvo = $dono;
            } else {
                $alvo = $p;
            }

            $alvo->codigo_microvix = $mvx->cod_produto;
            $alvo->sku = $mvx->referencia ?: $alvo->sku;
            $alvo->nome = mb_substr($mvx->nome, 0, 191);
            if (! $alvo->codigo_barras) {
                $barra = MicrovixBarra::where('cod_produto', $mvx->cod_produto)->whereColumn('cod_barra', '!=', 'cod_produto')->value('cod_barra');
                if ($barra && ! Product::where('codigo_barras', $barra)->where('id', '!=', $alvo->id)->exists()) {
                    $alvo->codigo_barras = $barra;
                }
            }
            $alvo->save();

            return $alvo;
        }, 'Produto ligado ao cadastro do Microvix'));
    }

    /** Produtos cujo código não é de um produto ATIVO do Microvix (inclui os ligados a um desativado). */
    private function semVinculo(): Collection
    {
        return Product::query()
            ->where(fn ($q) => $q->whereNull('codigo_microvix')
                ->orWhereNotIn('codigo_microvix', $this->ativos()))
            ->orderBy('nome')
            ->get();
    }

    private function linha(Product $p): array
    {
        return [
            'product_id' => $p->id,
            'nome'       => $p->nome,
            'codigo'     => $p->codigo_microvix,
            'sku'        => $p->sku,
            'barras'     => $p->codigo_barras,
            // Já estava ligado, mas a um produto desativado no Microvix.
            'desativado' => $p->codigo_microvix && MicrovixProduto::where('cod_produto', $p->codigo_microvix)->where('desativado', true)->exists(),
            'saldo'      => (float) Stock::withoutGlobalScopes()->where('product_id', $p->id)->sum('quantidade'),
        ];
    }

    /** Códigos do Microvix apontados pelas pistas do produto (sem repetir). */
    private function candidatos(Product $p): array
    {
        $cods = [];

        // Ligado a um produto desativado: o ativo de mesma referência (recadastro no Microvix).
        if ($p->codigo_microvix && ($cod = $this->microvix->ativoEquivalente($p->codigo_microvix)) && $cod !== $p->codigo_microvix) {
            $cods[$cod] = $cod;
        }

        if ($p->codigo_barras && ($cod = $this->microvix->codProdutoPorBarra($p->codigo_barras)) && ($cod = $this->microvix->ativoEquivalente($cod))) {
            $cods[$cod] = $cod;
        }

        foreach ($this->skusDoProduto($p) as $sku) {
            // EAN que a planilha da Shopee tinha para esse SKU.
            $eans = CatalogoProduto::where(fn ($q) => $q->where('sku', $sku)->orWhere('sku_pai', $sku))
                ->whereNotNull('ean')->pluck('ean')->unique();
            if ($eans->count() === 1 && ($cod = $this->microvix->codProdutoPorBarra($eans->first())) && ($cod = $this->microvix->ativoEquivalente($cod))) {
                $cods[$cod] = $cod;
            }

            // Referência do Microvix igual ao SKU, se for de um produto só.
            $porRef = MicrovixProduto::where('referencia', $sku)->where('desativado', false)->limit(2)->pluck('cod_produto');
            if ($porRef->count() === 1) {
                $cods[$porRef->first()] = $porRef->first();
            }
        }

        // Só vale produto ATIVO do cadastro do Microvix (base sincronizada).
        return $cods ? MicrovixProduto::whereIn('cod_produto', array_values($cods))->where('desativado', false)->pluck('cod_produto', 'cod_produto')->all() : [];
    }

    /** Subconsulta dos códigos de produtos ativos no Microvix. */
    private function ativos()
    {
        return MicrovixProduto::where('desativado', false)->select('cod_produto');
    }

    /** SKU e "código" antigos (na base Shopee o código era o próprio SKU). */
    private function skusDoProduto(Product $p): array
    {
        return array_values(array_unique(array_filter([trim((string) $p->sku), trim((string) $p->codigo_microvix)])));
    }

    /**
     * Guarda os SKUs antigos do produto (as vendas seguem achando). Não guarda
     * o que já é o código ou a referência do Microvix (acha sozinho) nem um
     * código de outro produto do Microvix. Se o produto for juntado a outro,
     * os SKUs vão junto.
     */
    private function guardarSkus(Product $p, MicrovixProduto $mvx): void
    {
        foreach ($this->skusDoProduto($p) as $sku) {
            if ($sku === $mvx->cod_produto || $sku === $mvx->referencia || MicrovixProduto::where('cod_produto', $sku)->exists()) {
                continue;
            }
            ProdutoSku::updateOrCreate(['sku' => mb_substr($sku, 0, 100)], ['product_id' => $p->id]);
        }
    }

    /** Passa tudo de $de para $para e apaga $de. */
    private function juntar(Product $de, Product $para): void
    {
        foreach (['stock_logs', 'movements', 'withdrawal_items', 'vendas_pedido_itens', 'produto_skus'] as $tabela) {
            DB::table($tabela)->where('product_id', $de->id)->update(['product_id' => $para->id]);
        }

        // Contagens: se as duas linhas estão na mesma contagem e endereço, fica a do produto certo.
        foreach (DB::table('inventory_count_items')->where('product_id', $de->id)->get() as $item) {
            $dup = DB::table('inventory_count_items')->where('inventory_count_id', $item->inventory_count_id)
                ->where('location_id', $item->location_id)->where('product_id', $para->id)->exists();
            $dup
                ? DB::table('inventory_count_items')->where('id', $item->id)->delete()
                : DB::table('inventory_count_items')->where('id', $item->id)->update(['product_id' => $para->id]);
        }

        // Estoque: no mesmo endereço soma (fica no histórico); nos outros só muda o produto.
        foreach (Stock::withoutGlobalScopes()->where('product_id', $de->id)->get() as $s) {
            $alvo = Stock::withoutGlobalScopes()->where('location_id', $s->location_id)->where('product_id', $para->id)->first();
            if ($alvo) {
                $alvo->quantidade = (float) $alvo->quantidade + (float) $s->quantidade;
                $alvo->save();
                DB::table('stocks')->where('id', $s->id)->delete();
            } else {
                DB::table('stocks')->where('id', $s->id)->update(['product_id' => $para->id]);
            }
        }

        $barras = $de->codigo_barras;
        DB::table('products')->where('id', $de->id)->delete();
        if ($barras && ! $para->codigo_barras) {
            $para->codigo_barras = $barras;
        }
    }
}
