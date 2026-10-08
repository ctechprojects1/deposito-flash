<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exclusão de separação com justificativa: a solicitação vira "cancelada"
     * (não some), guardando quem excluiu, quando e o motivo.
     */
    public function up(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->timestamp('excluida_em')->nullable();
            $table->foreignId('excluida_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motivo_exclusao', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('excluida_por_id');
            $table->dropColumn(['excluida_em', 'motivo_exclusao']);
        });
    }
};
