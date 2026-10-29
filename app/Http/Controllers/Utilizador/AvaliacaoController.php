<?php

namespace App\Http\Controllers\Utilizador;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// O cidadão avalia a app MeuAcesso e vê a resposta da equipa (super administrador).
//   POST /avaliacoes                → envia estrelas e comentário
//   GET  /avaliacoes/respostas      → respostas ainda visíveis (por ver, ou vistas há menos de 1 h)
//   POST /avaliacoes/{id}/vista     → marca a resposta como vista (a partir daí conta 1 h)
class AvaliacaoController extends Controller
{
    // Quanto tempo a resposta fica na app depois de ser vista
    public const HORAS_VISIVEL = 1;

    // POST /avaliacoes  { estrelas: 1-5, comentario?, plataforma?: android|ios|web, app_versao? }
    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'estrelas'   => ['required', 'integer', 'min:1', 'max:5'],
            'comentario' => ['nullable', 'string', 'max:1000'],
            'plataforma' => ['nullable', 'in:android,ios,web'],
            'app_versao' => ['nullable', 'string', 'max:20'],
        ], [
            'estrelas.required' => 'Escolha de 1 a 5 estrelas.',
            'estrelas.min'      => 'Escolha de 1 a 5 estrelas.',
            'estrelas.max'      => 'Escolha de 1 a 5 estrelas.',
            'comentario.max'    => 'O comentário pode ter no máximo 1000 caracteres.',
        ]);

        $comentario = trim((string) ($dados['comentario'] ?? ''));

        $id = DB::table('avaliacoes_app')->insertGetId([
            'user_id'    => $request->user()->id,
            'estrelas'   => $dados['estrelas'],
            'comentario' => $comentario !== '' ? $comentario : null,
            'plataforma' => $dados['plataforma'] ?? null,
            'app_versao' => $dados['app_versao'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['mensagem' => 'Obrigado pela sua avaliação.', 'id' => $id], 201);
    }

    // GET /avaliacoes/respostas → { respostas: [ { id, estrelas, comentario, resposta, respondido_em, vista_em, expira_em } ] }
    public function respostas(Request $request): JsonResponse
    {
        $limite = now()->subHours(self::HORAS_VISIVEL);

        $respostas = DB::table('avaliacoes_app')
            ->where('user_id', $request->user()->id)
            ->whereNotNull('resposta')
            ->where(fn ($q) => $q->whereNull('resposta_vista_em')->orWhere('resposta_vista_em', '>', $limite))
            ->orderByDesc('respondido_em')
            ->get()
            ->map(fn ($a) => [
                'id'            => $a->id,
                'estrelas'      => (int) $a->estrelas,
                'comentario'    => $a->comentario,
                'resposta'      => $a->resposta,
                'respondido_em' => $this->iso($a->respondido_em),
                'vista_em'      => $this->iso($a->resposta_vista_em),
                'expira_em'     => $a->resposta_vista_em
                    ? Carbon::parse($a->resposta_vista_em)->addHours(self::HORAS_VISIVEL)->toIso8601String()
                    : null,
            ]);

        return response()->json(['respostas' => $respostas]);
    }

    // POST /avaliacoes/{id}/vista → só marca a primeira vez (a 1 h conta desde aí)
    public function vista(Request $request, int $id): JsonResponse
    {
        $actualizadas = DB::table('avaliacoes_app')
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->whereNotNull('resposta')
            ->whereNull('resposta_vista_em')
            ->update(['resposta_vista_em' => now(), 'updated_at' => now()]);

        return response()->json(['mensagem' => $actualizadas ? 'Resposta vista.' : 'Sem alterações.']);
    }

    private function iso(?string $data): ?string
    {
        return $data ? Carbon::parse($data)->toIso8601String() : null;
    }
}
