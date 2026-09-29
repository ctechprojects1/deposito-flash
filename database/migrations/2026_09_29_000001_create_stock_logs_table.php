<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Histórico de estoque: toda alteração de saldo (baixa, entrada, ajuste,
     * zerar, contagem, separação, estorno, importação...) com antes/depois,
     * quem fez e de onde veio.
     */
    public function up(): void
    {
        Schema::create('stock_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deposito_id')->constrained('depositos');
            $table->foreignId('location_id')->constrained('locations');
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tipo', 30);
            $table->string('referencia', 120)->nullable();
            $table->string('observacao', 255)->nullable();
            $table->decimal('quantidade_anterior', 12, 2);
            $table->decimal('quantidade_nova', 12, 2);
            $table->decimal('diferenca', 12, 2);
            $table->timestamp('created_at')->nullable();

            $table->index(['deposito_id', 'created_at']);
            $table->index(['location_id', 'created_at']);
            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_logs');
    }
};
