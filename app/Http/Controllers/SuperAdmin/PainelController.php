<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Ficheiro;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Números da plataforma para o Super administrador (painel, utilização e relatórios).
// Tudo calculado a partir da base de dados.
class PainelController extends Controller
{
    private const MESES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

    // GET /superadmin/painel
    public function index(Request $request): JsonResponse
    {
        $inicioMes = now()->startOfMonth();
        $inicioMesAnterior = now()->subMonthNoOverflow()->startOfMonth();

        $admins = DB::table('users as u')
            ->leftJoin('instituicoes as i', fn ($j) => $j->on('i.administrador_id', '=', 'u.id')->whereNull('i.deleted_at'))
            ->where('u.tipo', User::TIPO_ADMIN)->whereNull('u.deleted_at')
            ->get(['u.deve_alterar_senha', 'i.id as instituicao_id']);

        $visitasMes = $this->visitas()->where('entrada_em', '>=', $inicioMes)->count();
        $visitasAnterior = $this->visitas()->whereBetween('entrada_em', [$inicioMesAnterior, $inicioMes])->count();

        return response()->json([
            'instituicoes'         => $this->instituicoes()->count(),
            'administradores'      => $admins->count(),
            'senhas_por_alterar'   => $admins->where('deve_alterar_senha', true)->count(),
            'por_registar'         => $admins->where('deve_alterar_senha', false)->whereNull('instituicao_id')->count(),
            'funcionarios'         => $this->funcionariosActivos()->count(),
            'visitas_mes'          => $visitasMes,
            'visitas_mes_anterior' => $visitasAnterior,
            'variacao_mes'         => $visitasAnterior ? (int) round(($visitasMes - $visitasAnterior) / $visitasAnterior * 100) : null,
            'visitas_por_mes'      => $this->visitasPorMes(6),
            'por_tipo'             => $this->instituicoes()
                ->leftJoin('tipos_instituicao as t', 't.id', '=', 'instituicoes.tipo_instituicao_id')
                ->selectRaw("COALESCE(t.nome, 'Outro') as rotulo, count(*) as valor")
                ->groupBy('rotulo')->orderByDesc('valor')->get()
                ->map(fn ($l) => ['rotulo' => $l->rotulo, 'valor' => (int) $l->valor]),
            'ultimas'              => $this->instituicoes()
                ->leftJoin('users as a', 'a.id', '=', 'instituicoes.administrador_id')
                ->leftJoin('tipos_instituicao as t', 't.id', '=', 'instituicoes.tipo_instituicao_id')
                ->orderByDesc('instituicoes.registada_em')->limit(3)
                ->get(['instituicoes.*', 'a.email as admin_email', 't.nome as tipo'])
                ->map(fn ($i) => $this->resumoInstituicao($i)),
            'utilizacao'           => $this->contagemUtilizacao(),
        ]);
    }

    // GET /superadmin/utilizacao — quem usa a app móvel (cidadãos) e o portal web (administradores e funcionários)
    public function utilizacao(Request $request): JsonResponse
    {
        $meses = $this->ultimosMeses(6);

        // Contas existentes no fim de cada mês (crescimento acumulado)
        $porMes = $meses->map(function (Carbon $m) {
            $fim = $m->copy()->endOfMonth();

            return [
                'mes'    => self::MESES[$m->month - 1],
                'mobile' => DB::table('users')->where('tipo', User::TIPO_UTILIZADOR)->whereNull('deleted_at')->where('created_at', '<=', $fim)->count(),
                'web'    => DB::table('users')->whereIn('tipo', [User::TIPO_FUNCIONARIO, User::TIPO_ADMIN])->whereNull('deleted_at')->where('created_at', '<=', $fim)->count(),
            ];
        })->values();

        // Por instituição: web = equipa activa + o administrador · mobile = cidadãos com conta que lá foram
        $porInstituicao = $this->instituicoes()
            ->leftJoin('tipos_instituicao as t', 't.id', '=', 'instituicoes.tipo_instituicao_id')
            ->orderBy('instituicoes.nome')
            ->get(['instituicoes.*', 't.nome as tipo'])
            ->map(fn ($i) => $this->resumoInstituicao($i) + [
                'web'    => DB::table('funcionarios')->where('instituicao_id', $i->id)->whereNull('deleted_at')->where('estado', 'activo')->count() + ($i->administrador_id ? 1 : 0),
                'mobile' => DB::table('visitas as v')->join('cidadaos as c', 'c.id', '=', 'v.cidadao_id')
                    ->where('v.instituicao_id', $i->id)->whereNull('v.deleted_at')->whereNotNull('c.user_id')
                    ->distinct()->count('c.id'),
            ])
            ->sortByDesc(fn ($i) => $i['mobile'] + $i['web'])->values();

        return response()->json($this->contagemUtilizacao() + ['por_mes' => $porMes, 'por_instituicao' => $porInstituicao]);
    }

