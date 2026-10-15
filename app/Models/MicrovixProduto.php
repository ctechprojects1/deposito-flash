<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Produto do Microvix (cod_produto -> nome + referência), espelho local.
 */
class MicrovixProduto extends Model
{
    protected $table = 'microvix_produtos';

    protected $fillable = ['cod_produto', 'nome', 'referencia', 'desativado', 'ts'];

    protected function casts(): array
    {
        return ['desativado' => 'boolean'];
    }
}
