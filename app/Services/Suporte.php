<?php

namespace App\Services;

use App\Models\Ficheiro;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Dados do ecrã "Suporte" da app: os contactos da empresa e as perguntas frequentes.
//   contactos()  → lidos de definicoes_sistema (grupo "suporte"); o super administrador muda-os no portal
//   perguntas()  → perguntas_frequentes, pela ordem escolhida
class Suporte
{
    // Campos de contacto: chave → [rótulo, máximo de caracteres, obrigatório]
    // Em definicoes_sistema ficam com a chave "suporte.<campo>" (ex.: suporte.telefone)
    public const CAMPOS = [
        'empresa'  => ['Empresa', 100, true],
        'telefone' => ['Telefone', 30, true],
        'whatsapp' => ['WhatsApp', 30, false],
        'email'    => ['Email', 150, false],
        'morada'   => ['Localização', 255, false],
        'horario'  => ['Horário de atendimento', 120, false],
        'site'     => ['Site', 150, false],
    ];

    // { empresa, telefone, whatsapp, email, morada, horario, site } (null quando ainda não há valor)
    public static function contactos(): array
    {
        $valores = DB::table('definicoes_sistema')
            ->where('grupo', 'suporte')
            ->pluck('valor', 'chave');

        $contactos = [];
        foreach (array_keys(self::CAMPOS) as $campo) {
            $valor = trim((string) ($valores["suporte.$campo"] ?? ''));
            $contactos[$campo] = $valor !== '' ? $valor : null;
        }

        return $contactos;
    }

    // Grava os campos recebidos (os que não vêm ficam como estão)
    public static function guardarContactos(array $dados): void
    {
        foreach (self::CAMPOS as $campo => [$rotulo]) {
            if (! array_key_exists($campo, $dados)) continue;

            $valor = trim((string) $dados[$campo]);
            DB::table('definicoes_sistema')->updateOrInsert(
                ['chave' => "suporte.$campo"],
                [
                    'valor'      => $valor !== '' ? $valor : null,
                    'tipo'       => 'texto',
                    'grupo'      => 'suporte',
                    'descricao'  => "Suporte · $rotulo",
                    'publica'    => true,
                    'updated_at' => now(),
                ]
            );
        }
    }

    // Termos e condições (PDF que o super administrador carrega; o novo substitui o anterior).
    // O id do ficheiro fica em definicoes_sistema com a chave "suporte.termos_ficheiro".
    // → { nome, tamanho_bytes, actualizado_em, url (link assinado, 30 min) } ou null se ainda não há
    public static function termos(): ?array
    {
        $ficheiro = self::ficheiroDosTermos();
        if (! $ficheiro) {
            return null;
        }

        return [
            'nome'           => $ficheiro->nome_original,
            'tamanho_bytes'  => (int) $ficheiro->tamanho_bytes,
            'actualizado_em' => $ficheiro->created_at?->toIso8601String(),
            'url'            => $ficheiro->url(),
        ];
    }

    // Guarda o PDF novo e apaga o anterior (disco e base de dados)
    public static function guardarTermos(UploadedFile $pdf, int $enviadoPor): void
    {
        $antigo = self::ficheiroDosTermos();

        $caminho = $pdf->store('plataforma/termos', 'local');
        $novo = Ficheiro::create([
            'categoria'     => 'outro',
            'disco'         => 'local',
            'caminho'       => $caminho,
            'nome_original' => $pdf->getClientOriginalName(),
            'mime'          => 'application/pdf',
            'tamanho_bytes' => $pdf->getSize(),
            'hash_sha256'   => hash_file('sha256', Storage::disk('local')->path($caminho)),
            'privado'       => false,
            'enviado_por'   => $enviadoPor,
        ]);

        DB::table('definicoes_sistema')->updateOrInsert(
            ['chave' => 'suporte.termos_ficheiro'],
            [
                'valor'      => (string) $novo->id,
                'tipo'       => 'numero',
                'grupo'      => 'suporte_ficheiros', // fora do grupo "suporte" para não se misturar com os contactos
                'descricao'  => 'Suporte · Termos e condições (id do ficheiro)',
                'publica'    => true,
                'updated_at' => now(),
            ]
        );

        if ($antigo) {
            Storage::disk($antigo->disco)->delete($antigo->caminho);
            $antigo->forceDelete();
        }
    }

    private static function ficheiroDosTermos(): ?Ficheiro
    {
        $id = DB::table('definicoes_sistema')->where('chave', 'suporte.termos_ficheiro')->value('valor');

        return $id ? Ficheiro::find((int) $id) : null;
    }

    // Perguntas frequentes pela ordem do super administrador.
    // $paraApp: só as activas e as que são para os cidadãos (publico = cidadao | todos)
    public static function perguntas(bool $paraApp = false): array
    {
        return DB::table('perguntas_frequentes')
            ->when($paraApp, fn ($q) => $q->where('activo', true)->whereIn('publico', ['cidadao', 'todos']))
            ->orderBy('ordem')
            ->orderBy('id')
            ->get()
            ->map(fn ($p) => $paraApp
                ? ['id' => $p->id, 'categoria' => $p->categoria, 'pergunta' => $p->pergunta, 'resposta' => $p->resposta]
                : [
                    'id'        => $p->id,
                    'categoria' => $p->categoria,
                    'pergunta'  => $p->pergunta,
                    'resposta'  => $p->resposta,
                    'publico'   => $p->publico,
                    'ordem'     => (int) $p->ordem,
                    'activo'    => (bool) $p->activo,
                ])
            ->values()
            ->all();
    }
}
