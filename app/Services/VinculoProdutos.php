<?php

namespace App\Services;

use App\Models\CatalogoProduto;
use App\Models\MicrovixBarra;
use App\Models\MicrovixProduto;
use App\Models\Product;

/**
 * Liga o item de uma venda (SKU da Shopee/TikTok) ao produto do sistema.
 * Só aceita um vínculo quando ele é único: SKUs/referências genéricas
 * ("SALDO", "**"...) se repetem e trocariam um produto por outro.
 *
 * Ordem: SKU do produto -> código Microvix igual ao SKU -> EAN da base de
 * apoio (Shopee) -> referência única do Microvix.
 */
class VinculoProdutos
{
    /** @var array<string, ?int> */
    private array $cache = [];

    public function resolver(?string $skuVariacao, ?string $skuPrincipal): ?Product
    {
        $skus = array_values(array_unique(array_filter([trim((string) $skuVariacao), trim((string) $skuPrincipal)])));
        if (! $skus) {
            return null;
        }

        $chave = implode('|', $skus);
        if (! array_key_exists($chave, $this->cache)) {
            $this->cache[$chave] = $this->buscar($skus);
        }

        return $this->cache[$chave] ? Product::find($this->cache[$chave]) : null;
    }

    /** @param  string[]  $skus  do mais específico (variação) ao mais geral */
    private function buscar(array $skus): ?int
    {
        foreach ($skus as $sku) {
            if ($id = $this->unico(Product::where('sku', $sku))) {
                return $id;
            }
            if ($id = $this->unico(Product::where('codigo_microvix', $sku))) {
                return $id;
            }

            // Base de apoio da Shopee: o SKU tem EAN -> produto com esse código de barras
            // (direto ou pelo código interno do Microvix).
            $eans = CatalogoProduto::where('sku', $sku)->whereNotNull('ean')->pluck('ean')->unique();
            if ($eans->count() === 1) {
                $ean = $eans->first();
                if ($id = $this->unico(Product::where('codigo_barras', $ean))) {
                    return $id;
                }
                $cod = MicrovixBarra::where('cod_barra', $ean)->value('cod_produto');
                if ($cod && ($id = $this->unico(Product::where('codigo_microvix', $cod)))) {
                    return $id;
                }
            }

            // Referência do Microvix igual ao SKU, se for de um produto só.
            $cods = MicrovixProduto::where('referencia', $sku)->limit(2)->pluck('cod_produto');
            if ($cods->count() === 1 && ($id = $this->unico(Product::where('codigo_microvix', $cods->first())))) {
                return $id;
            }
        }

        return null;
    }

    private function unico($query): ?int
    {
        $ids = $query->limit(2)->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }
}
