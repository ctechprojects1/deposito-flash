<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item de uma contagem de inventário.
 */
class InventoryCountItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_count_id',
        'location_id',
        'product_id',
        'qtd_sistema',
        'qtd_contada',
        'contada',
    ];

    protected function casts(): array
    {
        return [
            'qtd_sistema' => 'decimal:2',
            'qtd_contada' => 'decimal:2',
            'contada'     => 'boolean',
        ];
    }

    /**
     * Diferença = contado - sistema (positivo = sobra; negativo = falta).
     */
    public function getDiferencaAttribute(): float
    {
        return (float) $this->qtd_contada - (float) $this->qtd_sistema;
    }

    public function inventoryCount(): BelongsTo
    {
        return $this->belongsTo(InventoryCount::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
