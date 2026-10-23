<?php

namespace App\Services;

use App\Support\Telefone;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// O formulário de visita que o administrador definiu, visto pela recepção:
//   campos()          → o que o portal desenha no Passo 2
//   validar()         → confere as respostas e separa-as entre colunas da visita e respostas livres
//   gravarRespostas() → grava as respostas livres em respostas_visita
class FormularioVisita
{
    // campo_sistema → coluna da tabela visitas
    private const COLUNAS = [
        'motivo'              => 'motivo_id',
        'sector'              => 'sector_id',
        'pessoa_a_visitar'    => 'pessoa_a_visitar',
        'observacoes'         => 'observacoes',
        'acompanhantes'       => 'acompanhantes',
        'viatura_matricula'   => 'viatura_matricula',
        'cracha_numero'       => 'cracha_numero',
        'objectos_declarados' => 'objectos_declarados',
    ];

    // Estes vêm da identificação (Passo 1), não do Passo 2
    public const DA_IDENTIFICACAO = ['documento_numero', 'telefone_visitante'];

    // Formulário ainda vazio: a recepção escolhe pelo menos o motivo e o sector
    private const POR_OMISSAO = [
        ['chave' => 'motivo', 'nome' => 'Motivo da visita', 'tipo' => 'lista', 'campo_sistema' => 'motivo', 'fonte_opcoes' => 'motivos', 'obrigatorio' => true],
        ['chave' => 'sector', 'nome' => 'Sector a visitar', 'tipo' => 'lista', 'campo_sistema' => 'sector', 'fonte_opcoes' => 'sectores', 'obrigatorio' => true],
    ];

    // Campos visíveis, pela ordem do administrador → { versao, padrao, campos: [...] }
    public static function campos(int $instituicaoId): array
    {
        $formulario = DB::table('formularios')->where('instituicao_id', $instituicaoId)
            ->where('finalidade', 'registo_visita')->whereNull('deleted_at')->first();

        $linhas = $formulario
            ? DB::table('campos_formulario')->where('formulario_id', $formulario->id)
                ->whereNull('deleted_at')->where('visivel', true)->orderBy('ordem')->orderBy('id')->get()
            : collect();

        if ($linhas->isEmpty()) {
            return [
                'versao' => $formulario?->versao,
                'padrao' => true, // o portal avisa que o formulário ainda não foi definido
                'campos' => array_map(fn ($c) => $c + ['id' => null, 'opcoes' => []], self::POR_OMISSAO),
            ];
        }

        $opcoes = DB::table('opcoes_campo')->whereIn('campo_id', $linhas->pluck('id'))
            ->where('activo', true)->orderBy('ordem')->get()->groupBy('campo_id');

        return [
            'versao' => $formulario->versao,
            'padrao' => false,
            'campos' => $linhas->map(fn ($c) => [
                'id'            => $c->id,
                'chave'         => $c->chave,
                'nome'          => $c->nome,
                'tipo'          => $c->tipo,           // texto_curto | texto_longo | lista | data | numero | bi | telefone
                'campo_sistema' => $c->campo_sistema,  // motivo, sector, pessoa_a_visitar... ou null
                'fonte_opcoes'  => $c->fonte_opcoes,   // motivos | sectores | manual | null
                'obrigatorio'   => (bool) $c->obrigatorio,
                'opcoes'        => ($opcoes[$c->id] ?? collect())
                    ->map(fn ($o) => ['id' => $o->id, 'valor' => $o->valor, 'rotulo' => $o->rotulo])->values()->all(),
            ])->all(),
        ];
    }

