<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Espelho local dos códigos de barras do Microvix (cod_barra -> cod_produto).
     * A API do Linx não busca por código de barras (só pagina por timestamp),
     * então mantemos essa tabela sincronizada para a consulta ser instantânea.
     */
    public function up(): void
    {
        Schema::create('microvix_barras', function (Blueprint $table) {
            $table->id();
            $table->string('cod_barra', 60)->unique();
            $table->string('cod_produto', 30)->index();
            $table->unsignedBigInteger('ts')->default(0)->index(); // timestamp do Linx
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('microvix_barras');
    }
};
