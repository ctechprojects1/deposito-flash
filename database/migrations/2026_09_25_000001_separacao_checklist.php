<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Solicitação a partir de nota/pedido + separação por checklist
     * (iniciar, pausar, retomar, finalizar; admin reabre).
     */
    public function up(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            // enum -> string, para aceitar o status "pausada".
            $table->string('status', 20)->default('pendente')->change();

            $table->string('tipo_documento', 30)->nullable()->after('destino');
            $table->string('numero_documento', 100)->nullable()->after('tipo_documento');
            $table->timestamp('iniciada_em')->nullable();
            $table->timestamp('finalizada_em')->nullable();
            $table->timestamp('reaberta_em')->nullable();
            $table->foreignId('reaberta_por_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('withdrawal_items', function (Blueprint $table) {
            $table->string('codigo_microvix', 60)->nullable()->after('product_id');
            $table->string('descricao')->nullable()->after('codigo_microvix');
            $table->decimal('quantidade_documento', 12, 2)->default(0)->after('descricao');
            $table->boolean('retirado')->default(false);
            $table->timestamp('retirado_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('withdrawal_items', function (Blueprint $table) {
            $table->dropColumn(['codigo_microvix', 'descricao', 'quantidade_documento', 'retirado', 'retirado_em']);
        });

        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reaberta_por_id');
            $table->dropColumn(['tipo_documento', 'numero_documento', 'iniciada_em', 'finalizada_em', 'reaberta_em']);
        });
    }
};
