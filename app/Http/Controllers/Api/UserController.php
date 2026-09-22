<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /** Lista de permissões disponíveis (para a tela montar os chips). */
    public function permissoes(): JsonResponse
    {
        return response()->json(['data' => User::PERMISSOES]);
    }

    /** GET /api/users */
    public function index(): JsonResponse
    {
        $users = User::orderBy('name')->get()->map(fn ($u) => $this->serializar($u));
        return response()->json(['data' => $users]);
    }

    /** POST /api/users */
    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'name'          => ['required', 'string', 'max:120'],
            'email'         => ['required', 'email', 'unique:users,email'],
            'password'      => ['required', 'string', 'min:6'],
            'permissions'   => ['array'],
            'permissions.*' => ['string', Rule::in(User::PERMISSOES)],
            'ativo'         => ['boolean'],
        ]);

        $user = User::create([
            'name'        => $dados['name'],
            'email'       => $dados['email'],
            'password'    => $dados['password'],
            'role'        => in_array('admin', $dados['permissions'] ?? [], true)
                                ? User::ROLE_ADMIN : User::ROLE_SOLICITANTE,
            'permissions' => $dados['permissions'] ?? [],
            'ativo'       => $dados['ativo'] ?? true,
        ]);

        return response()->json(['message' => 'Usuário criado.', 'data' => $this->serializar($user)], 201);
    }

    /** PUT /api/users/{user} */
    public function update(Request $request, User $user): JsonResponse
    {
        $dados = $request->validate([
            'name'          => ['required', 'string', 'max:120'],
            'email'         => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'password'      => ['nullable', 'string', 'min:6'],
            'permissions'   => ['array'],
            'permissions.*' => ['string', Rule::in(User::PERMISSOES)],
            'ativo'         => ['boolean'],
        ]);

        $user->name        = $dados['name'];
        $user->email       = $dados['email'];
        $user->permissions = $dados['permissions'] ?? [];
        $user->role        = in_array('admin', $dados['permissions'] ?? [], true)
                                ? User::ROLE_ADMIN : User::ROLE_SOLICITANTE;
        if (array_key_exists('ativo', $dados)) {
            $user->ativo = $dados['ativo'];
        }
        if (! empty($dados['password'])) {
            $user->password = $dados['password'];
        }
        $user->save();

        return response()->json(['message' => 'Usuário atualizado.', 'data' => $this->serializar($user)]);
    }

    /** DELETE /api/users/{user} */
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'Você não pode excluir a si mesmo.'], 422);
        }

        $user->delete();
        return response()->json(['message' => 'Usuário excluído.']);
    }

    private function serializar(User $u): array
    {
        return [
            'id'          => $u->id,
            'name'        => $u->name,
            'email'       => $u->email,
            'role'        => $u->role,
            'is_admin'    => $u->isAdmin(),
            'ativo'       => (bool) $u->ativo,
            'permissions' => $u->permissions ?? [],
        ];
    }
}
