<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MeuAcesso · Ficheiros (fotos do rosto, imagens do BI, logótipos, relatórios, anexos)
 *
 * Um ficheiro pertence a "qualquer coisa" (dono_type / dono_id): um cidadão,
 * uma instituição, um relatório, uma mensagem...
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ficheiros', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->nullableMorphs('dono');
            $table->enum('categoria', [
                'foto_rosto', 'documento_frente', 'documento_verso', 'assinatura',
                'logotipo', 'relatorio', 'anexo_mensagem', 'anexo_suporte', 'outro',
            ])->default('outro')->index();
            $table->string('disco', 30)->default('local')->comment('Disco do Laravel: local, public, s3...');
            $table->string('caminho');
            $table->string('nome_original')->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('tamanho_bytes')->default(0);
            $table->unsignedSmallInteger('largura')->nullable();
            $table->unsignedSmallInteger('altura')->nullable();
            $table->char('hash_sha256', 64)->nullable()->index()->comment('Detecta ficheiros repetidos ou alterados');
            $table->boolean('privado')->default(true);
            $table->boolean('encriptado')->default(false);
            $table->timestamp('expira_em')->nullable()->comment('Para apagar automaticamente (ex.: exportações)');
            $table->foreignId('enviado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ficheiros');
    }
};
