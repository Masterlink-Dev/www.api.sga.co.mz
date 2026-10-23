<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MeuAcesso · Cidadãos (a base única partilhada pela app e pelo portal)
 *
 * Um cidadão pode existir SEM conta na app: quando a recepção regista
 * alguém pelo BI físico, cria-se o cidadão com origem = 'balcao' e user_id = null.
 * Se mais tarde a pessoa instalar a app, liga-se a conta ao mesmo registo.
 *
 * Dados sensíveis (nº do documento, nomes dos pais) ficam ENCRIPTADOS
 * (cast 'encrypted' no modelo). Para pesquisar pelo nº do BI usa-se o
 * *_hash (HMAC-SHA256 do número normalizado), que é único.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cidadaos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();

            // Documento principal (normalmente o BI)
            $table->enum('tipo_documento', ['bi', 'passaporte', 'dire', 'carta_conducao', 'cartao_eleitor', 'outro'])->default('bi');
            $table->text('numero_documento')->comment('Encriptado');
            $table->char('numero_documento_hash', 64)->comment('HMAC para pesquisa');
            $table->string('numero_documento_mascarado', 20)->nullable()->comment('Ex.: 1101••••••56A, para listas');
            $table->string('documento_emitido_em')->nullable()->comment('Local de emissão');
            $table->date('documento_data_emissao')->nullable();
            $table->date('documento_data_validade')->nullable()->index();

            // Dados pessoais (como no BI)
            $table->string('nomes');
            $table->string('apelidos');
            $table->string('nome_completo')->index();
            $table->enum('sexo', ['F', 'M'])->nullable();
            $table->date('data_nascimento')->nullable();
            $table->string('naturalidade')->nullable();
            $table->string('nacionalidade', 60)->default('Moçambicana');
            $table->enum('estado_civil', ['solteiro', 'casado', 'uniao_facto', 'divorciado', 'viuvo'])->nullable();
            $table->unsignedSmallInteger('altura_cm')->nullable();
            $table->text('nome_pai')->nullable()->comment('Encriptado');
            $table->text('nome_mae')->nullable()->comment('Encriptado');

            // Contactos e morada
            $table->string('telefone', 20)->nullable()->index();
            $table->string('telefone_alternativo', 20)->nullable();
            $table->string('email')->nullable()->index();
            $table->string('morada')->nullable();
            $table->string('bairro', 100)->nullable();
            $table->foreignId('provincia_id')->nullable()->constrained('provincias')->nullOnDelete();
            $table->foreignId('distrito_id')->nullable()->constrained('distritos')->nullOnDelete();
            $table->string('profissao')->nullable();
            $table->string('entidade_empregadora')->nullable();

            // Imagens
            $table->foreignId('foto_ficheiro_id')->nullable()->constrained('ficheiros')->nullOnDelete();
            $table->foreignId('documento_frente_ficheiro_id')->nullable()->constrained('ficheiros')->nullOnDelete();
            $table->foreignId('documento_verso_ficheiro_id')->nullable()->constrained('ficheiros')->nullOnDelete();

            // Origem e verificação
            $table->enum('origem', ['app', 'balcao', 'importacao', 'api'])->default('app');
            $table->foreignId('registado_por')->nullable()->constrained('users')->nullOnDelete()->comment('Funcionário, no registo ao balcão');
            $table->foreignId('registado_em_instituicao_id')->nullable()->constrained('instituicoes')->nullOnDelete();
            $table->enum('nivel_verificacao', ['nenhum', 'contacto', 'documento', 'presencial', 'oficial'])->default('nenhum')
                ->comment('oficial = confirmado com a base de dados da identificação civil (futuro)');
            $table->timestamp('verificado_em')->nullable();
            $table->foreignId('verificado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('foto_verificada')->default(false);

            // Contacto de emergência (opcional)
            $table->string('emergencia_nome')->nullable();
            $table->string('emergencia_telefone', 20)->nullable();
            $table->string('emergencia_parentesco', 40)->nullable();

            // Acessibilidade (para a recepção poder ajudar)
            $table->boolean('precisa_assistencia')->default(false);
            $table->string('assistencia_nota')->nullable();

            $table->enum('estado', ['activo', 'suspenso', 'falecido', 'anonimizado'])->default('activo')->index();
            $table->timestamp('anonimizado_em')->nullable()->comment('Direito ao esquecimento');
            $table->json('extra')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tipo_documento', 'numero_documento_hash']);
        });

        // Outros documentos do cidadão (ecrã "Documentos" da app)
        Schema::create('documentos_cidadao', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('cidadao_id')->constrained('cidadaos')->cascadeOnDelete();
            $table->enum('tipo', ['bi', 'passaporte', 'dire', 'carta_conducao', 'cartao_eleitor', 'nuit', 'certidao', 'outro']);
            $table->string('designacao')->nullable()->comment('Nome livre quando tipo = outro');
            $table->text('numero')->nullable()->comment('Encriptado');
            $table->char('numero_hash', 64)->nullable()->index();
            $table->string('entidade_emissora')->nullable();
            $table->string('local_emissao')->nullable();
            $table->date('data_emissao')->nullable();
            $table->date('data_validade')->nullable()->index();
            $table->foreignId('frente_ficheiro_id')->nullable()->constrained('ficheiros')->nullOnDelete();
            $table->foreignId('verso_ficheiro_id')->nullable()->constrained('ficheiros')->nullOnDelete();
            $table->boolean('principal')->default(false);
            $table->boolean('partilhavel')->default(true)->comment('Se a recepção pode ver este documento com o código');
            $table->enum('estado_verificacao', ['pendente', 'verificado', 'rejeitado', 'expirado'])->default('pendente')->index();
            $table->foreignId('verificado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verificado_em')->nullable();
            $table->string('motivo_rejeicao')->nullable();
            $table->timestamp('aviso_validade_enviado_em')->nullable()->comment('SMS/e-mail "o seu documento expira em breve"');
            $table->timestamps();
            $table->softDeletes();
        });

        // Termos e políticas aceites (registo legal)
        Schema::create('versoes_termos', function (Blueprint $table) {
            $table->id();
            $table->enum('tipo', ['termos_uso', 'politica_privacidade', 'partilha_dados', 'termos_instituicao']);
            $table->string('versao', 20);
            $table->string('titulo');
            $table->longText('conteudo');
            $table->text('resumo_alteracoes')->nullable();
            $table->timestamp('publicado_em')->nullable();
            $table->boolean('em_vigor')->default(false);
            $table->boolean('exige_nova_aceitacao')->default(false);
            $table->timestamps();

            $table->unique(['tipo', 'versao']);
        });

        Schema::create('consentimentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cidadao_id')->nullable()->constrained('cidadaos')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('instituicao_id')->nullable()->constrained('instituicoes')->cascadeOnDelete()
                ->comment('Preenchido quando o consentimento é para uma instituição concreta');
            $table->foreignId('versao_termos_id')->nullable()->constrained('versoes_termos')->nullOnDelete();
            $table->enum('tipo', [
                'termos_uso', 'politica_privacidade', 'partilha_dados', 'guardar_foto',
                'comunicacoes_sms', 'comunicacoes_email', 'comunicacoes_push', 'marketing',
            ])->index();
            $table->boolean('aceite');
            $table->dateTime('decidido_em');
            $table->timestamp('revogado_em')->nullable();
            $table->enum('canal', ['app', 'portal', 'balcao', 'sms', 'email'])->default('app');
            $table->foreignId('recolhido_por')->nullable()->constrained('users')->nullOnDelete()->comment('Funcionário, se foi ao balcão');
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consentimentos');
        Schema::dropIfExists('versoes_termos');
        Schema::dropIfExists('documentos_cidadao');
        Schema::dropIfExists('cidadaos');
    }
};
