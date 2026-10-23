<?php

namespace App\Http\Controllers\Utilizador;

use App\Http\Controllers\Controller;
use App\Models\Cidadao;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// O cidadão vê ONDE JÁ PASSOU: as suas visitas a instituições.
//   GET /visitas                 → lista (com filtros e páginas) + resumo
//   GET /visitas/instituicoes    → os locais por onde passou (com quantas vezes)
//   GET /visitas/{uuid}          → uma visita em detalhe
class HistoricoController extends Controller
{
    // GET /visitas?de=2026-10-01&ate=2026-10-31&estado=concluida&instituicao_id=<uuid>&per_page=20&page=1
    public function index(Request $request): JsonResponse
    {
        $cidadao = $this->cidadao($request);

        $f = $request->validate([
            'de'             => ['nullable', 'date'],
            'ate'            => ['nullable', 'date'],
            'estado'         => ['nullable', 'in:em_curso,concluida,fechada_automaticamente,cancelada'],
            'instituicao_id' => ['nullable', 'uuid'],
            'per_page'       => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $consulta = $this->visitasDe($cidadao)->select($this->colunas())->orderByDesc('v.entrada_em');

        if (! empty($f['de']))             $consulta->where('v.entrada_em', '>=', Carbon::parse($f['de'])->startOfDay());
        if (! empty($f['ate']))            $consulta->where('v.entrada_em', '<=', Carbon::parse($f['ate'])->endOfDay());
        if (! empty($f['estado']))         $consulta->where('v.estado', $f['estado']);
        if (! empty($f['instituicao_id'])) $consulta->where('i.uuid', $f['instituicao_id']);

        $pagina = $consulta->paginate($f['per_page'] ?? 20);

        return response()->json([
            'visitas'   => $pagina->getCollection()->map(fn ($v) => $this->formatar($v))->values(),
            'paginacao' => [
                'pagina'        => $pagina->currentPage(),
                'por_pagina'    => $pagina->perPage(),
                'total'         => $pagina->total(),
                'ultima_pagina' => $pagina->lastPage(),
            ],
            'resumo'    => $this->resumo($cidadao),
        ]);
    }

    // GET /visitas/instituicoes — "onde passei": uma linha por instituição
    public function instituicoes(Request $request): JsonResponse
    {
        $cidadao = $this->cidadao($request);

        $locais = $this->visitasDe($cidadao)
            ->groupBy('i.id', 'i.uuid', 'i.nome', 'i.sigla')
            ->orderByRaw('MAX(v.entrada_em) DESC')
            ->get([
                'i.uuid', 'i.nome', 'i.sigla',
                DB::raw('COUNT(*) as visitas'),
                DB::raw('MIN(v.entrada_em) as primeira'),
                DB::raw('MAX(v.entrada_em) as ultima'),
            ])
            ->map(fn ($l) => [
                'id'             => $l->uuid,
                'nome'           => $l->nome,
                'sigla'          => $l->sigla,
                'visitas'        => (int) $l->visitas,
                'primeira_visita' => Carbon::parse($l->primeira)->toIso8601String(),
                'ultima_visita'   => Carbon::parse($l->ultima)->toIso8601String(),
            ]);

        return response()->json(['total' => $locais->count(), 'instituicoes' => $locais]);
    }

    // GET /visitas/{uuid} — só funciona para visitas do próprio cidadão
    public function show(Request $request, string $uuid): JsonResponse
    {
        $cidadao = $this->cidadao($request);

        $v = $this->visitasDe($cidadao)
            ->where('v.uuid', $uuid)
            ->select([...$this->colunas(), 'v.tipo_saida', 'v.avaliacao'])
            ->first();

        if (! $v) {
            return response()->json(['mensagem' => 'Visita não encontrada.'], 404);
        }

        return response()->json([
            'visita' => $this->formatar($v) + [
                'tipo_saida' => $v->tipo_saida, // manual | automatica | app
                'avaliacao'  => $v->avaliacao,
            ],
        ]);
    }

    // ---------- auxiliares ----------

    // Só as contas de utilizador (tipo 0) têm histórico de visitas
    private function cidadao(Request $request): Cidadao
    {
        $user = $request->user();

        if ((int) $user->tipo !== 0) {
            abort(response()->json(['mensagem' => 'Esta rota é para utilizadores da app.'], 403));
        }

        return $user->cidadaoOuCriar();
    }

    // As visitas deste cidadão. Inclui as registadas ao balcão com o número do seu BI
    // (por exemplo, antes de ele ter conta na app). Cada rota escolhe as colunas que precisa.
    private function visitasDe(Cidadao $cidadao)
    {
        $bi = $cidadao->numero_documento; // desencriptado pelo modelo

        return DB::table('visitas as v')
            ->join('instituicoes as i', 'i.id', '=', 'v.instituicao_id')
            ->leftJoin('sectores as s', 's.id', '=', 'v.sector_id')
            ->leftJoin('motivos_visita as m', 'm.id', '=', 'v.motivo_id')
            ->whereNull('v.deleted_at')
            ->where(function ($q) use ($cidadao, $bi) {
                $q->where('v.cidadao_id', $cidadao->id);
                if ($bi) {
                    $q->orWhere('v.documento_numero', $bi);
                }
            });
    }

    private function colunas(): array
    {
        return [
            'v.uuid', 'i.uuid as instituicao_uuid', 'i.nome as instituicao', 'i.sigla as instituicao_sigla',
            's.nome as sector', 'm.nome as motivo', 'v.motivo_outro', 'v.pessoa_a_visitar',
            'v.entrada_em', 'v.saida_em', 'v.duracao_minutos', 'v.estado',
            'v.metodo_identificacao as via',
        ];
    }

    // Números de todo o histórico (não dependem dos filtros)
    private function resumo(Cidadao $cidadao): array
    {
        $d = $this->visitasDe($cidadao)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COUNT(DISTINCT v.instituicao_id) as instituicoes')
            ->selectRaw("COALESCE(SUM(CASE WHEN v.estado = 'em_curso' THEN 1 ELSE 0 END), 0) as dentro_agora")
            ->selectRaw('MAX(v.entrada_em) as ultima')
            ->selectRaw('COALESCE(SUM(v.duracao_minutos), 0) as minutos')
            ->first();

        return [
            'total'         => (int) $d->total,
            'instituicoes'  => (int) $d->instituicoes,
            'dentro_agora'  => (int) $d->dentro_agora > 0,
            'ultima_visita' => $d->ultima ? Carbon::parse($d->ultima)->toIso8601String() : null,
            'minutos_total' => (int) $d->minutos,
        ];
    }

    private function formatar(object $v): array
    {
        $entrada = Carbon::parse($v->entrada_em);
        $fim = $v->saida_em ? Carbon::parse($v->saida_em) : now();

        return [
            'id'                => $v->uuid,
            'instituicao_id'    => $v->instituicao_uuid,
            'instituicao'       => $v->instituicao,
            'instituicao_sigla' => $v->instituicao_sigla,
            'sector'            => $v->sector,
            'motivo'            => $v->motivo ?: $v->motivo_outro,
            'pessoa_a_visitar'  => $v->pessoa_a_visitar,
            'entrada_em'        => $entrada->toIso8601String(),
            'saida_em'          => $v->saida_em ? Carbon::parse($v->saida_em)->toIso8601String() : null,
            'minutos'           => $v->duracao_minutos ?? max(0, (int) round($entrada->diffInMinutes($fim))),
            'estado'            => $v->estado,
            'via'               => $v->via,
        ];
    }
}
