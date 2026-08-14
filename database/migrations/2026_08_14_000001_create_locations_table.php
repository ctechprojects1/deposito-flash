<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Endereços de estoque.
     *
     * Cada endereço é nomeado com um time de futebol e possui coordenadas
     * físicas (corredor / esteira / eixo) para posicionamento no mapa visual.
     */
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();

            // Nome do endereço (time de futebol) — deve ser único.
            $table->string('nome')->unique();

            // Coordenadas físicas / logísticas.
            $table->string('corredor', 50)->nullable();
            $table->string('esteira', 50)->nullable();

            // Coordenadas para renderização no mapa do estoque.
            $table->integer('eixo_x')->default(0);
            $table->integer('eixo_y')->default(0);

            $table->boolean('ativo')->default(true);
            $table->timestamps();

            // Índice de apoio para consultas do mapa.
            $table->index(['eixo_x', 'eixo_y']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
