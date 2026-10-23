<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Ficheiro;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Instituições registadas pelos administradores. O Super administrador só as consulta:
// não as cria nem as edita (isso é do administrador de cada uma).
class InstituicaoController extends Controller
{
    private const DIAS = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];

    // GET /superadmin/instituicoes?q=texto&estado=activa|suspensa → { data: [...] }
    public function index(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'q'      => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', 'in:activa,suspensa,encerrada'],
        ]);

        $inicioMes = now()->startOfMonth();

        $lista = DB::table('instituicoes as i')
            ->leftJoin('users as a', 'a.id', '=', 'i.administrador_id')
            ->leftJoin('tipos_instituicao as t', 't.id', '=', 'i.tipo_instituicao_id')
            ->leftJoin('provincias as p', 'p.id', '=', 'i.provincia_id')
            ->whereNull('i.deleted_at')
            ->when($dados['estado'] ?? null, fn ($q, $e) => $q->where('i.estado', $e))
            ->when(trim($dados['q'] ?? ''), fn ($q, $t) => $q->where(fn ($w) => $w
                ->where('i.nome', 'like', "%{$t}%")->orWhere('i.sigla', 'like', "%{$t}%")->orWhere('a.email', 'like', "%{$t}%")))
            ->orderByDesc('i.registada_em')
            ->select('i.*', 'a.email as admin_email', 't.nome as tipo', 'p.nome as provincia')
            ->selectSub(fn ($q) => $q->from('funcionarios')->whereColumn('instituicao_id', 'i.id')->whereNull('deleted_at')->where('estado', 'activo')->selectRaw('count(*)'), 'funcionarios')
            ->selectSub(fn ($q) => $q->from('visitas')->whereColumn('instituicao_id', 'i.id')->whereNull('deleted_at')->where('entrada_em', '>=', $inicioMes)->selectRaw('count(*)'), 'visitas_mes')
            ->get();

        // Horário de cada instituição (horário geral, sem unidade)
        $horarios = DB::table('horarios_atendimento')->whereIn('instituicao_id', $lista->pluck('id'))
            ->whereNull('unidade_id')->where('activo', true)->orderBy('dia_semana')->get()->groupBy('instituicao_id');

        return response()->json(['data' => $lista->map(function ($i) use ($horarios) {
            $h = $horarios[$i->id] ?? collect();

            return [
                'id'           => $i->uuid,
                'nome'         => $i->nome,
                'sigla'        => $i->sigla,
                'tipo'         => $i->tipo ?? 'Outro',
                'provincia'    => $i->provincia,
                'nuit'         => $i->nuit,
                'endereco'     => $i->endereco,
                'telefone'     => $i->telefone,
                'email'        => $i->email,
                'horario'      => $h->isNotEmpty()
                    ? $h->map(fn ($d) => self::DIAS[$d->dia_semana])->join(', ') . ' · ' . substr($h->first()->abre_as, 0, 5) . '–' . substr($h->first()->fecha_as, 0, 5)
                    : null,
                'admin_email'  => $i->admin_email,
                'funcionarios' => (int) $i->funcionarios,
                'visitas_mes'  => (int) $i->visitas_mes,
                'estado'       => $i->estado,
                'logo'         => $i->logo_ficheiro_id ? Ficheiro::find($i->logo_ficheiro_id)?->url() : null,
                'registada_em' => $i->registada_em,
            ];
        })]);
    }
}
