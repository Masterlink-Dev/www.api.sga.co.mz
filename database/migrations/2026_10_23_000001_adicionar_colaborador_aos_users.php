<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colaboradores do super administrador (co-adjuntos).
 * São contas tipo 3 com colaborador = true: fazem tudo o que o super administrador faz,
 * menos registar outros colaboradores. O super administrador principal tem colaborador = false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('colaborador')->default(false)->after('tipo')
                ->comment('Só no tipo 3: true = colaborador (não regista colaboradores)');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('colaborador');
        });
    }
};
