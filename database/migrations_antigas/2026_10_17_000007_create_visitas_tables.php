<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MeuAcesso · Visitas (o "livro de entradas e saídas" informatizado)
 *
 * agendamentos → visitas → acompanhantes
 * + notas da recepção, registo de consultas aos dados e restrições de acesso
 */
return new class extends Migration
{
    public function up(): void
    {
        // Marcações prévias (fase futura: o cidadão marca pela app)
        Schema::create('agendamentos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('instituicao_id')->constrained('instituicoes')->cascadeOnDelete();
            $table->foreignId('unidade_id')->nullable()->constrained('unidades')->nullOnDelete();
            $table->foreignId('sector_id')->nullable()->constrained('sectores')->nullOnDelete();
            $table->foreignId('motivo_id')->nullable()->constrained('motivos_visita')->nullOnDelete();
            $table->foreignId('cidadao_id')->nullable()->constrained('cidadaos')->nullOnDelete();
            $table->string('nome_visitante')->nullable()->comment('Quando ainda não existe cidadão');
            $table->string('telefone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('pessoa_a_visitar')->nullable();
            $table->foreignId('anfitriao_funcionario_id')->nullable()->constrained('funcionarios')->nullOnDelete();
            $table->dateTime('inicio')->index();
            $table->dateTime('fim')->nullable();
            $table->string('codigo_confirmacao', 10)->nullable()->index();
            $table->enum('estado', ['pendente', 'confirmado', 'cancelado', 'compareceu', 'faltou'])->default('pendente')->index();
            $table->enum('origem', ['app', 'portal', 'telefone', 'api'])->default('portal');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('lembrete_enviado_em')->nullable()->comment('SMS/e-mail de lembrete');
            $table->timestamp('cancelado_em')->nullable();
            $table->string('motivo_cancelamento')->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('visitas', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('instituicao_id')->constrained('instituicoes')->cascadeOnDelete();
            $table->foreignId('unidade_id')->nullable()->constrained('unidades')->nullOnDelete();
            $table->foreignId('sector_id')->nullable()->constrained('sectores')->nullOnDelete();
            $table->foreignId('motivo_id')->nullable()->constrained('motivos_visita')->nullOnDelete();
            $table->string('motivo_outro')->nullable()->comment('Texto livre quando o motivo é "Outro"');
            $table->foreignId('cidadao_id')->nullable()->constrained('cidadaos')->nullOnDelete();
            $table->foreignId('codigo_acesso_id')->nullable()->constrained('codigos_acesso')->nullOnDelete();
            $table->foreignId('agendamento_id')->nullable()->constrained('agendamentos')->nullOnDelete();

            // Cópia dos dados no momento da visita (os relatórios continuam certos
            // mesmo que o cidadão mude o nome ou seja anonimizado)
            $table->string('nome_visitante');
            $table->string('documento_mascarado', 20)->nullable();
            $table->enum('sexo_visitante', ['F', 'M'])->nullable();
            $table->unsignedTinyInteger('idade_visitante')->nullable();

            // Como foi identificado
            $table->enum('metodo_identificacao', ['codigo', 'qr', 'bi_manual', 'bi_leitor', 'agendamento', 'outro'])->index();
            $table->boolean('rosto_confirmado')->default(false);

            // Entrada
            $table->dateTime('entrada_em')->index();
            $table->foreignId('entrada_registada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('entrada_dispositivo_id')->nullable()->constrained('dispositivos')->nullOnDelete();

            // Saída
            $table->dateTime('saida_em')->nullable()->index();
            $table->foreignId('saida_registada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('tipo_saida', ['manual', 'automatica', 'app'])->nullable();
            $table->unsignedInteger('duracao_minutos')->nullable()->comment('Calculado ao registar a saída');

            // Detalhes da visita
            $table->string('pessoa_a_visitar')->nullable();
            $table->foreignId('anfitriao_funcionario_id')->nullable()->constrained('funcionarios')->nullOnDelete();
            $table->unsignedTinyInteger('acompanhantes')->default(0);
            $table->string('cracha_numero', 20)->nullable();
            $table->string('viatura_matricula', 15)->nullable();
            $table->string('objectos_declarados')->nullable()->comment('Ex.: portátil, pasta');
            $table->text('observacoes')->nullable();

            $table->enum('estado', ['em_curso', 'concluida', 'fechada_automaticamente', 'cancelada'])->default('em_curso')->index();
            $table->timestamp('alerta_visita_longa_em')->nullable();
            $table->boolean('notificacao_sector_enviada')->default(false);

            // Avaliação pelo cidadão na app (fase futura)
            $table->unsignedTinyInteger('avaliacao')->nullable()->comment('1 a 5');
            $table->text('avaliacao_comentario')->nullable();
            $table->timestamp('avaliado_em')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['instituicao_id', 'entrada_em']);
            $table->index(['instituicao_id', 'estado']);
            $table->index(['cidadao_id', 'entrada_em']);
        });

        Schema::create('acompanhantes_visita', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visita_id')->constrained('visitas')->cascadeOnDelete();
            $table->foreignId('cidadao_id')->nullable()->constrained('cidadaos')->nullOnDelete();
            $table->string('nome');
            $table->text('documento_numero')->nullable()->comment('Encriptado');
            $table->string('relacao', 40)->nullable()->comment('Filho(a), colega, advogado...');
            $table->boolean('menor')->default(false);
            $table->timestamps();
        });

        // Notas da recepção na ficha do visitante (cada instituição vê só as suas)
        Schema::create('notas_visitante', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instituicao_id')->constrained('instituicoes')->cascadeOnDelete();
            $table->foreignId('cidadao_id')->constrained('cidadaos')->cascadeOnDelete();
            $table->foreignId('visita_id')->nullable()->constrained('visitas')->nullOnDelete();
            $table->foreignId('autor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('texto');
            $table->enum('visibilidade', ['equipa', 'supervisores', 'privada'])->default('equipa');
            $table->boolean('fixada')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['instituicao_id', 'cidadao_id']);
        });

        // "Quem acedeu a estes dados": cada consulta fica registada e o cidadão vê-a na app
        Schema::create('consultas_dados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cidadao_id')->constrained('cidadaos')->cascadeOnDelete();
            $table->foreignId('instituicao_id')->nullable()->constrained('instituicoes')->nullOnDelete();
            $table->foreignId('unidade_id')->nullable()->constrained('unidades')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()->comment('Quem consultou');
            $table->foreignId('codigo_acesso_id')->nullable()->constrained('codigos_acesso')->nullOnDelete();
            $table->foreignId('visita_id')->nullable()->constrained('visitas')->nullOnDelete();
            $table->enum('tipo', ['codigo', 'qr', 'pesquisa_documento', 'ficha', 'exportacao', 'api'])->index();
            $table->json('campos_acedidos')->nullable();
            $table->string('finalidade')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('cidadao_notificado_em')->nullable();
            $table->timestamp('contestada_em')->nullable()->comment('O cidadão tocou em "Não foi você?"');
            $table->text('contestacao_motivo')->nullable();
            $table->enum('contestacao_estado', ['aberta', 'em_analise', 'resolvida', 'improcedente'])->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['cidadao_id', 'created_at']);
            $table->index(['instituicao_id', 'created_at']);
        });

        // Pessoas com entrada condicionada numa instituição (fase futura)
        Schema::create('restricoes_acesso', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instituicao_id')->constrained('instituicoes')->cascadeOnDelete();
            $table->foreignId('cidadao_id')->constrained('cidadaos')->cascadeOnDelete();
            $table->enum('tipo', ['aviso', 'acompanhamento_obrigatorio', 'proibido'])->default('aviso');
            $table->string('motivo');
            $table->date('valido_ate')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('aprovado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->index(['instituicao_id', 'cidadao_id', 'activa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restricoes_acesso');
        Schema::dropIfExists('consultas_dados');
        Schema::dropIfExists('notas_visitante');
        Schema::dropIfExists('acompanhantes_visita');
        Schema::dropIfExists('visitas');
        Schema::dropIfExists('agendamentos');
    }
};
