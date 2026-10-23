<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Quem tem número de outro tamanho (ou nenhum) ganha um novo de 4 dígitos
        $usados = DB::table('users')->whereRaw('LENGTH(numero) = 4')->pluck('numero')->all();

        $trocar = DB::table('users')
            ->whereNull('numero')
            ->orWhereRaw('LENGTH(numero) != 4')
            ->pluck('id');

        foreach ($trocar as $id) {
            do {
                $numero = (string) random_int(1000, 9999);
            } while (in_array($numero, $usados, true));

            $usados[] = $numero;

            DB::table('users')->where('id', $id)->update(['numero' => $numero]);
        }

        // Agora a coluna passa a ter só 4 caracteres
        Schema::table('users', function (Blueprint $table) {
            $table->string('numero', 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        //
    }
};
