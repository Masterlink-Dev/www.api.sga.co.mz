<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MeuAcesso · Formulários dinâmicos (ecrã "Formulário de visita" do administrador)
 *
 * formularios        → um por instituição e por finalidade (hoje só "registo_visita")
 * campos_formulario  → os campos que o administrador cria: nome, tipo, obrigatório, visível, ordem
 * opcoes_campo       → opções das listas de escolha preenchidas à mão
 * respostas_visita   → o que a recepção preencheu em cada visita, campo a campo
 *
 * Tipos (o portal mostra o rótulo, a API grava o código):
 *   Texto curto → texto_curto · Texto longo → texto_longo · Lista de escolha → lista
 *   Telefone → telefone · BI (12 algarismos + 1 letra) → bi · Data → data · Número → numero
 *   (preparados para depois: escolha_multipla, email, hora, sim_nao)
 *
 * Campos ligados ao sistema (campo_sistema): a resposta vai para a coluna da visita com o mesmo
 * nome, para os relatórios e a pesquisa continuarem a funcionar (ex.: motivo → visitas.motivo_id).
 * As listas "Motivo" e "Sector" tiram as opções das tabelas motivos_visita e sectores (fonte_opcoes).
 *
 * Ao remover um campo usa-se soft delete e as respostas guardam uma cópia do nome e do tipo,
 * por isso as visitas antigas continuam a mostrar o que foi preenchido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formularios', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('instituicao_id')->constrained('instituicoes')->cascadeOnDelete();
            $table->enum('finalidade', ['registo_visita', 'registo_saida', 'agendamento', 'pedido_funcionario'])
                ->default('registo_visita');
            $table->string('nome', 120)->default('Registar entrada');
            $table->string('descricao')->nullable();
            $table->unsignedSmallInteger('versao')->default(1)->comment('Sobe sempre que um campo muda');
            $table->boolean('activo')->default(true);
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['instituicao_id', 'finalidade']);
        });

        Schema::create('campos_formulario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('formulario_id')->constrained('formularios')->cascadeOnDelete();
            $table->foreignId('instituicao_id')->constrained('instituicoes')->cascadeOnDelete()
                ->comment('Repetido do formulário para validar o dono do campo numa só consulta');

            $table->string('nome', 100)->comment('Rótulo que a recepção vê, ex.: Pessoa a visitar');
            $table->string('chave', 60)->comment('Identificador estável usado na API, ex.: pessoa_a_visitar');
            $table->enum('tipo', [
                'texto_curto', 'texto_longo', 'lista', 'telefone', 'bi', 'data', 'numero',
                'escolha_multipla', 'email', 'hora', 'sim_nao',
            ])->default('texto_curto');

            // Ligação ao resto do sistema
            $table->enum('campo_sistema', [
                'motivo', 'sector', 'pessoa_a_visitar', 'documento_numero', 'telefone_visitante',
                'observacoes', 'acompanhantes', 'viatura_matricula', 'cracha_numero', 'objectos_declarados',
            ])->nullable()->comment('Null = campo livre (resposta em respostas_visita)');
            $table->enum('fonte_opcoes', ['manual', 'motivos', 'sectores'])->nullable()
                ->comment('Só para listas: de onde vêm as opções');

            // Regras
            $table->boolean('obrigatorio')->default(false);
            $table->boolean('visivel')->default(true);
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->string('placeholder', 150)->nullable();
            $table->string('texto_ajuda')->nullable();
            $table->string('valor_padrao')->nullable();
            $table->json('validacao')->nullable()->comment('Ex.: {"min":3,"max":150,"regex":"^\\d{12}[A-Z]$"}');

            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['formulario_id', 'chave']);
            $table->unique(['formulario_id', 'campo_sistema']);
            $table->index(['formulario_id', 'visivel', 'ordem']);
        });

        // Opções das listas preenchidas à mão (fonte_opcoes = manual)
        Schema::create('opcoes_campo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campo_id')->constrained('campos_formulario')->cascadeOnDelete();
            $table->string('valor', 100);
            $table->string('rotulo', 150);
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['campo_id', 'valor']);
        });

        Schema::create('respostas_visita', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visita_id')->constrained('visitas')->cascadeOnDelete();
            $table->foreignId('campo_id')->nullable()->constrained('campos_formulario')->nullOnDelete();

            // Cópia do campo no momento da visita
            $table->string('campo_chave', 60);
            $table->string('campo_nome', 100);
            $table->string('campo_tipo', 30);
            $table->unsignedSmallInteger('formulario_versao')->nullable();

            // A resposta
            $table->text('valor')->nullable()->comment('Texto já formatado para mostrar');
            $table->foreignId('opcao_id')->nullable()->constrained('opcoes_campo')->nullOnDelete();
            $table->json('valor_json')->nullable()->comment('Escolha múltipla: ["a","b"]');
            $table->timestamps();

            $table->unique(['visita_id', 'campo_chave']);
            $table->index('campo_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('respostas_visita');
        Schema::dropIfExists('opcoes_campo');
        Schema::dropIfExists('campos_formulario');
        Schema::dropIfExists('formularios');
    }
};
