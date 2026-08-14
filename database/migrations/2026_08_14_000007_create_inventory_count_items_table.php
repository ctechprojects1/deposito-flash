<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Itens de uma contagem: um por (endereço, produto), com a quantidade
     * do sistema congelada e a quantidade efetivamente contada.
     */
    public function up(): void
    {
        Schema::create('inventory_count_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('inventory_count_id')
                  ->constrained('inventory_counts')
                  ->cascadeOnDelete();

            $table->foreignId('location_id')->constrained('locations');
            $table->foreignId('product_id')->constrained('products');

            // Congelado no início da contagem.
            $table->decimal('qtd_sistema', 12, 2)->default(0);

            // O que foi contado fisicamente (0 é válido = "não achei nenhum").
            $table->decimal('qtd_contada', 12, 2)->default(0);
            $table->boolean('contada')->default(false);

            $table->timestamps();

            $table->unique(['inventory_count_id', 'location_id', 'product_id'], 'count_item_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_count_items');
    }
};
