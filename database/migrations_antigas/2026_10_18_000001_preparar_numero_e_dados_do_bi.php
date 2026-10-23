<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Número único de cada utilizador (vai no QR code)
        if (! Schema::hasColumn('users', 'numero')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('numero', 8)->nullable()->unique()->after('uuid');
            });
        }

        // Quem já existia sem número ganha um
        foreach (DB::table('users')->whereNull('numero')->pluck('id') as $id) {
            do {
                $numero = (string) random_int(10000000, 99999999);
            } while (DB::table('users')->where('numero', $numero)->exists());

            DB::table('users')->where('id', $id)->update(['numero' => $numero]);
        }

        // 2) O cidadão nasce no registo, ainda sem BI: estes campos passam a ser opcionais
        //    (são preenchidos quando o BI é lido)
        Schema::table('cidadaos', function (Blueprint $table) {
            $table->text('numero_documento')->nullable()->change();
            $table->char('numero_documento_hash', 64)->nullable()->change();
            $table->string('nomes')->nullable()->change();
            $table->string('apelidos')->nullable()->change();
        });
    }

    public function down(): void
    {
        //
    }
};
