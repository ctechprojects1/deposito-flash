<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Produto integrado ao ERP Microvix.
 */
class Product extends Model
{
    use HasFactory;

    public const STATUS_ATIVO   = 'ativo';
    public const STATUS_INATIVO = 'inativo';

    protected $fillable = [
        'codigo_microvix',
        'nome',
        'status',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relacionamentos
    |--------------------------------------------------------------------------
    */

    /**
     * Registros de estoque deste produto.
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    /**
     * Endereços onde este produto está armazenado (através da pivô stocks).
     */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'stocks')
                    ->withPivot('quantidade')
                    ->withTimestamps();
    }

    /**
     * Itens de solicitação que referenciam este produto.
     */
    public function withdrawalItems(): HasMany
    {
        return $this->hasMany(WithdrawalItem::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes / helpers
    |--------------------------------------------------------------------------
    */

    public function scopeAtivos($query)
    {
        return $query->where('status', self::STATUS_ATIVO);
    }
}
