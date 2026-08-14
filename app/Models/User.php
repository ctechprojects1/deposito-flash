<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    /**
     * Perfis disponíveis no sistema.
     */
    public const ROLE_ADMIN       = 'admin';
    public const ROLE_SOLICITANTE = 'solicitante';
    public const ROLE_SEPARADOR   = 'separador';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'ativo',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'ativo'             => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relacionamentos
    |--------------------------------------------------------------------------
    */

    /**
     * Solicitações abertas por este usuário (perfil solicitante).
     */
    public function solicitacoes(): HasMany
    {
        return $this->hasMany(WithdrawalRequest::class, 'solicitante_id');
    }

    /**
     * Solicitações separadas por este usuário (perfil separador).
     */
    public function separacoes(): HasMany
    {
        return $this->hasMany(WithdrawalRequest::class, 'separador_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers de perfil
    |--------------------------------------------------------------------------
    */

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isSolicitante(): bool
    {
        return $this->role === self::ROLE_SOLICITANTE;
    }

    public function isSeparador(): bool
    {
        return $this->role === self::ROLE_SEPARADOR;
    }
}
