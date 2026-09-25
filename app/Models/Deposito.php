<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Centro de distribuição (CD): Goiânia, São Paulo...
 * Cada CD tem seus próprios endereços, estoque, movimentações, contagens e
 * solicitações. O CD da requisição vem do header X-Deposito (middleware
 * `deposito`) e filtra os models via o trait PertenceAoDeposito.
 */
class Deposito extends Model
{
    protected $fillable = ['nome', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    /** Id do CD desta requisição (null fora de uma requisição da API, ex.: console). */
    public static function atualId(): ?int
    {
        return app()->bound('deposito.atual') ? app('deposito.atual') : null;
    }

    public static function definirAtual(int $id): void
    {
        app()->instance('deposito.atual', $id);
    }
}
