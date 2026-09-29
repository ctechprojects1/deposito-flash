<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma linha do histórico de estoque (gravada automaticamente pelo model Stock).
 */
class StockLog extends Model
{
    use \App\Models\Concerns\PertenceAoDeposito;

    public const UPDATED_AT = null;

    /** Rótulos dos tipos (tela de histórico). */
    public const TIPOS = [
        'separacao'       => 'Separação (baixa)',
        'estorno'         => 'Separação reaberta (estorno)',
        'movimentacao'    => 'Movimentação',
        'contagem'        => 'Contagem / inventário',
        'adicao'          => 'Produto adicionado',
        'ajuste'          => 'Ajuste de saldo',
        'remocao'         => 'Produto removido',
        'replicacao'      => 'Replicação',
        'zerar_endereco'  => 'Endereço zerado',
        'zerar_geral'     => 'Estoque geral zerado',
        'importacao'      => 'Importação CSV',
        'entrada_manual'  => 'Entrada manual',
        'baixa_manual'    => 'Baixa manual',
    ];

    protected $fillable = [
        'deposito_id', 'location_id', 'product_id', 'user_id', 'tipo', 'referencia',
        'observacao', 'quantidade_anterior', 'quantidade_nova', 'diferenca',
    ];

    protected function casts(): array
    {
        return [
            'quantidade_anterior' => 'decimal:2',
            'quantidade_nova'     => 'decimal:2',
            'diferenca'           => 'decimal:2',
            'created_at'          => 'datetime',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class)->withTrashed();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
