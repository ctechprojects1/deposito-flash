<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CDs que ficam no mesmo lugar físico (ex.: Goiânia e CD - Commerce)
     * recebem o mesmo `local`: na separação de um dá para tirar o item de um
     * endereço do outro.
     */
    public function up(): void
    {
        Schema::table('depositos', function (Blueprint $table) {
            $table->string('local', 60)->nullable()->after('nome');
        });
    }

    public function down(): void
    {
        Schema::table('depositos', fn (Blueprint $t) => $t->dropColumn('local'));
    }
};
