<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item de um pedido de marketplace, já ligado (quando possível) ao produto do sistema.
 */
class VendaPedidoItem extends Model
{
    protected $table = 'vendas_pedido_itens';

    protected $fillable = ['vendas_pedido_id', 'sku_principal', 'sku', 'nome', 'variacao', 'quantidade', 'product_id'];

    protected function casts(): array
    {
        return ['quantidade' => 'float'];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(VendaPedido::class, 'vendas_pedido_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** "Produto — Variação" como aparece na Shopee. */
    public function nomeCompleto(): string
    {
        return $this->variacao ? "{$this->nome} — {$this->variacao}" : $this->nome;
    }
}
