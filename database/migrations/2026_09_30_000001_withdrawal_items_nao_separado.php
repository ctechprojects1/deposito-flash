<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Item que o separador não conseguiu separar (sem estoque, não achou,
     * pedido errado...): fica registrado com o motivo e não dá baixa.
     */
    public function up(): void
    {
        Schema::table('withdrawal_items', function (Blueprint $table) {
            $table->boolean('nao_separado')->default(false)->after('retirado_em');
            $table->string('motivo_nao_separado', 255)->nullable()->after('nao_separado');
        });
    }

    public function down(): void
    {
        Schema::table('withdrawal_items', function (Blueprint $table) {
            $table->dropColumn(['nao_separado', 'motivo_nao_separado']);
        });
    }
};
