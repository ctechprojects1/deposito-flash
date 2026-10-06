<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Item da base de apoio (planilha Shopee etc.). Compartilhada entre os CDs,
 * como o cadastro de produtos e a base do Microvix.
 */
class CatalogoProduto extends Model
{
    protected $table = 'catalogo_produtos';

    protected $fillable = ['fonte', 'chave', 'nome', 'variacao', 'sku', 'sku_pai', 'ean', 'externo_id'];

    /** Nome completo para mostrar/gravar: "Produto - Variação". */
    public function nomeCompleto(): string
    {
        return $this->variacao ? "{$this->nome} - {$this->variacao}" : $this->nome;
    }

    /** SKU mais específico disponível (variação, senão o de referência). */
    public function skuPrincipal(): ?string
    {
        return $this->sku ?: $this->sku_pai;
    }
}
