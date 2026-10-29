<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Avaliações da app MeuAcesso (serviço da plataforma): o super administrador vê quem avaliou,
// a opinião, e responde uma vez a cada avaliação (a resposta aparece num toast na app).
class AvaliacaoController extends Controller
{
    // GET /superadmin/avaliacoes?estado=por_responder|respondidas&estrelas=1-5&q=texto&per_page=20
    //   → { data: [...], paginacao, resumo: { total, media, por_estrela: { 1..5 }, por_responder } }
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'estado'   => ['nullable', 'in:por_responder,respondidas'],
            'estrelas' => ['nullable', 'integer', 'min:1', 'max:5'],
            'q'        => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $consulta = DB::table('avaliacoes_app as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('users as r', 'r.id', '=', 'a.respondido_por')
            ->select('a.*', 'u.name as nome', 'u.email', 'u.numero', 'r.name as respondido_por_nome')
            ->orderByRaw('a.resposta IS NULL DESC') // primeiro as que faltam responder
            ->orderByDesc('a.created_at');

        if (($f['estado'] ?? null) === 'por_responder') $consulta->whereNull('a.resposta');
        if (($f['estado'] ?? null) === 'respondidas')   $consulta->whereNotNull('a.resposta');
        if (! empty($f['estrelas']))                    $consulta->where('a.estrelas', $f['estrelas']);
        if (! empty($f['q'])) {
            $q = $f['q'];
            $consulta->where(fn ($w) => $w->where('u.name', 'like', "%$q%")
                ->orWhere('u.email', 'like', "%$q%")
                ->orWhere('a.comentario', 'like', "%$q%"));
        }

        $pagina = $consulta->paginate($f['per_page'] ?? 20);

        return response()->json([
            'data'      => $pagina->getCollection()->map(fn ($a) => $this->formatar($a))->values(),
            'paginacao' => [
                'pagina'        => $pagina->currentPage(),
                'por_pagina'    => $pagina->perPage(),
                'total'         => $pagina->total(),
                'ultima_pagina' => $pagina->lastPage(),
            ],
            'resumo'    => $this->resumo(),
        ]);
    }

    // POST /superadmin/avaliacoes/{id}/responder  { resposta }  (só uma vez: 409 se já foi respondida)
    public function responder(Request $request, int $id): JsonResponse
    {
        $dados = $request->validate([
            'resposta' => ['required', 'string', 'min:2', 'max:500'],
        ], [
            'resposta.required' => 'Escreva a resposta.',
            'resposta.max'      => 'A resposta pode ter no máximo 500 caracteres.',
        ]);

        $avaliacao = DB::table('avaliacoes_app')->where('id', $id)->first();
        if (! $avaliacao) {
            return response()->json(['mensagem' => 'Avaliação não encontrada.'], 404);
        }
        if ($avaliacao->resposta !== null) {
            return response()->json(['mensagem' => 'Esta avaliação já foi respondida.'], 409);
        }

        $super = $request->user();
        DB::table('avaliacoes_app')->where('id', $id)->update([
            'resposta'       => trim($dados['resposta']),
            'respondido_por' => $super->id,
            'respondido_em'  => now(),
            'updated_at'     => now(),
        ]);

        DB::table('registos_auditoria')->insert([
            'user_id'       => $super->id,
            'accao'         => 'responder_avaliacao',
            'entidade_type' => 'avaliacoes_app',
            'entidade_id'   => $id,
            'descricao'     => mb_substr(trim($dados['resposta']), 0, 255),
            'ip'            => $request->ip(),
            'user_agent'    => $request->userAgent(),
            'url'           => $request->fullUrl(),
        ]);

        $actualizada = DB::table('avaliacoes_app as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('users as r', 'r.id', '=', 'a.respondido_por')
            ->where('a.id', $id)
            ->select('a.*', 'u.name as nome', 'u.email', 'u.numero', 'r.name as respondido_por_nome')
            ->first();

        return response()->json([
            'mensagem'  => 'Resposta enviada. O utilizador vai vê-la na app.',
            'avaliacao' => $this->formatar($actualizada),
            'resumo'    => $this->resumo(),
        ]);
    }

    private function resumo(): array
    {
        $porEstrela = DB::table('avaliacoes_app')
            ->selectRaw('estrelas, COUNT(*) as total')
            ->groupBy('estrelas')
            ->pluck('total', 'estrelas');

        $total = (int) $porEstrela->sum();

        return [
            'total'         => $total,
            'media'         => $total ? round(DB::table('avaliacoes_app')->avg('estrelas'), 1) : null,
            'por_estrela'   => collect(range(1, 5))->mapWithKeys(fn ($e) => [$e => (int) ($porEstrela[$e] ?? 0)]),
            'por_responder' => DB::table('avaliacoes_app')->whereNull('resposta')->count(),
        ];
    }

    private function formatar(object $a): array
    {
        return [
            'id'             => $a->id,
            'nome'           => $a->nome ?? 'Conta removida',
            'email'          => $a->email ?? null,
            'codigo'         => $a->numero ?? null,
            'estrelas'       => (int) $a->estrelas,
            'comentario'     => $a->comentario,
            'plataforma'     => $a->plataforma,
            'app_versao'     => $a->app_versao,
            'criado_em'      => Carbon::parse($a->created_at)->toIso8601String(),
            'resposta'       => $a->resposta,
            'respondido_em'  => $a->respondido_em ? Carbon::parse($a->respondido_em)->toIso8601String() : null,
            'respondido_por' => $a->respondido_por_nome ?? null,
            'vista_em'       => $a->resposta_vista_em ? Carbon::parse($a->resposta_vista_em)->toIso8601String() : null,
        ];
    }
}
