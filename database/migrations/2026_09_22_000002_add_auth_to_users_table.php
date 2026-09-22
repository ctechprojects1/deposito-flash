<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adiciona permissões (JSON) e token de API para autenticação.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('permissions')->nullable()->after('role');
            $table->string('api_token', 64)->nullable()->unique()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['permissions']);
            $table->dropColumn(['api_token']);
        });
    }
};
