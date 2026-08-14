<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Usuários padrão para operar o sistema ENQUANTO não há tela de login.
 *
 * A ORDEM importa: o frontend usa IDs fixos temporários
 *   - SOLICITANTE_ID = 1  (RequestForm.jsx)
 *   - SEPARADOR_ID   = 2  (PickerDashboard.jsx)
 * então o solicitante precisa ser o 1º inserido e o separador o 2º.
 *
 * Quando a Fase 7 (autenticação) entrar, estes viram usuários reais com senha.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        // id 1 — usado como solicitante padrão pelo front.
        User::updateOrCreate(
            ['email' => 'solicitante@sistema.local'],
            [
                'name'     => 'Solicitante Padrão',
                'role'     => User::ROLE_SOLICITANTE,
                'password' => Hash::make('trocar@123'),
                'ativo'    => true,
            ]
        );

        // id 2 — usado como separador padrão pelo front.
        User::updateOrCreate(
            ['email' => 'separador@sistema.local'],
            [
                'name'     => 'Separador Padrão',
                'role'     => User::ROLE_SEPARADOR,
                'password' => Hash::make('trocar@123'),
                'ativo'    => true,
            ]
        );

        // id 3 — administrador (para quando a autenticação existir).
        User::updateOrCreate(
            ['email' => 'admin@sistema.local'],
            [
                'name'     => 'Administrador',
                'role'     => User::ROLE_ADMIN,
                'password' => Hash::make('trocar@123'),
                'ativo'    => true,
            ]
        );
    }
}
