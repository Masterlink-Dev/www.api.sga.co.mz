<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(DadosBaseSeeder::class);

        // Instituição e secretaria de teste, só em ambiente local
        if (app()->isLocal()) {
            $this->call(SecretariaDemoSeeder::class);
        }
    }
}
