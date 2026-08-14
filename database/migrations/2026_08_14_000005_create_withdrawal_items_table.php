<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Itens que compõem cada solicitação de retirada.
     */
    public function up(): void
    {
        Schema::create('withdrawal_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('withdrawal_request_id')
                  ->constrained('withdrawal_requests')
                  ->cascadeOnUpdate()
                  ->cascadeOnDelete();

            $table->foreignId('product_id')
                  ->constrained('products')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete();

            // Endereço de onde o item deve ser (ou foi) retirado.
            $table->foreignId('location_id')
                  ->nullable()
                  ->constrained('locations')
                  ->cascadeOnUpdate()
                  ->nullOnDelete();

            $table->decimal('quantidade_solicitada', 12, 2);
            $table->decimal('quantidade_separada', 12, 2)->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_items');
    }
};
