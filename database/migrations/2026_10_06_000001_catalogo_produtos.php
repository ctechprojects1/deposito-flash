<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Base de produtos de apoio (planilhas da Shopee etc.): itens que nem
     * sempre estão no Microvix. Usada para achar o produto por SKU, EAN ou
     * nome na hora de endereçar; depois será cruzada com o Microvix pelo SKU.
     */
    public function up(): void
    {
        Schema::create('catalogo_produtos', function (Blueprint $table) {
            $table->id();
            $table->string('fonte', 30);            // shopee, tiktok, planilha...
            $table->string('chave', 191);           // identifica a linha na fonte (reimportar atualiza)
            $table->string('nome', 255);
            $table->string('variacao', 191)->nullable();
            $table->string('sku', 100)->nullable();
            $table->string('sku_pai', 100)->nullable();
            $table->string('ean', 60)->nullable();
            $table->string('externo_id', 60)->nullable();
            $table->timestamps();

            $table->unique(['fonte', 'chave']);
            $table->index('sku');
            $table->index('sku_pai');
            $table->index('ean');
        });

        // SKU do produto (guardado para cruzar com o Microvix depois).
        Schema::table('products', function (Blueprint $table) {
            $table->string('sku', 100)->nullable()->after('codigo_barras');
            $table->index('sku');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['sku']);
            $table->dropColumn('sku');
        });
        Schema::dropIfExists('catalogo_produtos');
    }
};
