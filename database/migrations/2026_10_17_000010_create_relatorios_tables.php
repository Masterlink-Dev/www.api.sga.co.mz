<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MeuAcesso · Relatórios gerados e estatísticas diárias
 *
 * estatisticas_diarias guarda os totais de cada dia. Assim, quando as visitas
 * antigas são apagadas (prazo de guarda), os gráficos de "Relatórios" continuam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relatorios_gerados', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('instituicao_id')->nullable()->constrained('instituicoes')->cascadeOnDelete()
                ->comment('Null = relatório da plataforma (admin)');
            $table->foreignId('unidade_id')->nullable()->constrained('unidades')->nullOnDelete();
            $table->enum('tipo', ['diario', 'semanal', 'mensal', 'trimestral', 'anual', 'personalizado', 'visitantes_frequentes', 'consultas_dados']);
            $table->string('titulo');
            $table->date('periodo_inicio');
            $table->date('periodo_fim');
            $table->enum('formato', ['pdf', 'xlsx', 'csv']);
            $table->json('parametros')->nullable()->comment('Filtros usados: sector, motivo...');
            $table->enum('estado', ['em_fila', 'a_gerar', 'pronto', 'falhou', 'expirado'])->default('em_fila')->index();
            $table->foreignId('ficheiro_id')->nullable()->constrained('ficheiros')->nullOnDelete();
            $table->text('erro')->nullable();
            $table->boolean('automatico')->default(false);
            $table->foreignId('pedido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('gerado_em')->nullable();
            $table->timestamp('enviado_por_email_em')->nullable();
            $table->unsignedInteger('downloads')->default(0);
            $table->timestamps();

            $table->index(['instituicao_id', 'tipo', 'periodo_inicio']);
        });

        Schema::create('estatisticas_diarias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instituicao_id')->constrained('instituicoes')->cascadeOnDelete();
            $table->foreignId('unidade_id')->nullable()->constrained('unidades')->cascadeOnDelete();
            $table->date('data');
            $table->unsignedInteger('total_visitas')->default(0);
            $table->unsignedInteger('visitantes_unicos')->default(0);
            $table->unsignedInteger('novos_visitantes')->default(0);
            $table->unsignedInteger('via_codigo')->default(0);
            $table->unsignedInteger('via_qr')->default(0);
            $table->unsignedInteger('via_manual')->default(0);
            $table->unsignedInteger('saidas_automaticas')->default(0);
            $table->unsignedInteger('tempo_medio_min')->nullable();
            $table->unsignedInteger('tempo_maximo_min')->nullable();
            $table->unsignedSmallInteger('pico_dentro')->nullable()->comment('Máximo de pessoas dentro ao mesmo tempo');
            $table->json('por_hora')->nullable()->comment('{"07":3,"08":8,...}');
            $table->json('por_sector')->nullable();
            $table->json('por_motivo')->nullable();
            $table->json('por_sexo')->nullable();
            $table->json('por_faixa_etaria')->nullable();
            $table->unsignedInteger('sms_enviados')->default(0);
            $table->unsignedInteger('emails_enviados')->default(0);
            $table->unsignedInteger('consultas_dados')->default(0);
            $table->timestamps();

            $table->unique(['instituicao_id', 'unidade_id', 'data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estatisticas_diarias');
        Schema::dropIfExists('relatorios_gerados');
    }
};
