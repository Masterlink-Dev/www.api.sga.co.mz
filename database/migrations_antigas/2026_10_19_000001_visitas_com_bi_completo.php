<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visitas com o número do BI completo (sem máscara) e registo manual,
 * para quem não tem conta na app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitas', function (Blueprint $table) {
            $table->string('documento_numero', 30)->nullable()->index()->after('nome_visitante');
            $table->string('telefone_visitante', 20)->nullable()->after('documento_numero');
            $table->json('dados_documento')->nullable()->after('telefone_visitante')
                ->comment('Registo manual: nascimento, naturalidade, emissão, validade, morada');
        });
    }

    public function down(): void
    {
        Schema::table('visitas', function (Blueprint $table) {
            $table->dropColumn(['documento_numero', 'telefone_visitante', 'dados_documento']);
        });
    }
};
