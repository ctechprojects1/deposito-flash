<?php

namespace App\Models;

use App\Models\Concerns\PertenceAoDeposito;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pedido de marketplace (Shopee, TikTok) a separar e embalar, por CD.
 */
class VendaPedido extends Model
{
    use PertenceAoDeposito;

    protected $table = 'vendas_pedidos';

    protected $fillable = [
        'deposito_id', 'canal', 'pedido', 'status', 'rastreio', 'envio', 'cliente',
        'criado_em', 'pago_em', 'withdrawal_request_id', 'separado_em', 'separado_por_id',
        'embalado_em', 'embalado_por_id',
    ];

    protected function casts(): array
    {
        return [
            'criado_em'   => 'datetime',
            'pago_em'     => 'datetime',
            'separado_em' => 'datetime',
            'embalado_em' => 'datetime',
        ];
    }

    public function itens(): HasMany
    {
        return $this->hasMany(VendaPedidoItem::class, 'vendas_pedido_id');
    }

    public function separacao(): BelongsTo
    {
        return $this->belongsTo(WithdrawalRequest::class, 'withdrawal_request_id');
    }

    /** Cancelado/devolvido no canal: não separa nem embala. */
    public function cancelado(): bool
    {
        return (bool) preg_match('/cancel|devolv|reembols/i', (string) $this->status);
    }
}
