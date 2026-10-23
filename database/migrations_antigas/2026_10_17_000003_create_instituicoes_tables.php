<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MeuAcesso · Instituições públicas e a sua organização interna
 *
 * instituicao → unidades (recepções/balcões) → sectores
 * + motivos de visita, horários, dias encerrados e definições de privacidade
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_instituicao', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 80)->unique()->comment('Ministério, Hospital, Conservatória, Município...');
            $table->string('descricao')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('instituicoes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tipo_instituicao_id')->nullable()->constrained('tipos_instituicao')->nullOnDelete();
            $table->foreignId('instituicao_mae_id')->nullable()->constrained('instituicoes')->nullOnDelete()
                ->comment('Ex.: Direcção Provincial ligada ao Ministério');

            // Identificação
            $table->string('nome');
            $table->string('sigla', 20)->nullable();
            $table->string('slug')->unique();
            $table->string('nuit', 20)->nullable()->unique();
            $table->foreignId('logo_ficheiro_id')->nullable()->constrained('ficheiros')->nullOnDelete();
            $table->string('cor_primaria', 7)->nullable()->comment('Hex, para personalizar o portal');

            // Contactos e localização
            $table->string('email')->nullable();
            $table->string('telefone', 20)->nullable();
            $table->string('website')->nullable();
            $table->string('endereco')->nullable();
            $table->string('bairro', 100)->nullable();
            $table->foreignId('provincia_id')->nullable()->constrained('provincias')->nullOnDelete();
            $table->foreignId('distrito_id')->nullable()->constrained('distritos')->nullOnDelete();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('fuso_horario', 40)->default('Africa/Maputo');

            // Ciclo de vida (as credenciais são dadas pelo administrador da plataforma)
            $table->enum('estado', ['pendente', 'activa', 'suspensa', 'encerrada'])->default('pendente')->index();
            $table->string('motivo_estado')->nullable();
            $table->foreignId('criada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activada_em')->nullable();
            $table->foreignId('activada_por')->nullable()->constrained('users')->nullOnDelete();

            // Responsável / ponto focal
            $table->string('responsavel_nome')->nullable();
            $table->string('responsavel_cargo')->nullable();
            $table->string('responsavel_email')->nullable();
            $table->string('responsavel_telefone', 20)->nullable();

            // Contrato e limites (fase futura)
            $table->date('contrato_inicio')->nullable();
            $table->date('contrato_fim')->nullable();
            $table->unsignedInteger('limite_funcionarios')->nullable();
            $table->unsignedInteger('limite_sms_mensal')->nullable();
            $table->unsignedInteger('limite_email_mensal')->nullable();

            $table->json('extra')->nullable()->comment('Campos livres para necessidades futuras');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('unidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instituicao_id')->constrained('instituicoes')->cascadeOnDelete();
            $table->string('nome')->comment('Ex.: Recepção Central, Balcão B, Portaria Norte');
            $table->string('codigo', 20)->nullable();
            $table->string('endereco')->nullable();
            $table->string('piso', 30)->nullable();
            $table->string('telefone', 20)->nullable();
            $table->foreignId('provincia_id')->nullable()->constrained('provincias')->nullOnDelete();
            $table->foreignId('distrito_id')->nullable()->constrained('distritos')->nullOnDelete();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('raio_metros')->nullable()->comment('Geofence para check-in pela app (futuro)');
            $table->unsignedSmallInteger('capacidade_maxima')->nullable()->comment('Máximo de visitantes dentro ao mesmo tempo');
            $table->boolean('principal')->default(false);
            $table->boolean('activa')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['instituicao_id', 'nome']);
        });

        Schema::create('sectores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instituicao_id')->constrained('instituicoes')->cascadeOnDelete();
            $table->foreignId('unidade_id')->nullable()->constrained('unidades')->nullOnDelete();
            $table->string('nome');
            $table->string('piso', 30)->nullable();
            $table->string('sala', 30)->nullable();
            $table->string('responsavel_nome')->nullable();
            $table->unsignedBigInteger('responsavel_funcionario_id')->nullable()->comment('FK criada na migration de acessos');
            $table->string('telefone_extensao', 10)->nullable();
            $table->string('email')->nullable();
            $table->boolean('notificar_chegada')->default(false)->comment('Avisar o sector por SMS/e-mail quando chega um visitante');
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['instituicao_id', 'nome']);
        });

        Schema::create('motivos_visita', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instituicao_id')->nullable()->constrained('instituicoes')->cascadeOnDelete()
                ->comment('Null = motivo padrão da plataforma');
            $table->string('nome');
            $table->string('descricao')->nullable();
            $table->unsignedSmallInteger('tempo_previsto_min')->nullable();
            $table->boolean('exige_agendamento')->default(false);
            $table->boolean('exige_documento_extra')->default(false);
            $table->boolean('e_outro')->default(false)->comment('Opção "Outro", com texto livre');
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['instituicao_id', 'nome']);
        });

        Schema::create('horarios_atendimento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instituicao_id')->constrained('instituicoes')->cascadeOnDelete();
            $table->foreignId('unidade_id')->nullable()->constrained('unidades')->cascadeOnDelete();
            $table->unsignedTinyInteger('dia_semana')->comment('1 = segunda ... 7 = domingo');
            $table->time('abre_as');
            $table->time('fecha_as');
            $table->time('pausa_inicio')->nullable();
            $table->time('pausa_fim')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['instituicao_id', 'unidade_id', 'dia_semana']);
        });

        Schema::create('dias_encerrados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instituicao_id')->nullable()->constrained('instituicoes')->cascadeOnDelete()
                ->comment('Null = feriado nacional');
            $table->date('data');
            $table->string('descricao');
            $table->boolean('repete_todos_anos')->default(false);
            $table->timestamps();

            $table->index(['instituicao_id', 'data']);
        });

        // Um registo por instituição: o que aparece no ecrã "Definições" do portal
        Schema::create('definicoes_instituicao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instituicao_id')->unique()->constrained('instituicoes')->cascadeOnDelete();

            // Privacidade
            $table->boolean('mascarar_bi')->default(true);
            $table->boolean('confirmar_rosto')->default(true);
            $table->boolean('motivo_obrigatorio')->default(true);
            $table->boolean('sector_obrigatorio')->default(true);
            $table->boolean('permitir_registo_manual')->default(true)->comment('Registo pelo BI físico, sem app');
            $table->boolean('saida_automatica')->default(true);
            $table->time('hora_saida_automatica')->default('18:00:00');
            $table->unsignedSmallInteger('prazo_guarda_meses')->default(24);
            $table->boolean('pedir_foto_no_balcao')->default(false);
            $table->boolean('emitir_cracha')->default(false);

            // Avisos
            $table->boolean('alerta_visita_longa')->default(true);
            $table->unsignedSmallInteger('minutos_visita_longa')->default(90);
            $table->boolean('resumo_diario')->default(true);
            $table->time('hora_resumo_diario')->default('16:00:00');
            $table->boolean('relatorio_mensal')->default(true);

            // O que se envia ao cidadão
            $table->boolean('sms_ao_cidadao_entrada')->default(false);
            $table->boolean('sms_ao_cidadao_saida')->default(false);
            $table->boolean('email_ao_cidadao_entrada')->default(false);
            $table->boolean('push_consulta_dados')->default(true)->comment('Aviso "a instituição acedeu aos seus dados"');
            $table->string('remetente_sms', 11)->nullable()->comment('Sender ID próprio (máx. 11 caracteres)');
            $table->string('email_resposta')->nullable()->comment('Reply-to dos e-mails da instituição');

            $table->json('extra')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('definicoes_instituicao');
        Schema::dropIfExists('dias_encerrados');
        Schema::dropIfExists('horarios_atendimento');
        Schema::dropIfExists('motivos_visita');
        Schema::dropIfExists('sectores');
        Schema::dropIfExists('unidades');
        Schema::dropIfExists('instituicoes');
        Schema::dropIfExists('tipos_instituicao');
    }
};
