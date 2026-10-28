<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vendas da Shopee (e depois TikTok), vindas da planilha de pedidos "A Enviar".
     * Cada upload atualiza os pedidos (status, rastreio...). Um pedido gera a
     * separação uma vez só e entra na lista de embalagem até ser embalado.
     * Guarda só o necessário para separar e embalar (sem CPF, telefone, endereço).
     */
    public function up(): void
    {
        Schema::create('vendas_pedidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deposito_id')->constrained('depositos');
            $table->string('canal', 20);                 // shopee, tiktok
            $table->string('pedido', 60);                // ID do pedido no canal
            $table->string('status', 60)->nullable();
            $table->string('rastreio', 60)->nullable();
            $table->string('envio', 100)->nullable();    // Opção de envio (Shopee Xpress, Retirada...)
            $table->string('cliente', 191)->nullable();  // Nome do destinatário
            $table->dateTime('criado_em')->nullable();
            $table->dateTime('pago_em')->nullable();
            $table->foreignId('withdrawal_request_id')->nullable()->constrained('withdrawal_requests')->nullOnDelete();
            $table->dateTime('separado_em')->nullable();
            $table->foreignId('separado_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('embalado_em')->nullable();
            $table->foreignId('embalado_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['deposito_id', 'canal', 'pedido']);
            $table->index(['deposito_id', 'separado_em']);
            $table->index(['deposito_id', 'embalado_em']);
        });

        Schema::create('vendas_pedido_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendas_pedido_id')->constrained('vendas_pedidos')->cascadeOnDelete();
            $table->string('sku_principal', 100)->nullable();
            $table->string('sku', 100)->nullable();
            $table->string('nome', 255);
            $table->string('variacao', 191)->nullable();
            $table->decimal('quantidade', 12, 2);
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendas_pedido_itens');
        Schema::dropIfExists('vendas_pedidos');
    }
};
