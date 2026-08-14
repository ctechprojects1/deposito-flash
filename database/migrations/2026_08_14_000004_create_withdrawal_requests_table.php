<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Solicitações de retirada de material do estoque.
     */
    public function up(): void
    {
        Schema::create('withdrawal_requests', function (Blueprint $table) {
            $table->id();

            // Quem abriu a solicitação (perfil solicitante).
            $table->foreignId('solicitante_id')
                  ->constrained('users')
                  ->cascadeOnUpdate()
                  ->restrictOnDelete();

            // Quem separa o material (perfil separador) — definido depois.
            $table->foreignId('separador_id')
                  ->nullable()
                  ->constrained('users')
                  ->cascadeOnUpdate()
                  ->nullOnDelete();

            // Destino do material solicitado.
            $table->string('destino');

            // Caminho do anexo da nota fiscal (armazenado via Storage).
            $table->string('anexo_nota_path')->nullable();

            // Fluxo da solicitação.
            $table->enum('status', [
                'pendente',
                'em_separacao',
                'concluida',
                'cancelada',
            ])->default('pendente')->index();

            $table->text('observacao')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_requests');
    }
};
