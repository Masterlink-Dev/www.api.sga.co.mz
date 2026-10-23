<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MeuAcesso · Contas de acesso (uma só tabela para todos)
 *
 * - cidadao     → usa a app móvel
 * - funcionario → usa o portal da instituição
 * - admin       → equipa Masterlink (administra a plataforma)
 *
 * As colunas name, email, password e remember_token ficam com o nome padrão
 * do Laravel para o login, o Sanctum e as factories funcionarem sem alterações.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedTinyInteger('tipo')->default(0)->index(); // 0 utilizador, 1 secretaria, 2 admin

            // Identificação e contactos
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('telefone', 20)->nullable()->unique()->comment('Formato E.164, ex.: +258841234567');
            $table->timestamp('telefone_verificado_em')->nullable();
            $table->string('password')->nullable()->comment('Null = conta que entra só com código SMS ou Google');
            $table->string('google_id')->nullable()->unique();
            $table->string('avatar_path')->nullable();

            // Preferências
            $table->string('idioma', 5)->default('pt');
            $table->string('fuso_horario', 40)->default('Africa/Maputo');
            $table->boolean('aceita_sms')->default(true);
            $table->boolean('aceita_email')->default(true);
            $table->boolean('aceita_push')->default(true);
            $table->time('nao_incomodar_inicio')->nullable()->comment('Não enviar SMS/push a partir desta hora');
            $table->time('nao_incomodar_fim')->nullable();

            // Estado da conta
            $table->enum('estado', ['pendente', 'activo', 'suspenso', 'bloqueado', 'eliminado'])->default('activo')->index();
            $table->string('motivo_estado')->nullable();
            $table->boolean('deve_alterar_senha')->default(false)->comment('True quando a senha foi dada pelo administrador');
            $table->timestamp('senha_alterada_em')->nullable();

            // Segurança
            $table->boolean('dois_factores_activo')->default(false);
            $table->enum('dois_factores_canal', ['sms', 'email', 'app'])->nullable();
            $table->text('dois_factores_segredo')->nullable()->comment('Encriptado');
            $table->text('dois_factores_codigos_recuperacao')->nullable()->comment('Encriptado');
            $table->unsignedTinyInteger('tentativas_falhadas')->default(0);
            $table->timestamp('bloqueado_ate')->nullable();
            $table->timestamp('ultimo_acesso_em')->nullable();
            $table->string('ultimo_acesso_ip', 45)->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
