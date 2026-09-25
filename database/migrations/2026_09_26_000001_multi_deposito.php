<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vários CDs (Goiânia, São Paulo...) no mesmo sistema, com dados separados.
     * Tudo o que já existe fica no CD 1 (Goiânia).
     */
    public function up(): void
    {
        Schema::create('depositos', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 100)->unique();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        DB::table('depositos')->insert([
            ['id' => 1, 'nome' => 'Goiânia', 'ativo' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'nome' => 'São Paulo', 'ativo' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Endereços: o nome passa a ser único dentro do CD (SP pode repetir nomes de Goiânia).
        Schema::table('locations', function (Blueprint $table) {
            $table->foreignId('deposito_id')->default(1)->after('id')->constrained('depositos');
            $table->dropUnique(['nome']);
            $table->unique(['deposito_id', 'nome']);
        });

        foreach (['withdrawal_requests', 'inventory_counts', 'movements'] as $tabela) {
            Schema::table($tabela, function (Blueprint $table) {
                $table->foreignId('deposito_id')->default(1)->after('id')->constrained('depositos');
            });
        }

        // CDs liberados por usuário (admin acessa todos). Quem já existe fica com Goiânia.
        Schema::table('users', function (Blueprint $table) {
            $table->json('depositos')->nullable()->after('permissions');
        });
        DB::table('users')->update(['depositos' => json_encode([1])]);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('depositos'));

        foreach (['movements', 'inventory_counts', 'withdrawal_requests'] as $tabela) {
            Schema::table($tabela, fn (Blueprint $t) => $t->dropConstrainedForeignId('deposito_id'));
        }

        Schema::table('locations', function (Blueprint $table) {
            $table->dropUnique(['deposito_id', 'nome']);
            $table->dropConstrainedForeignId('deposito_id');
            $table->unique('nome');
        });

        Schema::dropIfExists('depositos');
    }
};
