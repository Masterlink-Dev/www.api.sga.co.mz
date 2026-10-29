<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// O super administrador responde às avaliações da app (uma resposta por avaliação, não é um chat).
// Na app a resposta aparece num toast e desaparece 24 h depois de o utilizador a ver.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliacoes_app', function (Blueprint $table) {
            $table->text('resposta')->nullable()->after('app_versao');
            $table->foreignId('respondido_por')->nullable()->after('resposta')->constrained('users')->nullOnDelete();
            $table->timestamp('respondido_em')->nullable()->after('respondido_por');
            $table->timestamp('resposta_vista_em')->nullable()->after('respondido_em')
                ->comment('Quando o utilizador viu a resposta na app (some 24 h depois)');
        });
    }

    public function down(): void
    {
        Schema::table('avaliacoes_app', function (Blueprint $table) {
            $table->dropConstrainedForeignId('respondido_por');
            $table->dropColumn(['resposta', 'respondido_em', 'resposta_vista_em']);
        });
    }
};
