<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SKUs de anúncio (Shopee/TikTok) de cada produto. Quando um produto da
     * base Shopee é ligado ao Microvix, o código e a referência passam a ser
     * os do Microvix; o SKU antigo fica aqui para as vendas continuarem
     * achando o produto sem a planilha da Shopee.
     */
    public function up(): void
    {
        Schema::create('produto_skus', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 100)->unique();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produto_skus');
    }
};
