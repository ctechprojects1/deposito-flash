<?php

namespace App\Support;

/**
 * Diz ao histórico de estoque DE ONDE vem a alteração de saldo que está
 * acontecendo agora (separação, movimentação, contagem...).
 *
 *   Historico::com('separacao', "Solicitação #8", fn () => $stock->baixa(...));
 *
 * Sem contexto, a alteração é registrada como "ajuste".
 */
class Historico
{
    private static array $pilha = [];

    public static function com(string $tipo, ?string $referencia, callable $fn, ?string $observacao = null): mixed
    {
        self::$pilha[] = [
            'tipo'       => $tipo,
            'referencia' => $referencia ? mb_substr($referencia, 0, 120) : null,
            'observacao' => $observacao ? mb_substr($observacao, 0, 255) : null,
        ];

        try {
            return $fn();
        } finally {
            array_pop(self::$pilha);
        }
    }

    public static function atual(): array
    {
        return end(self::$pilha) ?: ['tipo' => 'ajuste', 'referencia' => null, 'observacao' => null];
    }
}
