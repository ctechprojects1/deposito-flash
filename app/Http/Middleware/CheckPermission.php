<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifica se o usuário autenticado tem a permissão exigida pela rota.
 * Uso: ->middleware('perm:movimentar')
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permissao): Response
    {
        $user = $request->user();

        if (! $user || ! $user->can2($permissao)) {
            return response()->json([
                'message' => 'Você não tem permissão para esta ação.',
            ], 403);
        }

        return $next($request);
    }
}
