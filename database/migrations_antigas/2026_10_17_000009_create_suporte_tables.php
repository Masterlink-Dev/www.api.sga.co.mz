<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MeuAcesso · Suporte, perguntas frequentes e avaliação da app
 * (ecrã "Suporte" da app e "Precisa de ajuda?" do portal)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perguntas_frequentes', function (Blueprint $table) {
            $table->id();
            $table->enum('publico', ['cidadao', 'instituicao', 'todos'])->default('todos');
            $table->string('categoria', 60)->nullable();
            $table->string('pergunta');
            $table->text('resposta');
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->unsignedInteger('vezes_util')->default(0);
            $table->unsignedInteger('vezes_nao_util')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('pedidos_suporte', function (Blueprint $table) {
            $table->id();
            $table->string('referencia', 20)->unique()->comment('Ex.: SUP-2026-000123');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('instituicao_id')->nullable()->constrained('instituicoes')->nullOnDelete();
            $table->string('nome_contacto')->nullable();
            $table->string('email_contacto')->nullable();
            $table->string('telefone_contacto', 20)->nullable();
            $table->enum('categoria', ['conta', 'codigo', 'documentos', 'visita', 'privacidade', 'erro_app', 'sugestao', 'outro'])->default('outro');
            $table->string('assunto');
            $table->text('descricao');
            $table->enum('prioridade', ['baixa', 'normal', 'alta', 'urgente'])->default('normal');
            $table->enum('estado', ['aberto', 'em_analise', 'aguarda_cliente', 'resolvido', 'fechado'])->default('aberto')->index();
            $table->enum('canal_origem', ['app', 'portal', 'email', 'telefone', 'sms'])->default('app');
            $table->foreignId('atribuido_a')->nullable()->constrained('users')->nullOnDelete();
            $table->string('app_versao', 20)->nullable();
            $table->string('dispositivo_info')->nullable();
            $table->timestamp('primeira_resposta_em')->nullable();
            $table->timestamp('resolvido_em')->nullable();
            $table->unsignedTinyInteger('satisfacao')->nullable()->comment('1 a 5, depois de resolvido');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('respostas_suporte', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_suporte_id')->constrained('pedidos_suporte')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('mensagem');
            $table->boolean('nota_interna')->default(false)->comment('Só a equipa de suporte vê');
            $table->foreignId('ficheiro_id')->nullable()->constrained('ficheiros')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('avaliacoes_app', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('estrelas')->comment('1 a 5');
            $table->text('comentario')->nullable();
            $table->enum('plataforma', ['android', 'ios', 'web'])->nullable();
            $table->string('app_versao', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacoes_app');
        Schema::dropIfExists('respostas_suporte');
        Schema::dropIfExists('pedidos_suporte');
        Schema::dropIfExists('perguntas_frequentes');
    }
};
