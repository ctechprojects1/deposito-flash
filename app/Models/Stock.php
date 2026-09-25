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

    /**
     * Estoque só do CD atual (via endereço). Vale para toda consulta, inclusive
     * $produto->stocks, zerar geral e baixas: um CD nunca vê/mexe no saldo do outro.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('deposito', function (\Illuminate\Database\Eloquent\Builder $q) {
            if ($id = Deposito::atualId()) {
                $q->whereIn('stocks.location_id', Location::withoutGlobalScopes()->select('id')->where('deposito_id', $id));
            }
        });
    }

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
