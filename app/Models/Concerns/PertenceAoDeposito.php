<?php

namespace App\Models\Concerns;

use App\Models\Deposito;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model separado por CD: toda consulta enxerga só o CD atual e todo registro
 * novo nasce no CD atual. Assim um CD nunca mexe nos dados do outro.
 */
trait PertenceAoDeposito
{
    public static function bootPertenceAoDeposito(): void
    {
        static::addGlobalScope('deposito', function (Builder $q) {
            if ($id = Deposito::atualId()) {
                $q->where($q->getModel()->getTable().'.deposito_id', $id);
            }
        });

        static::creating(function ($model) {
            if (! $model->deposito_id && ($id = Deposito::atualId())) {
                $model->deposito_id = $id;
            }
        });
    }

    public function deposito(): BelongsTo
    {
        return $this->belongsTo(Deposito::class);
    }
}
