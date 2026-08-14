<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabela pivô de estoque: relaciona qual produto está em qual endereço
     * e em qual quantidade.
     */
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('location_id')
                  ->constrained('locations')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete();

            $table->foreignId('product_id')
                  ->constrained('products')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete();

            $table->decimal('quantidade', 12, 2)->default(0);

            $table->timestamps();

            // Um produto só pode ter um registro por endereço.
            $table->unique(['location_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
