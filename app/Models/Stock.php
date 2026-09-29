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

        // Histórico: toda mudança de saldo feita por model (save/create/delete)
        // vira uma linha em stock_logs. Atualizações em massa (->update() na
        // query) NÃO passam por aqui — por isso o código evita usá-las.
        static::created(fn (Stock $s) => $s->registrarHistorico(0, (float) $s->quantidade));
        static::updated(function (Stock $s) {
            if ($s->wasChanged('quantidade')) {
                $s->registrarHistorico((float) $s->getOriginal('quantidade'), (float) $s->quantidade);
            }
        });
        static::deleted(fn (Stock $s) => $s->registrarHistorico((float) $s->quantidade, 0));
    }

    private function registrarHistorico(float $antes, float $depois): void
    {
        if (round($antes, 2) === round($depois, 2)) {
            return;
        }

        $ctx = \App\Support\Historico::atual();
        StockLog::create([
            'deposito_id'         => Location::withTrashed()->withoutGlobalScopes()->whereKey($this->location_id)->value('deposito_id'),
            'location_id'         => $this->location_id,
            'product_id'          => $this->product_id,
            'user_id'             => auth()->id(),
            'tipo'                => $ctx['tipo'],
            'referencia'          => $ctx['referencia'],
            'observacao'          => $ctx['observacao'],
            'quantidade_anterior' => $antes,
            'quantidade_nova'     => $depois,
            'diferenca'           => round($depois - $antes, 2),
        ]);
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
