<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exclusão de endereços: some do sistema, mas o histórico
     * (movimentações, solicitações, contagens) continua mostrando o nome.
     */
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
