<?php

namespace App\Http\Middleware;

use App\Models\Deposito;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Define o CD da requisição a partir do header X-Deposito (o seletor do
 * topo da tela). Sem header, usa o primeiro CD liberado ao usuário.
 * Roda antes da resolução de rotas ({location}, {withdrawalRequest}...),
 * então um id de outro CD simplesmente não é encontrado (404).
 */
class DepositoAtual
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $permitidos = $user ? $user->depositosPermitidos() : [];

        if (! $permitidos) {
            return response()->json(['message' => 'Nenhum CD liberado para o seu usuário. Fale com o administrador.'], 403);
        }

        $pedido = $request->header('X-Deposito');
        if ($pedido !== null && $pedido !== '') {
            if (! in_array((int) $pedido, $permitidos, true)) {
                return response()->json(['message' => 'Você não tem acesso a este CD.'], 403);
            }
            $id = (int) $pedido;
        } else {
            $id = $permitidos[0];
        }

        Deposito::definirAtual($id);

        return $next($request);
    }
}
