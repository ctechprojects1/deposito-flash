<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivô de estoque: quantidade de um produto em um endereço.
 */
class Stock extends Model
{
    use HasFactory;

    protected $fillable = [
        'location_id',
        'product_id',
        'quantidade',
    ];

    protected function casts(): array
    {
        return [
            'quantidade' => 'decimal:2',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relacionamentos
    |--------------------------------------------------------------------------
    */

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
