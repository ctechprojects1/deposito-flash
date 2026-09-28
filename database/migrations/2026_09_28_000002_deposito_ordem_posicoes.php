<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ordem das posições no mapa, por CD:
     *  - nivel      (Goiânia): 1A, 1B, 2A, 2B...  (agrupa por número)
     *  - alfabetica (São Paulo): A1, A2, A3, B1, B2... (ordem alfabética)
     */
    public function up(): void
    {
        Schema::table('depositos', function (Blueprint $table) {
            $table->string('ordem_posicoes', 20)->default('nivel')->after('nome');
        });

        DB::table('depositos')->where('id', 2)->update(['ordem_posicoes' => 'alfabetica']);
    }

    public function down(): void
    {
        Schema::table('depositos', fn (Blueprint $t) => $t->dropColumn('ordem_posicoes'));
    }
};
