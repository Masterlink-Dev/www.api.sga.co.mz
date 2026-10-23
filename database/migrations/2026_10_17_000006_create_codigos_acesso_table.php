<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MeuAcesso · Código único / QR que o cidadão mostra na recepção
 *
 * - codigo   → os 5 dígitos que se dizem em voz alta (ex.: 48213).
 *              Só é único entre os códigos ACTIVOS, por isso tem prazo curto.
 * - qr_token → texto longo e aleatório lido pelo QR (não se adivinha).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('codigos_acesso', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cidadao_id')->constrained('cidadaos')->cascadeOnDelete();
            $table->string('codigo', 8)->comment('5 dígitos por omissão');
            $table->string('qr_token', 128)->unique();
            $table->enum('tipo', ['temporario', 'uso_unico', 'permanente'])->default('temporario');
            $table->foreignId('instituicao_id')->nullable()->constrained('instituicoes')->nullOnDelete()
                ->comment('Se preenchido, só funciona nesta instituição');
            $table->json('campos_partilhados')->nullable()->comment('Que dados a recepção pode ver: ["nome","foto","bi"...]');
            $table->dateTime('valido_desde')->useCurrent();
            $table->dateTime('valido_ate')->index();
            $table->unsignedSmallInteger('max_utilizacoes')->nullable()->comment('Null = sem limite durante a validade');
            $table->unsignedSmallInteger('utilizacoes')->default(0);
            $table->timestamp('ultima_utilizacao_em')->nullable();
            $table->enum('estado', ['activo', 'usado', 'expirado', 'revogado'])->default('activo');
            $table->timestamp('revogado_em')->nullable();
            $table->string('motivo_revogacao')->nullable()->comment('Ex.: "Não fui eu" na notificação');
            $table->foreignId('dispositivo_id')->nullable()->constrained('dispositivos')->nullOnDelete();
            $table->string('ip_geracao', 45)->nullable();
            $table->timestamps();

            $table->index(['codigo', 'estado']);
            $table->index(['cidadao_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('codigos_acesso');
    }
};
