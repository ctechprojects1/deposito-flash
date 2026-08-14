<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item individual de uma solicitação de retirada.
 */
class WithdrawalItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'withdrawal_request_id',
        'product_id',
        'location_id',
        'quantidade_solicitada',
        'quantidade_separada',
    ];

    protected function casts(): array
    {
        return [
            'quantidade_solicitada' => 'decimal:2',
            'quantidade_separada'   => 'decimal:2',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relacionamentos
    |--------------------------------------------------------------------------
    */

    public function withdrawalRequest(): BelongsTo
    {
        return $this->belongsTo(WithdrawalRequest::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