    // GET /superadmin/relatorios?periodo=6m|ano|trimestre&tipo=Ministério&instituicao=MISAU
    public function relatorios(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'periodo'     => ['nullable', 'in:6m,ano,trimestre'],
            'tipo'        => ['nullable', 'string', 'max:80'],
            'instituicao' => ['nullable', 'string', 'max:20'], // sigla
            'formato'     => ['nullable', 'in:json,pdf,xlsx'],
        ]);

        if (in_array($dados['formato'] ?? 'json', ['pdf', 'xlsx'], true)) {
            return response()->json(['codigo' => 'POR_IMPLEMENTAR', 'mensagem' => 'A exportação em PDF e Excel ainda não está disponível.'], 501);
        }

        [$inicio, $meses] = match ($dados['periodo'] ?? '6m') {
            'ano'       => [now()->startOfYear(), now()->month],
            'trimestre' => [now()->subMonthsNoOverflow(2)->startOfMonth(), 3],
            default     => [now()->subMonthsNoOverflow(5)->startOfMonth(), 6],
        };

        // Instituições abrangidas pelos filtros
        $instituicoes = $this->instituicoes()
            ->leftJoin('tipos_instituicao as t', 't.id', '=', 'instituicoes.tipo_instituicao_id')
            ->when($dados['tipo'] ?? null, fn ($q, $tipo) => $q->where('t.nome', $tipo))
            ->when($dados['instituicao'] ?? null, fn ($q, $sigla) => $q->where('instituicoes.sigla', $sigla))
            ->get(['instituicoes.*', 't.nome as tipo']);
        $ids = $instituicoes->pluck('id');

        $visitas = fn () => $this->visitas()->whereIn('instituicao_id', $ids)->where('entrada_em', '>=', $inicio);
        $porInst = $visitas()->selectRaw('instituicao_id, count(*) as total')->groupBy('instituicao_id')->pluck('total', 'instituicao_id');
        $dias = max(1, (int) $inicio->diffInDays(now()) + 1);

        $linhas = $instituicoes->map(fn ($i) => $this->resumoInstituicao($i) + [
            'funcionarios' => DB::table('funcionarios')->where('instituicao_id', $i->id)->whereNull('deleted_at')->where('estado', 'activo')->count(),
            'visitas'      => (int) ($porInst[$i->id] ?? 0),
            'media_dia'    => (int) round(($porInst[$i->id] ?? 0) / $dias),
        ])->sortByDesc('visitas')->values();

        $porMes = $this->visitasPorMes($meses, $ids);
        $n = count($porMes['valores']);
        $ultimo = $porMes['valores'][$n - 1] ?? 0;
        $penultimo = $porMes['valores'][$n - 2] ?? 0;

        return response()->json([
            'periodo' => ['inicio' => $inicio->toDateString(), 'fim' => now()->toDateString()],
            'kpis'    => [
                'visitas'             => $visitas()->count(),
                'instituicoes_activas' => $instituicoes->where('estado', 'activa')->count(),
                'sem_visitas'         => $linhas->where('visitas', 0)->count(),
                'crescimento_mensal'  => $penultimo ? (int) round(($ultimo - $penultimo) / $penultimo * 100) : null,
            ],
            'por_mes'          => $porMes,
            'top'              => $linhas->take(5)->map(fn ($l) => ['rotulo' => $l['sigla'], 'valor' => $l['visitas']])->values(),
            'por_instituicao'  => $linhas,
            // Para os filtros do ecrã
            'tipos'            => DB::table('tipos_instituicao')->where('activo', true)->orderBy('ordem')->pluck('nome'),
            'siglas'           => $this->instituicoes()->orderBy('sigla')->pluck('sigla'),
        ]);
    }

    private function instituicoes()
    {
        return DB::table('instituicoes')->whereNull('instituicoes.deleted_at');
    }

    private function visitas()
    {
        return DB::table('visitas')->whereNull('deleted_at');
    }

    private function funcionariosActivos()
    {
        return DB::table('funcionarios as f')->join('instituicoes as i', 'i.id', '=', 'f.instituicao_id')
            ->whereNull('f.deleted_at')->whereNull('i.deleted_at')->where('f.estado', 'activo');
    }

    // Cidadãos com a app (mobile) e contas do portal (web: administradores + funcionários)
    private function contagemUtilizacao(): array
    {
        return [
            'mobile' => DB::table('users')->where('tipo', User::TIPO_UTILIZADOR)->whereNull('deleted_at')->count(),
            'web'    => DB::table('users')->whereIn('tipo', [User::TIPO_FUNCIONARIO, User::TIPO_ADMIN])->whereNull('deleted_at')->count(),
        ];
    }

    // Os últimos N meses (o actual incluído), do mais antigo para o mais recente
    private function ultimosMeses(int $n)
    {
        return collect(range($n - 1, 0))->map(fn ($k) => now()->startOfMonth()->subMonthsNoOverflow($k));
    }

    // { meses: ['mai', ...], valores: [980, ...] }
    private function visitasPorMes(int $n, $instituicoes = null): array
    {
        $meses = $this->ultimosMeses($n);
        $contagem = $this->visitas()
            ->when($instituicoes !== null, fn ($q) => $q->whereIn('instituicao_id', $instituicoes))
            ->where('entrada_em', '>=', $meses->first())
            ->selectRaw('YEAR(entrada_em) as a, MONTH(entrada_em) as m, count(*) as total')
            ->groupBy('a', 'm')->get()
            ->mapWithKeys(fn ($l) => ["{$l->a}-{$l->m}" => (int) $l->total]);

        return [
            'meses'   => $meses->map(fn ($m) => self::MESES[$m->month - 1])->values(),
            'valores' => $meses->map(fn ($m) => $contagem["{$m->year}-{$m->month}"] ?? 0)->values(),
        ];
    }

    private function resumoInstituicao(object $i): array
    {
        return [
            'id'           => $i->uuid,
            'nome'         => $i->nome,
            'sigla'        => $i->sigla,
            'tipo'         => $i->tipo ?? 'Outro',
            'admin_email'  => $i->admin_email ?? null,
            'estado'       => $i->estado,
            'registada_em' => $i->registada_em,
            'logo'         => $i->logo_ficheiro_id ? Ficheiro::find($i->logo_ficheiro_id)?->url() : null,
        ];
    }
}
