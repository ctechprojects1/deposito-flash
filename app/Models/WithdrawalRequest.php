<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Solicitação de retirada de material do estoque.
 */
class WithdrawalRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDENTE     = 'pendente';
    public const STATUS_EM_SEPARACAO = 'em_separacao';
    public const STATUS_CONCLUIDA    = 'concluida';
    public const STATUS_CANCELADA    = 'cancelada';

    protected $fillable = [
        'solicitante_id',
        'separador_id',
        'destino',
        'anexo_nota_path',
        'status',
        'observacao',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relacionamentos
    |--------------------------------------------------------------------------
    */

    /**
     * Usuário que abriu a solicitação.
     */
    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitante_id');
    }

    /**
     * Usuário responsável pela separação.
     */
    public function separador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'separador_id');
    }

    /**
     * Itens que compõem a solicitação.
     */
    public function items(): HasMany
    {
        return $this->hasMany(WithdrawalItem::class);
    }
}
