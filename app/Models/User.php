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

    /** Todas as permissões concedíveis (funcionalidades do sistema). */
    public const PERMISSOES = [
        'ver_mapa',
        'movimentar',
        'solicitar',
        'separar',
        'contar',
        'importar',
        'gerenciar_enderecos',
        'admin',
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'permissions',
        'ativo',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'api_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'ativo'             => 'boolean',
            'permissions'       => 'array',
        ];
    }

    /**
     * O usuário tem a permissão? Admin (role admin ou permissão 'admin')
     * tem acesso a tudo.
     */
    public function can2(string $permissao): bool
    {
        if ($this->isAdmin()) {
            return true;
        }
        return in_array($permissao, $this->permissions ?? [], true);
    }

    /** Lista efetiva de permissões (admin recebe todas). */
    public function permissoesEfetivas(): array
    {
        return $this->isAdmin() ? self::PERMISSOES : ($this->permissions ?? []);
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
        return $this->role === self::ROLE_ADMIN
            || in_array('admin', $this->permissions ?? [], true);
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
