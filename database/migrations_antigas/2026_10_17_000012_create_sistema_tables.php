<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MeuAcesso · Auditoria, definições da plataforma e versões da app
 */
return new class extends Migration
{
    public function up(): void
    {
        // Tudo o que se cria, altera ou apaga (quem, quando, antes e depois)
        Schema::create('registos_auditoria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('instituicao_id')->nullable()->constrained('instituicoes')->nullOnDelete();
            $table->string('accao', 60)->index()->comment('criar, actualizar, apagar, aprovar, exportar, login_admin...');
            $table->nullableMorphs('entidade');
            $table->json('valores_antigos')->nullable();
            $table->json('valores_novos')->nullable();
            $table->string('descricao')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('url')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        // Definições gerais da plataforma (editadas pelo admin Masterlink)
        Schema::create('definicoes_sistema', function (Blueprint $table) {
            $table->id();
            $table->string('chave', 80)->unique()->comment('Ex.: codigo.validade_minutos, sms.fornecedor_padrao');
            $table->text('valor')->nullable();
            $table->enum('tipo', ['texto', 'numero', 'booleano', 'json'])->default('texto');
            $table->string('grupo', 40)->default('geral');
            $table->string('descricao')->nullable();
            $table->boolean('publica')->default(false)->comment('Se a app pode ler (ex.: telefone de apoio)');
            $table->timestamps();
        });

        // Obrigar a actualizar a app quando sai uma versão importante
        Schema::create('versoes_app', function (Blueprint $table) {
            $table->id();
            $table->enum('plataforma', ['android', 'ios']);
            $table->string('versao', 20);
            $table->unsignedInteger('build')->nullable();
            $table->string('versao_minima', 20)->nullable()->comment('Abaixo desta, a app pede para actualizar');
            $table->boolean('obrigatoria')->default(false);
            $table->text('novidades')->nullable();
            $table->string('url_loja')->nullable();
            $table->timestamp('publicada_em')->nullable();
            $table->timestamps();

            $table->unique(['plataforma', 'versao']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('versoes_app');
        Schema::dropIfExists('definicoes_sistema');
        Schema::dropIfExists('registos_auditoria');
    }
};
