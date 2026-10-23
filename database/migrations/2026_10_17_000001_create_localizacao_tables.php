<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MeuAcesso · Províncias e distritos de Moçambique
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provincias', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 60)->unique();
            $table->string('codigo', 10)->nullable()->unique()->comment('Ex.: MPM, GZA, NPL');
            $table->string('capital', 60)->nullable();
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();
        });

        Schema::create('distritos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provincia_id')->constrained('provincias')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('nome', 80);
            $table->string('codigo', 10)->nullable();
            $table->timestamps();

            $table->unique(['provincia_id', 'nome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distritos');
        Schema::dropIfExists('provincias');
    }
};
