<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Dados de teste: 1 instituição, sectores, motivos e 1 secretaria.
// Pode correr mais de uma vez. NÃO mexe nos utilizadores que já existem.
class SecretariaDemoSeeder extends Seeder
{
    public function run(): void
    {
        $agora = now();

        // Instituição
        $inst = DB::table('instituicoes')->where('slug', 'ministerio-demo')->first();
        if (! $inst) {
            $id = DB::table('instituicoes')->insertGetId([
                'uuid' => (string) Str::uuid(), 'nome' => 'Ministério de Demonstração', 'sigla' => 'MDEMO',
                'slug' => 'ministerio-demo', 'estado' => 'activa', 'registada_em' => $agora,
                'created_at' => $agora, 'updated_at' => $agora,
            ]);
        } else {
            $id = $inst->id;
        }

        // Unidade (balcão)
        $unidade = DB::table('unidades')->where('instituicao_id', $id)->where('nome', 'Recepção Central')->first();
        if (! $unidade) {
            $unidadeId = DB::table('unidades')->insertGetId([
                'instituicao_id' => $id, 'nome' => 'Recepção Central',
                'principal' => true, 'activa' => true, 'created_at' => $agora, 'updated_at' => $agora,
            ]);
        } else {
            $unidadeId = $unidade->id;
        }

        // Sectores
        foreach (['Gabinete do Ministro', 'Direcção de Recursos Humanos', 'Contabilidade', 'Arquivo'] as $i => $nome) {
            DB::table('sectores')->updateOrInsert(
                ['instituicao_id' => $id, 'nome' => $nome],
                ['unidade_id' => $unidadeId, 'ordem' => $i, 'activo' => true, 'created_at' => $agora, 'updated_at' => $agora]
            );
        }

        // Motivos de visita
        foreach (['Reunião', 'Entrega de documentos', 'Pedido de informação', 'Outro'] as $i => $nome) {
            DB::table('motivos_visita')->updateOrInsert(
                ['instituicao_id' => $id, 'nome' => $nome],
                ['e_outro' => $nome === 'Outro', 'ordem' => $i, 'activo' => true, 'created_at' => $agora, 'updated_at' => $agora]
            );
        }

        // Perfil "Secretaria"
        DB::table('perfis')->updateOrInsert(
            ['instituicao_id' => $id, 'slug' => 'secretaria'],
            ['nome' => 'Secretaria', 'ambito' => 'instituicao', 'nivel' => 2, 'de_sistema' => true,
             'created_at' => $agora, 'updated_at' => $agora]
        );
        $perfilId = DB::table('perfis')->where('instituicao_id', $id)->where('slug', 'secretaria')->value('id');

        // Conta da secretaria (tipo 1)
        $user = User::where('email', 'secretaria@meuacesso.co.mz')->first();
        if (! $user) {
            $user = User::create([
                'uuid' => (string) Str::uuid(), 'numero' => User::gerarNumero(), 'tipo' => 1,
                'name' => 'Secretaria Demo', 'email' => 'secretaria@meuacesso.co.mz',
                'password' => 'Secretaria#2026', 'estado' => 'activo',
            ]);
        }

        DB::table('funcionarios')->updateOrInsert(
            ['user_id' => $user->id, 'instituicao_id' => $id],
            ['unidade_id' => $unidadeId, 'perfil_id' => $perfilId, 'cargo' => 'Secretária / Recepção',
             'estado' => 'activo', 'aprovado_em' => $agora, 'created_at' => $agora, 'updated_at' => $agora]
        );

        // Definições (privacidade e notificações) com os valores por omissão
        DB::table('definicoes_instituicao')->updateOrInsert(
            ['instituicao_id' => $id],
            ['created_at' => $agora, 'updated_at' => $agora]
        );

        // Formulário de visita com os mesmos campos de exemplo do portal
        $formularioId = DB::table('formularios')->where('instituicao_id', $id)->where('finalidade', 'registo_visita')->value('id')
            ?? DB::table('formularios')->insertGetId([
                'uuid' => (string) Str::uuid(), 'instituicao_id' => $id, 'finalidade' => 'registo_visita',
                'nome' => 'Registar entrada', 'activo' => true, 'created_at' => $agora, 'updated_at' => $agora,
            ]);

        $campos = [
            // chave, nome, tipo, campo_sistema, fonte_opcoes, obrigatório
            ['motivo', 'Motivo da visita', 'lista', 'motivo', 'motivos', true],
            ['sector', 'Sector a visitar', 'lista', 'sector', 'sectores', true],
            ['pessoa_a_visitar', 'Pessoa a visitar', 'texto_curto', 'pessoa_a_visitar', null, false],
            ['documento_numero', 'Número do BI', 'bi', 'documento_numero', null, true],
            ['telefone_visitante', 'Telemóvel do visitante', 'telefone', 'telefone_visitante', null, false],
        ];
        foreach ($campos as $i => [$chave, $nome, $tipo, $sistema, $fonte, $obrigatorio]) {
            DB::table('campos_formulario')->updateOrInsert(
                ['formulario_id' => $formularioId, 'chave' => $chave],
                ['instituicao_id' => $id, 'nome' => $nome, 'tipo' => $tipo, 'campo_sistema' => $sistema, 'fonte_opcoes' => $fonte,
                 'obrigatorio' => $obrigatorio, 'visivel' => true, 'ordem' => $i, 'created_at' => $agora, 'updated_at' => $agora]
            );
        }

        $this->command?->info('Pronto. Login: secretaria@meuacesso.co.mz  |  Senha: Secretaria#2026');
    }
}
