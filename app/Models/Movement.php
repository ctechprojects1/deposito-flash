<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Movimentação de estoque entre endereços.
 */
class Movement extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'origin_location_id',
        'destination_location_id',
        'quantidade',
        'motivo',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'quantidade' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function origem(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'origin_location_id');
    }

    public function destino(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
