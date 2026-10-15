<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Espelho local do cadastro de produtos do Microvix (nome + referência).
     * A API do Linx não busca por nome nem por referência (só pagina por
     * timestamp), então mantemos essa tabela sincronizada, como a de barras.
     */
    public function up(): void
    {
        Schema::create('microvix_produtos', function (Blueprint $table) {
            $table->id();
            $table->string('cod_produto', 30)->unique();
            $table->string('nome', 255);
            $table->string('referencia', 120)->nullable()->index();
            $table->boolean('desativado')->default(false);
            $table->unsignedBigInteger('ts')->default(0)->index(); // timestamp do Linx
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('microvix_produtos');
    }
};
