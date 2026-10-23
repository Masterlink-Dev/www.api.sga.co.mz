<?php

namespace App\Http\Controllers\Secretaria;

use App\Http\Controllers\Controller;
use App\Models\Cidadao;
use App\Services\LeitorBI;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// A instituição vê QUEM passou por ela (pessoas, não visitas soltas).
//   GET /secretaria/visitantes          → lista de pessoas (filtros, páginas e resumo)
//   GET /secretaria/visitantes/{id}     → ficha: dados, números e histórico nesta instituição
//
// Só aparecem visitas DESTA instituição (a instituição vem sempre da conta de quem pede).
// "Quem é a mesma pessoa": o número do BI; se a visita não tem BI, a conta do cidadão.
class VisitanteController extends Controller
{
    use ContextoSecretaria;

    // A partir de quantas visitas alguém é "frequente"
    private const FREQUENTE = 5;

    // Identifica a pessoa numa visita
    private const CHAVE = "COALESCE(NULLIF(v.documento_numero, ''), CAST(v.cidadao_id AS CHAR))";

    // GET /secretaria/visitantes?q=ana&tipo=app&dentro=1&frequentes=1&ordem=recentes&de=2026-10-01&ate=2026-10-31&per_page=20
    public function index(Request $request): JsonResponse
    {
        $func = $this->funcionario($request);

        $f = $request->validate([
            'q'          => ['nullable', 'string', 'max:100'],
            'tipo'       => ['nullable', 'in:app,manual'],        // app = tem conta · manual = só BI ao balcão
            'dentro'     => ['nullable', 'boolean'],              // só quem está dentro agora
            'frequentes' => ['nullable', 'boolean'],
            'ordem'      => ['nullable', 'in:recentes,visitas,nome'],
            'de'         => ['nullable', 'date'],
            'ate'        => ['nullable', 'date'],
            'per_page'   => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $grupos = $this->agrupar($this->base($func));

        // Período: conta só as visitas desse intervalo
        if (! empty($f['de']))  $grupos->where('v.entrada_em', '>=', Carbon::parse($f['de'])->startOfDay());
        if (! empty($f['ate'])) $grupos->where('v.entrada_em', '<=', Carbon::parse($f['ate'])->endOfDay());

        // Procurar por nome, nº do BI ou código MeuAcesso
        if (! empty($f['q'])) {
            $q = $f['q'];
            $codigo = $this->soDigitos($q);
            $grupos->where(function ($w) use ($q, $codigo) {
                $w->where('v.nome_visitante', 'like', '%'.$q.'%')
                  ->orWhere('v.documento_numero', 'like', '%'.$q.'%');
                if ($codigo !== '') {
                    $w->orWhere('u.numero', $codigo);
                }
            });
        }

        // Filtros sobre a pessoa (depois de agrupar)
        if (($f['tipo'] ?? null) === 'app')    $grupos->havingRaw('MAX(CASE WHEN v.cidadao_id IS NOT NULL THEN 1 ELSE 0 END) = 1');
        if (($f['tipo'] ?? null) === 'manual') $grupos->havingRaw('MAX(CASE WHEN v.cidadao_id IS NOT NULL THEN 1 ELSE 0 END) = 0');
        if ($request->boolean('dentro'))       $grupos->havingRaw("MAX(CASE WHEN v.estado = 'em_curso' THEN 1 ELSE 0 END) = 1");
        if ($request->boolean('frequentes'))   $grupos->havingRaw('COUNT(*) >= ?', [self::FREQUENTE]);

        match ($f['ordem'] ?? 'recentes') {
            'visitas' => $grupos->orderByRaw('COUNT(*) DESC')->orderByRaw('MAX(v.entrada_em) DESC'),
            'nome'    => $grupos->orderByRaw('MAX(v.nome_visitante) ASC'),
            default   => $grupos->orderByRaw('MAX(v.entrada_em) DESC'),
        };

        $pagina = $grupos->paginate($f['per_page'] ?? 20);

        // Dados da visita mais recente de cada pessoa desta página (nome, BI, telefone, sector)
        $ultimas = $this->ultimasVisitas($func, $pagina->pluck('chave')->map(fn ($c) => (string) $c)->all());

        $itens = $pagina->getCollection()->map(function ($g) use ($ultimas) {
            $u = $ultimas[$g->chave] ?? null;
            $doc = $u?->documento_numero ?: null;

            return [
                'id'                  => $u?->uuid,                  // uuid da visita mais recente: serve para abrir a ficha
                'nome'                => $u?->nome_visitante,
                'numero'              => $g->numero,                 // código MeuAcesso (null se não tem conta)
                'documento'           => $doc,
                'documento_mascarado' => $doc ? LeitorBI::mascarar($doc) : null,
                'telefone'            => $u?->telefone_visitante,
                'usa_app'             => (bool) $g->usa_app,
                'dentro_agora'        => (bool) $g->dentro,
                'frequente'           => (int) $g->total >= self::FREQUENTE,
                'total_visitas'       => (int) $g->total,
                'primeira_visita'     => Carbon::parse($g->primeira)->toIso8601String(),
                'ultima_visita'       => Carbon::parse($g->ultima)->toIso8601String(),
                'ultimo_sector'       => $u?->sector,
            ];
        })->values();

        $saida = $pagina->toArray();
        $saida['data'] = $itens;
        $saida['resumo'] = $this->resumo($func);

        return response()->json($saida);
    }

    // GET /secretaria/visitantes/{id} — {id} é o "id" que a lista devolve (uuid de uma visita da pessoa)
    public function show(Request $request, string $uuid): JsonResponse
    {
        $func = $this->funcionario($request);

        $ref = DB::table('visitas')
            ->where('uuid', $uuid)->where('instituicao_id', $func->instituicao_id)->whereNull('deleted_at')
            ->first();

        if (! $ref) {
            return response()->json(['mensagem' => 'Visitante não encontrado.'], 404);
        }

        $doc = $ref->documento_numero !== null && $ref->documento_numero !== '' ? $ref->documento_numero : null;

        if (! $doc && ! $ref->cidadao_id) {
            return response()->json(['mensagem' => 'Esta visita não tem dados para identificar o visitante.'], 404);
        }

        // Todas as visitas desta pessoa nesta instituição
        $consulta = DB::table('visitas as v')
            ->leftJoin('sectores as s', 's.id', '=', 'v.sector_id')
            ->leftJoin('motivos_visita as m', 'm.id', '=', 'v.motivo_id')
            ->leftJoin('users as r', 'r.id', '=', 'v.entrada_registada_por')
            ->where('v.instituicao_id', $func->instituicao_id)
            ->whereNull('v.deleted_at');

        $doc
            ? $consulta->where('v.documento_numero', $doc)
            : $consulta->whereNull('v.documento_numero')->where('v.cidadao_id', $ref->cidadao_id);

        $visitas = $consulta->orderByDesc('v.entrada_em')->get([
            'v.uuid', 'v.cidadao_id', 'v.nome_visitante', 'v.telefone_visitante',
            'v.sexo_visitante', 'v.dados_documento', 'r.name as registado_por',
            's.nome as sector', 'm.nome as motivo', 'v.motivo_outro', 'v.pessoa_a_visitar',
            'v.entrada_em', 'v.saida_em', 'v.duracao_minutos', 'v.estado', 'v.metodo_identificacao as via',
        ]);

        $ultima = $visitas->first();
        $primeira = $visitas->last();
        $concluidas = $visitas->whereNotNull('duracao_minutos');

        // Conta na app (se alguma das visitas foi feita com o código)
        $cidadaoId = $visitas->pluck('cidadao_id')->filter()->first();
        $conta = $cidadaoId
            ? DB::table('cidadaos as c')->leftJoin('users as u', 'u.id', '=', 'c.user_id')
                ->where('c.id', $cidadaoId)
                ->first(['c.nivel_verificacao', 'c.email', 'c.telefone', 'u.numero',
                         'c.documento_frente_ficheiro_id', 'c.documento_verso_ficheiro_id'])
            : null;

        // Dados do documento: o que a conta tem; se faltar, o que a recepção escreveu à mão
        $cidadao = $cidadaoId ? Cidadao::with('foto')->find($cidadaoId) : null;
        $manual = collect($visitas)->map(fn ($v) => $v->dados_documento ? json_decode($v->dados_documento, true) : null)
            ->filter()->first() ?? [];
        $data = fn ($valor) => $valor ? Carbon::parse($valor)->toDateString() : null;
        $nascimento = $data($cidadao?->data_nascimento ?: ($manual['data_nascimento'] ?? null));

        $dados = [
            'data_nascimento' => $nascimento,
            'idade'           => $nascimento ? Carbon::parse($nascimento)->age : null,
            'sexo'            => $cidadao?->sexo ?: $visitas->pluck('sexo_visitante')->filter()->first(), // F | M
            'naturalidade'    => $cidadao?->naturalidade ?: ($manual['naturalidade'] ?? null),
            'nacionalidade'   => $cidadao?->nacionalidade ?: ($manual['nacionalidade'] ?? null),
            'local_emissao'   => $cidadao?->documento_emitido_em ?: ($manual['local_emissao'] ?? null),
            'data_emissao'    => $data($cidadao?->documento_data_emissao ?: ($manual['data_emissao'] ?? null)),
            'valido_ate'      => $data($cidadao?->documento_data_validade ?: ($manual['data_validade'] ?? null)),
            'morada'          => $cidadao?->morada ?: ($manual['morada'] ?? null),
        ];

        // Fica registado que esta instituição abriu a ficha (o cidadão vê isto na app)
        if ($cidadaoId) {
            DB::table('consultas_dados')->insert([
                'cidadao_id'      => $cidadaoId,
                'instituicao_id'  => $func->instituicao_id,
                'unidade_id'      => $func->unidade_id,
                'user_id'         => $request->user()->id,
                'tipo'            => 'ficha',
                'campos_acedidos' => json_encode(['nome', 'documento', 'historico_visitas']),
                'ip'              => $request->ip(),
                'user_agent'      => substr((string) $request->userAgent(), 0, 500),
            ]);
        }

        // Últimos 6 meses (incluindo o actual), do mais antigo para o mais recente
        $porMes = collect(range(5, 0))->map(function ($atras) use ($visitas) {
            $mes = now()->startOfMonth()->subMonths($atras)->format('Y-m');

            return [
                'mes'     => $mes,
                'visitas' => $visitas->filter(fn ($v) => Carbon::parse($v->entrada_em)->format('Y-m') === $mes)->count(),
            ];
        })->values();

        return response()->json([
            'visitante' => [
                'id'                  => $ultima->uuid,
                'nome'                => $ultima->nome_visitante,
                'numero'              => $conta->numero ?? null,
                'documento'           => $doc,
                'documento_mascarado' => $doc ? LeitorBI::mascarar($doc) : null,
                'telefone'            => ($conta->telefone ?? null) ?: $ultima->telefone_visitante,
                'email'               => $conta->email ?? null,
                'usa_app'             => (bool) $cidadaoId,
                'bi_estado'           => $conta ? $this->estadoBi($conta) : null,
                'foto'                => $cidadao?->foto?->url(), // link assinado, ou null
                'dados'               => $dados,
            ],
            'resumo' => [
                'total_visitas'    => $visitas->count(),
                'primeira_visita'  => Carbon::parse($primeira->entrada_em)->toIso8601String(),
                'ultima_visita'    => Carbon::parse($ultima->entrada_em)->toIso8601String(),
                'tempo_medio_min'  => $concluidas->count() ? (int) round($concluidas->avg('duracao_minutos')) : null,
                'sector_habitual'  => $visitas->pluck('sector')->filter()->countBy()->sortDesc()->keys()->first(),
                'dentro_agora'     => $visitas->firstWhere('estado', 'em_curso')?->uuid, // uuid da visita aberta, ou null
                'frequente'        => $visitas->count() >= self::FREQUENTE,
            ],
            'por_mes' => $porMes,
            'visitas' => $visitas->take(50)->map(fn ($v) => $this->formatar($v))->values(),
        ]);
    }

    // ---------- auxiliares ----------

    // Visitas desta instituição que se conseguem atribuir a uma pessoa
    private function base(object $func)
    {
        return DB::table('visitas as v')
            ->leftJoin('cidadaos as c', 'c.id', '=', 'v.cidadao_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->where('v.instituicao_id', $func->instituicao_id)
            ->whereNull('v.deleted_at')
            ->whereRaw(self::CHAVE.' IS NOT NULL');
    }

    // Uma linha por pessoa, com os totais das suas visitas
    private function agrupar($consulta)
    {
        return $consulta
            ->selectRaw(self::CHAVE.' as chave')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('MIN(v.entrada_em) as primeira')
            ->selectRaw('MAX(v.entrada_em) as ultima')
            ->selectRaw("MAX(CASE WHEN v.estado = 'em_curso' THEN 1 ELSE 0 END) as dentro")
            ->selectRaw('MAX(CASE WHEN v.cidadao_id IS NOT NULL THEN 1 ELSE 0 END) as usa_app')
            ->selectRaw('MAX(u.numero) as numero')
            ->groupBy('chave');
    }

    // Números de toda a instituição (não dependem dos filtros da lista)
    private function resumo(object $func): array
    {
        $r = DB::query()
            ->fromSub($this->agrupar($this->base($func)), 'g')
            ->selectRaw('COUNT(*) as visitantes')
            ->selectRaw('COALESCE(SUM(g.usa_app), 0) as usam_app')
            ->selectRaw('COALESCE(SUM(g.dentro), 0) as dentro_agora')
            ->selectRaw('COALESCE(SUM(CASE WHEN g.total >= ? THEN 1 ELSE 0 END), 0) as frequentes', [self::FREQUENTE])
            ->first();

        return [
            'visitantes'   => (int) $r->visitantes,
            'usam_app'     => (int) $r->usam_app,
            'sem_app'      => (int) $r->visitantes - (int) $r->usam_app,
            'dentro_agora' => (int) $r->dentro_agora,
            'frequentes'   => (int) $r->frequentes,
        ];
    }

    // A visita mais recente de cada pessoa da página, pela chave. Devolve [chave => visita]
    private function ultimasVisitas(object $func, array $chaves): array
    {
        if (! $chaves) {
            return [];
        }

        $marcas = implode(',', array_fill(0, count($chaves), '?'));

        $linhas = $this->base($func)
            ->leftJoin('sectores as s', 's.id', '=', 'v.sector_id')
            ->whereRaw(self::CHAVE." IN ($marcas)", $chaves)
            ->orderByDesc('v.entrada_em')
            ->get(['v.uuid', 'v.nome_visitante', 'v.documento_numero', 'v.telefone_visitante', 's.nome as sector',
                   DB::raw(self::CHAVE.' as chave')]);

        $ultimas = [];
        foreach ($linhas as $l) {
            $ultimas[$l->chave] ??= $l; // as mais recentes vêm primeiro
        }

        return $ultimas;
    }

    // Como está o BI de quem tem conta (igual ao que a recepção vê ao procurar o código)
    private function estadoBi(object $c): string
    {
        if (in_array($c->nivel_verificacao, ['documento', 'presencial', 'oficial'])) {
            return 'verificado';
        }

        return ($c->documento_frente_ficheiro_id && $c->documento_verso_ficheiro_id) ? 'submetido' : 'nao_submetido';
    }

    private function formatar(object $v): array
    {
        $entrada = Carbon::parse($v->entrada_em);
        $fim = $v->saida_em ? Carbon::parse($v->saida_em) : now();

        return [
            'id'               => $v->uuid,
            'sector'           => $v->sector,
            'motivo'           => $v->motivo ?: $v->motivo_outro,
            'pessoa_a_visitar' => $v->pessoa_a_visitar,
            'registado_por'    => $v->registado_por ?? null, // funcionário que registou a entrada
            'estado'           => $v->estado,
            'via'              => $v->via,
            'entrada_em'       => $entrada->toIso8601String(),
            'saida_em'         => $v->saida_em ? Carbon::parse($v->saida_em)->toIso8601String() : null,
            'minutos'          => $v->duracao_minutos ?? max(0, (int) round($entrada->diffInMinutes($fim))),
        ];
    }
}
