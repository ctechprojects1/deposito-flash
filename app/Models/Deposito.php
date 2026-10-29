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
    protected $fillable = ['nome', 'local', 'ordem_posicoes', 'ativo'];

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

    /**
     * Outros CDs ativos no mesmo lugar físico (mesmo `local`) do CD atual:
     * a separação pode tirar item de um endereço deles. [id => nome]
     */
    public static function vizinhos(): array
    {
        $atual = self::atualId();
        $local = $atual ? self::whereKey($atual)->value('local') : null;
        if (! $local) {
            return [];
        }

        return self::where('local', $local)->where('id', '!=', $atual)->where('ativo', true)
            ->orderBy('id')->pluck('nome', 'id')->all();
    }

    /** CD atual + vizinhos (de onde a separação pode tirar). */
    public static function acessiveisNaSeparacao(): array
    {
        return array_merge(array_filter([self::atualId()]), array_keys(self::vizinhos()));
    }
}
