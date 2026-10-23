<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MeuAcesso · Perfis, permissões, equipa das instituições, pedidos de colegas,
 * envios de credenciais e dispositivos
 *
 * Fluxo:
 *  1. Super administrador cria o administrador (users.tipo = 2, só o email)  → envios_credenciais
 *  2. Administrador regista a equipa (users.tipo = 1 + funcionarios)          → envios_credenciais
 *  3. Um funcionário propõe um colega → pedidos_funcionario (pendente)
 *     O administrador aprova (cria a conta e o funcionário) ou recusa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instituicao_id')->nullable()->constrained('instituicoes')->cascadeOnDelete()
                ->comment('Null = perfil da plataforma (Secretária, Recepcionista, Funcionária, Supervisora, Gestor)');
            $table->string('nome', 60);
            $table->string('slug', 60);
            $table->string('descricao')->nullable();
            $table->enum('ambito', ['plataforma', 'instituicao'])->default('instituicao');
            $table->unsignedTinyInteger('nivel')->default(1)->comment('Quanto maior, mais permissões');
            $table->boolean('de_sistema')->default(false)->comment('Não pode ser apagado');
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();

            $table->unique(['instituicao_id', 'slug']);
        });

        Schema::create('permissoes', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique()->comment('Ex.: visitas.registar_entrada');
            $table->string('nome');
            $table->string('grupo', 40)->comment('visitas, visitantes, relatorios, definicoes, equipa...');
            $table->string('descricao')->nullable();
            $table->timestamps();
        });

        Schema::create('perfil_permissao', function (Blueprint $table) {
            $table->foreignId('perfil_id')->constrained('perfis')->cascadeOnDelete();
            $table->foreignId('permissao_id')->constrained('permissoes')->cascadeOnDelete();
            $table->primary(['perfil_id', 'permissao_id']);
        });

        // Liga uma conta (users.tipo = 1) a uma instituição: a "equipa" do administrador
        Schema::create('funcionarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('instituicao_id')->constrained('instituicoes')->cascadeOnDelete();
            $table->foreignId('unidade_id')->nullable()->constrained('unidades')->nullOnDelete();
            $table->foreignId('sector_id')->nullable()->constrained('sectores')->nullOnDelete();
            $table->foreignId('perfil_id')->nullable()->constrained('perfis')->nullOnDelete();
            $table->string('cargo')->nullable()->comment('Função mostrada no portal: Secretária, Recepcionista, Funcionária...');
            $table->string('numero_funcionario', 30)->nullable();
            $table->string('documento_numero', 30)->nullable()->comment('Nº do BI (12 algarismos + 1 letra)');
            $table->enum('origem', ['administrador', 'pedido_colega', 'importacao'])->default('administrador');
            $table->foreignId('registado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('estado', ['pendente', 'activo', 'suspenso', 'removido'])->default('activo')->index();
            $table->string('motivo_estado')->nullable();
            $table->foreignId('aprovado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aprovado_em')->nullable();
            $table->timestamp('ultimo_acesso_em')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'instituicao_id']);
        });

        Schema::table('sectores', function (Blueprint $table) {
            $table->foreign('responsavel_funcionario_id')->references('id')->on('funcionarios')->nullOnDelete();
        });

        // Um funcionário propõe um colega; só o administrador da instituição aprova ou recusa
        Schema::create('pedidos_funcionario', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('instituicao_id')->constrained('instituicoes')->cascadeOnDelete();
            $table->foreignId('proposto_por')->nullable()->constrained('users')->nullOnDelete()->comment('Funcionário que fez o pedido');

            // Dados submetidos
            $table->string('nome');
            $table->string('email');
            $table->string('telefone', 20)->nullable();
            $table->string('documento_numero', 30)->nullable()->comment('Nº do BI');
            $table->string('cargo')->nullable();
            $table->foreignId('perfil_id')->nullable()->constrained('perfis')->nullOnDelete();
            $table->foreignId('unidade_id')->nullable()->constrained('unidades')->nullOnDelete();
            $table->foreignId('sector_id')->nullable()->constrained('sectores')->nullOnDelete();
            $table->text('justificacao')->nullable();

            // Decisão
            $table->enum('estado', ['pendente', 'aprovado', 'recusado', 'cancelado'])->default('pendente')->index();
            $table->foreignId('decidido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decidido_em')->nullable();
            $table->string('motivo_recusa')->nullable();
            $table->foreignId('funcionario_id')->nullable()->constrained('funcionarios')->nullOnDelete()
                ->comment('Criado quando o pedido é aprovado');
            $table->timestamps();

            $table->index(['instituicao_id', 'estado']);
            $table->index(['proposto_por', 'estado']);
        });

        // Cada email com o endereço do portal, o email de acesso e a senha inicial
        Schema::create('envios_credenciais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->comment('Quem recebe o acesso');
            $table->foreignId('enviado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('motivo', ['criacao', 'redefinicao', 'reenvio'])->default('criacao');
            $table->enum('canal', ['email', 'sms', 'ambos'])->default('email');
            $table->string('destino')->comment('Email ou telefone para onde foi');
            $table->unsignedBigInteger('mensagem_id')->nullable()->comment('FK criada na migration de mensagens');
            $table->enum('estado', ['pendente', 'enviado', 'falhou'])->default('pendente');
            $table->timestamp('enviado_em')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        // Telemóveis com a app e computadores/tablets da recepção
        Schema::create('dispositivos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('plataforma', ['android', 'ios', 'web', 'outro'])->default('android');
            $table->string('nome')->nullable()->comment('Ex.: Tablet da entrada');
            $table->string('marca', 60)->nullable();
            $table->string('modelo', 80)->nullable();
            $table->string('sistema_versao', 30)->nullable();
            $table->string('navegador', 60)->nullable();
            $table->string('app_versao', 20)->nullable();
            $table->string('identificador')->nullable()->comment('ID do dispositivo gerado pela app');
            $table->text('push_token')->nullable()->comment('Expo / FCM / APNs');
            $table->enum('push_fornecedor', ['expo', 'fcm', 'apns'])->nullable();
            $table->timestamp('push_token_actualizado_em')->nullable();
            $table->string('ultimo_ip', 45)->nullable();
            $table->string('ultima_localizacao')->nullable();
            $table->timestamp('ultimo_acesso_em')->nullable();
            $table->boolean('confiavel')->default(false);
            $table->boolean('biometria_activa')->default(false);
            $table->timestamp('revogado_em')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revogado_em']);
        });

        // Histórico de entradas na conta (o ecrã "Sessões abertas" lê daqui e da tabela sessions)
        Schema::create('registos_acesso', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('dispositivo_id')->nullable()->constrained('dispositivos')->nullOnDelete();
            $table->enum('evento', [
                'login', 'logout', 'login_falhado', 'dois_factores', 'senha_recuperada',
                'senha_alterada', 'senha_redefinida', 'primeiro_acesso', 'conta_bloqueada', 'token_revogado',
            ])->index();
            $table->enum('metodo', ['senha', 'otp_sms', 'otp_email', 'google', 'biometria', 'token'])->nullable();
            $table->boolean('sucesso')->default(true);
            $table->string('motivo')->nullable();
            $table->string('identificador_tentado')->nullable()->comment('E-mail/telefone usado numa tentativa falhada');
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('localizacao')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registos_acesso');
        Schema::dropIfExists('dispositivos');
        Schema::dropIfExists('envios_credenciais');
        Schema::dropIfExists('pedidos_funcionario');
        Schema::table('sectores', function (Blueprint $table) {
            $table->dropForeign(['responsavel_funcionario_id']);
        });
        Schema::dropIfExists('funcionarios');
        Schema::dropIfExists('perfil_permissao');
        Schema::dropIfExists('permissoes');
        Schema::dropIfExists('perfis');
    }
};
