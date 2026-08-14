<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contagem de inventário (balanço). Cada registro é uma "campanha" de
     * contagem, que congela o estoque do sistema e recebe o que foi contado.
     */
    public function up(): void
    {
        Schema::create('inventory_counts', function (Blueprint $table) {
            $table->id();
            $table->string('descricao')->nullable();

            // Escopo opcional: nome do Time (corredor) contado, ou null = geral.
            $table->string('escopo_time')->nullable();

            $table->enum('status', ['aberta', 'finalizada', 'cancelada'])
                  ->default('aberta')
                  ->index();

            // Quem iniciou (perfil conferente). Nulo enquanto não há login.
            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->unsignedInteger('total_itens')->default(0);
            $table->unsignedInteger('itens_contados')->default(0);
            $table->timestamp('finalizada_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_counts');
    }
};
