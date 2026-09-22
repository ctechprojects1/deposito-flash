<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticação por token de API (Bearer). Resolve o usuário a partir do
 * token enviado no header Authorization.
 */
class TokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }

        $user = User::where('api_token', hash('sha256', $token))->first();

        if (! $user || ! $user->ativo) {
            return response()->json(['message' => 'Sessão inválida ou expirada.'], 401);
        }

        // Disponibiliza o usuário via $request->user() e auth()->user().
        $request->setUserResolver(fn () => $user);
        Auth::setUser($user);

        return $next($request);
    }
}
