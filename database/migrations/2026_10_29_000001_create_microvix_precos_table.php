<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Espelho local dos preços do Microvix (LinxProdutosDetalhes), por código
     * interno. Buscar todos na hora leva ~25s (41 mil produtos), então
     * sincronizamos em lotes por timestamp, como as barras e os produtos.
     */
    public function up(): void
    {
        Schema::create('microvix_precos', function (Blueprint $table) {
            $table->id();
            $table->string('cod_produto', 30)->unique();
            $table->decimal('preco_venda', 12, 2)->nullable();
            $table->decimal('preco_custo', 12, 2)->nullable();
            $table->unsignedBigInteger('ts')->default(0)->index(); // timestamp do Linx
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('microvix_precos');
    }
};
