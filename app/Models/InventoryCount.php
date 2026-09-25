<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Contagem de inventário (balanço).
 */
class InventoryCount extends Model
{
    use \App\Models\Concerns\PertenceAoDeposito;

    use HasFactory;

    public const STATUS_ABERTA     = 'aberta';
    public const STATUS_FINALIZADA = 'finalizada';
    public const STATUS_CANCELADA  = 'cancelada';

    protected $fillable = [
        'descricao',
        'escopo_time',
        'status',
        'user_id',
        'total_itens',
        'itens_contados',
        'finalizada_em',
    ];

    protected function casts(): array
    {
        return [
            'finalizada_em' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryCountItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
