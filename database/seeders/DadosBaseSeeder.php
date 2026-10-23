<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Dados de que a plataforma precisa para arrancar: províncias, tipos de instituição,
// perfis da equipa, o motivo "Outro" e a conta do super administrador.
// Pode correr mais de uma vez.
class DadosBaseSeeder extends Seeder
{
    public function run(): void
    {
        $agora = now();

        $provincias = [
            ['Maputo Cidade', 'MPC', 'Maputo'], ['Maputo Província', 'MPM', 'Matola'], ['Gaza', 'GZA', 'Xai-Xai'],
            ['Inhambane', 'INH', 'Inhambane'], ['Sofala', 'SFL', 'Beira'], ['Manica', 'MNC', 'Chimoio'],
            ['Tete', 'TTE', 'Tete'], ['Zambézia', 'ZMB', 'Quelimane'], ['Nampula', 'NPL', 'Nampula'],
            ['Cabo Delgado', 'CDG', 'Pemba'], ['Niassa', 'NSA', 'Lichinga'],
        ];
        foreach ($provincias as $i => [$nome, $codigo, $capital]) {
            DB::table('provincias')->updateOrInsert(
                ['nome' => $nome],
                ['codigo' => $codigo, 'capital' => $capital, 'ordem' => $i, 'created_at' => $agora, 'updated_at' => $agora]
            );
        }

        // Os mesmos tipos que o portal mostra no registo da instituição
        foreach (['Ministério', 'Direcção Provincial', 'Hospital', 'Conservatória', 'Município', 'Outro'] as $i => $nome) {
            DB::table('tipos_instituicao')->updateOrInsert(
                ['nome' => $nome],
                ['ordem' => $i, 'activo' => true, 'created_at' => $agora, 'updated_at' => $agora]
            );
        }

        // Perfis da plataforma (instituicao_id = null): as funções que o administrador escolhe ao registar a equipa
        $perfis = [
            ['secretaria', 'Secretária', 'Regista entradas e saídas.', 1],
            ['recepcionista', 'Recepcionista', 'Regista entradas e saídas e consulta as visitas do dia.', 1],
            ['funcionaria', 'Funcionária', 'Regista entradas e saídas.', 1],
            ['supervisor', 'Supervisora', 'Tudo o que a recepção faz, mais a ficha completa dos visitantes.', 2],
            ['gestor', 'Gestor', 'Gere sectores, motivos e regras de privacidade da instituição.', 3],
        ];
        foreach ($perfis as $i => [$slug, $nome, $descricao, $nivel]) {
            $existe = DB::table('perfis')->whereNull('instituicao_id')->where('slug', $slug)->exists();
            $valores = ['nome' => $nome, 'descricao' => $descricao, 'ambito' => 'plataforma', 'nivel' => $nivel,
                'de_sistema' => true, 'ordem' => $i, 'updated_at' => $agora];
            $existe
                ? DB::table('perfis')->whereNull('instituicao_id')->where('slug', $slug)->update($valores)
                : DB::table('perfis')->insert($valores + ['slug' => $slug, 'created_at' => $agora]);
        }

        // "Outro" existe em todas as instituições (fica sempre no fim da lista)
        if (! DB::table('motivos_visita')->whereNull('instituicao_id')->where('nome', 'Outro')->exists()) {
            DB::table('motivos_visita')->insert([
                'nome' => 'Outro', 'e_outro' => true, 'ordem' => 999, 'activo' => true,
                'created_at' => $agora, 'updated_at' => $agora,
            ]);
        }

        // Super administrador: email e senha vêm do .env (SUPERADMIN_EMAIL, SUPERADMIN_SENHA).
        // Sem SUPERADMIN_SENHA gera-se uma senha aleatória, mostrada uma só vez.
        $email = mb_strtolower(env('SUPERADMIN_EMAIL', 'super@meuacesso.co.mz'));
        if (! User::where('email', $email)->exists()) {
            $senha = env('SUPERADMIN_SENHA') ?: Str::password(14);
            User::create([
                'uuid' => (string) Str::uuid(), 'tipo' => User::TIPO_SUPER_ADMIN,
                'name' => 'Super administrador', 'email' => $email,
                'password' => $senha, 'estado' => 'activo', 'senha_alterada_em' => $agora,
            ]);
            $this->command?->info("Super administrador: {$email}" . (env('SUPERADMIN_SENHA') ? '' : "  |  Senha: {$senha}"));
        }
    }
}
