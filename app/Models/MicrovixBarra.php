<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Código de barras do Microvix -> código interno (cod_produto).
 */
class MicrovixBarra extends Model
{
    protected $table = 'microvix_barras';

    protected $fillable = ['cod_barra', 'cod_produto', 'ts'];
}