    /**
     * Confere as respostas do Passo 2 ({ chave: valor }) e devolve
     *   colunas → para a tabela visitas (motivo_id, sector_id, pessoa_a_visitar...)
     *   livres  → linhas para respostas_visita
     *   versao  → versão do formulário usada
     * $identificacao: o BI e o telefone que já vêm do Passo 1.
     * Se algo falhar: 422 com erros em "respostas.<chave>" (o portal mostra-os junto do campo).
     */
    public static function validar(int $instituicaoId, array $respostas, ?string $motivoOutro, array $identificacao): array
    {
        $form = self::campos($instituicaoId);
        $erros = [];
        $colunas = [];
        $livres = [];

        foreach ($form['campos'] as $c) {
            $sistema = $c['campo_sistema'];
            $chaveErro = 'respostas.' . $c['chave'];

            // BI e telefone: vêm do Passo 1. Se forem obrigatórios, a identificação tem de os ter.
            if (in_array($sistema, self::DA_IDENTIFICACAO, true)) {
                if ($c['obrigatorio'] && empty($identificacao[$sistema])) {
                    $erros[$chaveErro] = $sistema === 'documento_numero'
                        ? 'Este visitante ainda não tem o número do BI registado.'
                        : 'Este visitante ainda não tem o telemóvel registado.';
                }
                continue;
            }

            $valor = $respostas[$c['chave']] ?? null;
            $valor = is_string($valor) ? trim($valor) : $valor;

            if ($valor === null || $valor === '') {
                if ($c['obrigatorio']) {
                    $erros[$chaveErro] = "Preencha «{$c['nome']}».";
                }
                continue;
            }

            $r = self::conferir($c, $valor, $instituicaoId);
            if (isset($r['erro'])) {
                $erros[$chaveErro] = $r['erro'];
                continue;
            }

            if ($sistema && isset(self::COLUNAS[$sistema])) {
                $colunas[self::COLUNAS[$sistema]] = $r['valor'];
            } else {
                $livres[] = [
                    'campo_id'    => $c['id'],
                    'campo_chave' => $c['chave'],
                    'campo_nome'  => $c['nome'],
                    'campo_tipo'  => $c['tipo'],
                    'valor'       => $r['texto'],          // já formatado para mostrar (ex.: "Não", "23/10/2026")
                    'opcao_id'    => $r['opcao_id'] ?? null,
                ];
            }
        }

        // Motivo "Outro": é preciso escrever qual é
        if (! empty($colunas['motivo_id']) && DB::table('motivos_visita')->where('id', $colunas['motivo_id'])->value('e_outro')) {
            $texto = trim((string) $motivoOutro);
            if ($texto === '') {
                $erros['motivo_outro'] = 'Escreva qual é o motivo.';
            } else {
                $colunas['motivo_outro'] = mb_substr($texto, 0, 255);
            }
        }

        if ($erros) {
            throw ValidationException::withMessages($erros);
        }

        return ['colunas' => $colunas, 'livres' => $livres, 'versao' => $form['versao']];
    }

    public static function gravarRespostas(int $visitaId, array $livres, ?int $versao): void
    {
        $agora = now();
        foreach ($livres as $r) {
            DB::table('respostas_visita')->insert($r + [
                'visita_id'         => $visitaId,
                'formulario_versao' => $versao,
                'created_at'        => $agora,
                'updated_at'        => $agora,
            ]);
        }
    }

    // Confere um valor pelo tipo do campo → { valor (para gravar), texto (para mostrar), opcao_id? } ou { erro }
    private static function conferir(array $c, mixed $valor, int $instituicaoId): array
    {
        switch ($c['tipo']) {
            case 'lista':
                if ($c['fonte_opcoes'] === 'motivos') {
                    $m = DB::table('motivos_visita')->where('id', (int) $valor)
                        ->where(fn ($q) => $q->whereNull('instituicao_id')->orWhere('instituicao_id', $instituicaoId))
                        ->where('activo', true)->whereNull('deleted_at')->first();

                    return $m ? ['valor' => $m->id, 'texto' => $m->nome] : ['erro' => 'Escolha um motivo da lista.'];
                }
                if ($c['fonte_opcoes'] === 'sectores') {
                    $s = DB::table('sectores')->where('id', (int) $valor)->where('instituicao_id', $instituicaoId)
                        ->where('activo', true)->whereNull('deleted_at')->first();

                    return $s ? ['valor' => $s->id, 'texto' => $s->nome] : ['erro' => 'Escolha um sector da lista.'];
                }
                $o = collect($c['opcoes'])->firstWhere('valor', (string) $valor);

                return $o ? ['valor' => $o['valor'], 'texto' => $o['rotulo'], 'opcao_id' => $o['id']] : ['erro' => 'Escolha uma opção da lista.'];

            case 'data':
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $valor) || ! strtotime($valor)) {
                    return ['erro' => 'A data não é válida.'];
                }
                $d = Carbon::parse($valor);

                return ['valor' => $d->toDateString(), 'texto' => $d->format('d/m/Y')];

            case 'numero':
                return is_numeric($valor) ? ['valor' => $valor + 0, 'texto' => (string) ($valor + 0)] : ['erro' => 'Escreva um número.'];

            case 'telefone':
                $t = Telefone::normalizar((string) $valor);

                return $t ? ['valor' => $t, 'texto' => $t] : ['erro' => 'O telemóvel não é válido. Exemplo: 84 123 4567.'];

            case 'bi':
                $bi = strtoupper(preg_replace('/\s+/', '', (string) $valor));

                return preg_match('/^\d{12}[A-Z]$/', $bi) ? ['valor' => $bi, 'texto' => $bi] : ['erro' => 'O BI tem 12 algarismos seguidos de 1 letra.'];

            case 'texto_longo':
                return mb_strlen($valor) <= 2000 ? ['valor' => $valor, 'texto' => $valor] : ['erro' => 'O texto tem no máximo 2000 caracteres.'];

            default: // texto_curto
                return mb_strlen($valor) <= 255 ? ['valor' => $valor, 'texto' => $valor] : ['erro' => 'O texto tem no máximo 255 caracteres.'];
        }
    }
}
