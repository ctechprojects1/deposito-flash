<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Endereço de estoque, nomeado por time de futebol e posicionado no mapa.
 */
class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'nome',
        'corredor',
        'esteira',
        'eixo_x',
        'eixo_y',
        'ativo',
    ];

    protected function casts(): array
    {
        return [
            'eixo_x' => 'integer',
            'eixo_y' => 'integer',
            'ativo'  => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relacionamentos
    |--------------------------------------------------------------------------
    */

    /**
     * Registros de estoque neste endereço.
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    /**
     * Produtos armazenados neste endereço (através da pivô stocks).
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'stocks')
                    ->withPivot('quantidade')
                    ->withTimestamps();
    }

    /**
     * Itens de solicitação vinculados a este endereço.
     */
    public function withdrawalItems(): HasMany
    {
        return $this->hasMany(WithdrawalItem::class);
    }
}
