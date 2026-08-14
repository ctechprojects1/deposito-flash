<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Produtos, integrados ao ERP Microvix pelo `codigo_microvix`.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            // Código único vindo do Microvix — chave de integração.
            $table->string('codigo_microvix', 60)->unique();

            $table->string('nome');

            // Status do produto no sistema de endereçamento.
            $table->enum('status', ['ativo', 'inativo'])
                  ->default('ativo')
                  ->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
