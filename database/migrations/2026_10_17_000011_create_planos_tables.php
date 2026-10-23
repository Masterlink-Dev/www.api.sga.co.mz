<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MeuAcesso · Planos, subscrições e consumo (FASE FUTURA)
 * Ficam já criadas para não ser preciso mexer nas outras tabelas mais tarde.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planos', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 60)->unique();
            $table->string('descricao')->nullable();
            $table->decimal('preco_mensal', 12, 2)->default(0);
            $table->decimal('preco_anual', 12, 2)->nullable();
            $table->string('moeda', 3)->default('MZN');
            $table->unsignedInteger('limite_funcionarios')->nullable();
            $table->unsignedInteger('limite_unidades')->nullable();
            $table->unsignedInteger('limite_visitas_mes')->nullable();
            $table->unsignedInteger('sms_incluidos_mes')->default(0);
            $table->unsignedInteger('emails_incluidos_mes')->default(0);
            $table->decimal('preco_sms_extra', 10, 4)->nullable();
            $table->json('funcionalidades')->nullable()->comment('["agendamentos","relatorios_pdf","api"...]');
            $table->boolean('publico')->default(true);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('subscricoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instituicao_id')->constrained('instituicoes')->cascadeOnDelete();
            $table->foreignId('plano_id')->constrained('planos')->restrictOnDelete();
            $table->enum('ciclo', ['mensal', 'anual', 'contrato'])->default('contrato');
            $table->date('inicio');
            $table->date('fim')->nullable();
            $table->enum('estado', ['activa', 'em_atraso', 'suspensa', 'cancelada', 'terminada'])->default('activa')->index();
            $table->decimal('valor_acordado', 12, 2)->nullable();
            $table->string('numero_contrato', 40)->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
        });

        // Quanto cada instituição gastou por mês e canal (para quotas e facturação)
        Schema::create('consumos_mensais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instituicao_id')->constrained('instituicoes')->cascadeOnDelete();
            $table->char('mes', 7)->comment('AAAA-MM');
            $table->enum('canal', ['sms', 'email', 'push', 'whatsapp']);
            $table->unsignedInteger('quantidade')->default(0);
            $table->unsignedInteger('partes_sms')->default(0);
            $table->decimal('custo', 12, 4)->default(0);
            $table->string('moeda', 3)->default('MZN');
            $table->timestamps();

            $table->unique(['instituicao_id', 'mes', 'canal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumos_mensais');
        Schema::dropIfExists('subscricoes');
        Schema::dropIfExists('planos');
    }
};
