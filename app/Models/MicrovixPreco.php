<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Preço do produto no Microvix (cod_produto -> venda + custo), espelho local.
 */
class MicrovixPreco extends Model
{
    protected $table = 'microvix_precos';

    protected $fillable = ['cod_produto', 'preco_venda', 'preco_custo', 'ts'];

    protected function casts(): array
    {
        return ['preco_venda' => 'float', 'preco_custo' => 'float'];
    }
}
