<?php

use App\Support\CodigoMeuAcesso;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// O código MeuAcesso passa de 4 dígitos para 6 caracteres: 5 algarismos + 1 letra (ex.: 2A7341)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('numero', 6)->nullable()->comment('Código MeuAcesso: 5 algarismos + 1 letra (vai no QR code)')->change();
        });

        // Quem já tinha código (4 dígitos) ganha um novo no formato novo
        $usados = DB::table('users')->whereRaw('LENGTH(numero) = 6')->pluck('numero')->flip()->all();

        $trocar = DB::table('users')
            ->whereNotNull('numero')
            ->whereRaw('LENGTH(numero) != 6')
            ->pluck('id');

        foreach ($trocar as $id) {
            $codigo = CodigoMeuAcesso::gerar(fn (string $c) => isset($usados[$c]));
            $usados[$codigo] = true;

            DB::table('users')->where('id', $id)->update(['numero' => $codigo]);
        }
    }

    public function down(): void
    {
        //
    }
};
