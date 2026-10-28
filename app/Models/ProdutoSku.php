<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SKU de anúncio (Shopee/TikTok) que aponta para um produto do sistema.
 */
class ProdutoSku extends Model
{
    protected $table = 'produto_skus';

    protected $fillable = ['sku', 'product_id'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
