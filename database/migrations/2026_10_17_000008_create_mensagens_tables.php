<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MeuAcesso · Comunicações: SMS, e-mail, push (e WhatsApp no futuro)
 *
 * fornecedores_mensagem → quem envia (gateway SMS, SMTP, Expo push...)
 * modelos_mensagem      → textos com {{variaveis}}, por canal e idioma
 * mensagens             → cada envio (fila + histórico + custo)
 * eventos_mensagem      → estados recebidos do fornecedor (entregue, falhou...)
 * codigos_verificacao   → códigos OTP enviados por SMS/e-mail
 * preferencias / supressoes → o que cada pessoa quer receber e quem pediu STOP
 * campanhas             → envios em massa (fase futura)
 * notifications         → caixa de notificações dentro da app (canal "database" do Laravel)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fornecedores_mensagem', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 60);
            $table->enum('canal', ['sms', 'email', 'push', 'whatsapp'])->index();
            $table->string('driver', 40)->comment('Ex.: smtp, mailgun, ses, expo, fcm, gateway_http, twilio...');
            $table->text('credenciais')->nullable()->comment('JSON encriptado: chaves, URL, utilizador...');
            $table->string('remetente', 120)->nullable()->comment('Sender ID do SMS ou e-mail "From"');
            $table->string('remetente_nome', 120)->nullable();
            $table->string('url_webhook')->nullable()->comment('Onde o fornecedor envia os relatórios de entrega');
            $table->string('segredo_webhook')->nullable();
            $table->unsignedTinyInteger('prioridade')->default(1)->comment('1 = primeiro a tentar; os outros servem de reserva');
            $table->unsignedInteger('limite_por_minuto')->nullable();
            $table->decimal('custo_unitario', 10, 4)->nullable();
            $table->string('moeda', 3)->default('MZN');
            $table->decimal('saldo', 12, 2)->nullable();
            $table->decimal('alerta_saldo_abaixo_de', 12, 2)->nullable();
            $table->timestamp('saldo_actualizado_em')->nullable();
            $table->boolean('activo')->default(true);
            $table->boolean('modo_teste')->default(false)->comment('Não envia de verdade, só regista');
            $table->timestamps();
        });

        Schema::create('modelos_mensagem', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instituicao_id')->nullable()->constrained('instituicoes')->cascadeOnDelete()
                ->comment('Null = modelo da plataforma; preenchido = versão própria da instituição');
            $table->string('codigo', 60)->comment('Ex.: otp_registo, consulta_dados, visita_entrada, credenciais_funcionario');
            $table->enum('canal', ['sms', 'email', 'push', 'whatsapp']);
            $table->string('idioma', 5)->default('pt');
            $table->string('nome');
            $table->string('descricao')->nullable();
            $table->string('assunto')->nullable()->comment('E-mail e título do push');
            $table->text('corpo')->comment('Texto com {{variaveis}}; no SMS manter até 160 caracteres');
            $table->longText('corpo_html')->nullable()->comment('Só e-mail');
            $table->json('variaveis')->nullable()->comment('Ex.: ["nome","codigo","instituicao","hora"]');
            $table->enum('categoria', ['transaccional', 'seguranca', 'aviso', 'marketing'])->default('transaccional')
                ->comment('Segurança/transaccional enviam mesmo fora de horas; marketing respeita opt-out');
            $table->unsignedSmallInteger('versao')->default(1);
            $table->boolean('activo')->default(true);
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['instituicao_id', 'codigo', 'canal', 'idioma']);
        });

        Schema::create('campanhas', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('instituicao_id')->nullable()->constrained('instituicoes')->cascadeOnDelete();
            $table->string('titulo');
            $table->enum('canal', ['sms', 'email', 'push', 'whatsapp']);
            $table->foreignId('modelo_id')->nullable()->constrained('modelos_mensagem')->nullOnDelete();
            $table->string('assunto')->nullable();
            $table->text('corpo')->nullable();
            $table->json('segmento')->nullable()->comment('Filtros dos destinatários: província, instituição, frequentes...');
            $table->enum('estado', ['rascunho', 'agendada', 'a_enviar', 'concluida', 'cancelada'])->default('rascunho');
            $table->dateTime('agendada_para')->nullable();
            $table->unsignedInteger('total_destinatarios')->default(0);
            $table->unsignedInteger('total_enviadas')->default(0);
            $table->unsignedInteger('total_entregues')->default(0);
            $table->unsignedInteger('total_falhadas')->default(0);
            $table->decimal('custo_total', 12, 2)->default(0);
            $table->foreignId('criada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('mensagens', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->enum('canal', ['sms', 'email', 'push', 'whatsapp'])->index();
            $table->foreignId('modelo_id')->nullable()->constrained('modelos_mensagem')->nullOnDelete();
            $table->foreignId('fornecedor_id')->nullable()->constrained('fornecedores_mensagem')->nullOnDelete();
            $table->foreignId('campanha_id')->nullable()->constrained('campanhas')->nullOnDelete();
            $table->foreignId('instituicao_id')->nullable()->constrained('instituicoes')->nullOnDelete()
                ->comment('Quem originou o envio (para contar a quota e o custo)');

            // Para quem
            $table->nullableMorphs('destinatario'); // users ou cidadaos
            $table->string('destino')->comment('Telefone E.164, e-mail ou push token');
            $table->string('destino_nome')->nullable();

            // O quê
            $table->string('assunto')->nullable();
            $table->text('corpo');
            $table->longText('corpo_html')->nullable();
            $table->json('variaveis')->nullable();
            $table->json('dados_push')->nullable()->comment('Dados extra para abrir um ecrã na app');
            $table->nullableMorphs('contexto'); // visita, consulta_dados, codigo_verificacao, pedido_conta...
            $table->enum('categoria', ['transaccional', 'seguranca', 'aviso', 'marketing'])->default('transaccional');
            $table->unsignedTinyInteger('prioridade')->default(5)->comment('1 = urgente (OTP) ... 9 = pode esperar');

            // Estado do envio
            $table->enum('estado', [
                'pendente', 'agendada', 'em_envio', 'enviada', 'entregue',
                'lida', 'falhada', 'rejeitada', 'cancelada', 'suprimida',
            ])->default('pendente')->index();
            $table->dateTime('agendada_para')->nullable();
            $table->timestamp('enviada_em')->nullable();
            $table->timestamp('entregue_em')->nullable();
            $table->timestamp('lida_em')->nullable()->comment('E-mail aberto / push tocado');
            $table->timestamp('clicada_em')->nullable();
            $table->timestamp('falhou_em')->nullable();
            $table->unsignedTinyInteger('tentativas')->default(0);
            $table->unsignedTinyInteger('max_tentativas')->default(3);
            $table->timestamp('proxima_tentativa_em')->nullable();
            $table->string('erro_codigo', 60)->nullable();
            $table->text('erro_mensagem')->nullable();

            // Dados do fornecedor e custos
            $table->string('id_externo')->nullable()->index()->comment('ID devolvido pelo gateway / SMTP');
            $table->unsignedTinyInteger('partes_sms')->nullable()->comment('Cada 160 caracteres = 1 parte');
            $table->unsignedSmallInteger('caracteres')->nullable();
            $table->string('operadora', 30)->nullable()->comment('Vodacom, Movitel, Tmcel...');
            $table->decimal('custo', 10, 4)->nullable();
            $table->string('moeda', 3)->default('MZN');

            $table->foreignId('criada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_origem', 45)->nullable();
            $table->timestamps();

            $table->index(['estado', 'agendada_para']);
            $table->index(['estado', 'proxima_tentativa_em']);
            $table->index(['instituicao_id', 'canal', 'created_at']);
        });

        Schema::create('eventos_mensagem', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mensagem_id')->constrained('mensagens')->cascadeOnDelete();
            $table->string('estado', 30)->comment('enviada, entregue, falhada, aberta, clicada, bounce, queixa...');
            $table->string('descricao')->nullable();
            $table->json('payload')->nullable()->comment('Resposta crua do fornecedor/webhook');
            $table->dateTime('ocorrido_em');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['mensagem_id', 'ocorrido_em']);
        });

        Schema::create('anexos_mensagem', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mensagem_id')->constrained('mensagens')->cascadeOnDelete();
            $table->foreignId('ficheiro_id')->constrained('ficheiros')->cascadeOnDelete();
            $table->string('nome_apresentado')->nullable();
            $table->timestamps();
        });

        // Códigos de confirmação (OTP) enviados por SMS ou e-mail
        Schema::create('codigos_verificacao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->enum('canal', ['sms', 'email', 'whatsapp']);
            $table->string('destino')->comment('Telefone ou e-mail para onde foi enviado');
            $table->enum('finalidade', [
                'registo', 'login', 'recuperar_senha', 'alterar_telefone', 'alterar_email',
                'confirmar_accao', 'dois_factores', 'eliminar_conta',
            ])->index();
            $table->string('codigo_hash')->comment('Guardar só o hash, nunca o código');
            $table->dateTime('expira_em');
            $table->unsignedTinyInteger('tentativas')->default(0);
            $table->unsignedTinyInteger('max_tentativas')->default(5);
            $table->timestamp('usado_em')->nullable();
            $table->foreignId('mensagem_id')->nullable()->constrained('mensagens')->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['destino', 'finalidade', 'usado_em']);
        });

        // O que cada pessoa quer receber, evento a evento e canal a canal
        Schema::create('preferencias_notificacao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('evento', 60)->comment('Ex.: consulta_dados, visita_longa, resumo_diario, documento_a_expirar');
            $table->enum('canal', ['sms', 'email', 'push', 'whatsapp']);
            $table->boolean('activo')->default(true);
            $table->json('opcoes')->nullable()->comment('Ex.: {"minutos": 90}');
            $table->timestamps();

            $table->unique(['user_id', 'evento', 'canal']);
        });

        // Contactos que não podem receber mais (pediram STOP, e-mail inválido, queixa...)
        Schema::create('supressoes_contacto', function (Blueprint $table) {
            $table->id();
            $table->enum('canal', ['sms', 'email', 'push', 'whatsapp']);
            $table->string('destino');
            $table->enum('motivo', ['pediu_stop', 'bounce', 'queixa_spam', 'numero_invalido', 'manual'])->default('manual');
            $table->enum('abrange', ['tudo', 'marketing'])->default('marketing')
                ->comment('marketing = continua a receber OTP e avisos de segurança');
            $table->string('origem')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['canal', 'destino']);
        });

        // Caixa de notificações dentro da app (canal "database" do Laravel)
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        // O email das credenciais fica ligado à mensagem que o levou
        Schema::table('envios_credenciais', function (Blueprint $table) {
            $table->foreign('mensagem_id')->references('id')->on('mensagens')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('envios_credenciais', function (Blueprint $table) {
            $table->dropForeign(['mensagem_id']);
        });
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('supressoes_contacto');
        Schema::dropIfExists('preferencias_notificacao');
        Schema::dropIfExists('codigos_verificacao');
        Schema::dropIfExists('anexos_mensagem');
        Schema::dropIfExists('eventos_mensagem');
        Schema::dropIfExists('mensagens');
        Schema::dropIfExists('campanhas');
        Schema::dropIfExists('modelos_mensagem');
        Schema::dropIfExists('fornecedores_mensagem');
    }
};
