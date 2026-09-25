<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deposito;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Login: valida credenciais e devolve um token de API.
     * POST /api/login { email, password }
     */
    public function login(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $dados['email'])->first();

        if (! $user || ! Hash::check($dados['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'E-mail ou senha inválidos.',
            ]);
        }

        if (! $user->ativo) {
            throw ValidationException::withMessages([
                'email' => 'Usuário inativo. Fale com o administrador.',
            ]);
        }

        // Gera token novo (guardamos só o hash; o cliente guarda o texto).
        $plain = Str::random(64);
        $user->api_token = hash('sha256', $plain);
        $user->save();

        return response()->json([
            'token' => $plain,
            'user'  => $this->serializar($user),
        ]);
    }

    /** Dados do usuário autenticado. GET /api/me */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->serializar($request->user())]);
    }

    /** Logout: invalida o token atual. POST /api/logout */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->update(['api_token' => null]);
        return response()->json(['message' => 'Sessão encerrada.']);
    }

    public static function serializar(User $u): array
    {
        return [
            'id'          => $u->id,
            'name'        => $u->name,
            'email'       => $u->email,
            'role'        => $u->role,
            'is_admin'    => $u->isAdmin(),
            'permissions' => $u->permissoesEfetivas(),
            'depositos'   => Deposito::whereIn('id', $u->depositosPermitidos())->orderBy('id')->get(['id', 'nome']),
        ];
    }
}
