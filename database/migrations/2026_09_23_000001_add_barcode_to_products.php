<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Código de barras (opcional) e Microvix passa a ser opcional.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('codigo_barras', 60)->nullable()->unique()->after('codigo_microvix');
            $table->string('codigo_microvix', 60)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['codigo_barras']);
            $table->dropColumn('codigo_barras');
        });
    }
};
