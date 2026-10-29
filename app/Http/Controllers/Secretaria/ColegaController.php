<?php

namespace App\Http\Controllers\Secretaria;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

// O funcionário propõe colegas. O pedido fica pendente e só dá acesso depois de o administrador o aprovar
// (/admin/pedidos). Cada funcionário só vê os pedidos que ele próprio fez.
class ColegaController extends Controller
{
    use ContextoSecretaria;

    // GET /secretaria/colegas → { data: [ { nome, email, cargo, estado, em } ] }
    public function index(Request $request): JsonResponse
    {
        $func = $this->funcionario($request);

        $lista = DB::table('pedidos_funcionario')
            ->where('instituicao_id', $func->instituicao_id)
            ->where('proposto_por', $request->user()->id)
            ->orderByDesc('created_at')
            ->get(['id', 'nome', 'email', 'cargo', 'estado', 'motivo_recusa', 'created_at'])
            ->map(fn ($p) => [
                'id'     => $p->id,
                'nome'   => $p->nome,
                'email'  => $p->email,
                'cargo'  => $p->cargo,
                'estado' => $p->estado, // pendente | aprovado | recusado | cancelado
                'motivo' => $p->motivo_recusa,
                'em'     => $p->created_at,
            ]);

        return response()->json(['data' => $lista]);
    }

    // GET /secretaria/equipa → os colegas activos da instituição (o funcionário vê, não altera)
    public function equipa(Request $request): JsonResponse
    {
        $func = $this->funcionario($request);
        $eu = $request->user()->id;

        $lista = DB::table('funcionarios as f')
            ->join('users as u', 'u.id', '=', 'f.user_id')
            ->where('f.instituicao_id', $func->instituicao_id)
            ->where('f.estado', 'activo')
            ->whereNull('f.deleted_at')->whereNull('u.deleted_at')
            ->orderBy('u.name')
            ->get(['u.id as user_id', 'u.uuid', 'u.name', 'u.email', 'f.cargo', 'u.ultimo_acesso_em'])
            ->map(fn ($l) => [
                'id'            => $l->uuid,
                'nome'          => $l->name,
                'email'         => $l->email,
                'perfil'        => $l->cargo ?? 'Funcionária',
                'ultimo_acesso' => $l->ultimo_acesso_em,
                'eu'            => (int) $l->user_id === (int) $eu,
            ]);

        return response()->json(['data' => $lista]);
    }

    // POST /secretaria/colegas  { nome, email, bi?, cargo?, justificacao? }
    public function store(Request $request): JsonResponse
    {
        $func = $this->funcionario($request);

        $request->merge([
            'nome'  => trim((string) $request->input('nome')),
            'email' => mb_strtolower(trim((string) $request->input('email'))),
            'bi'    => strtoupper(preg_replace('/\s+/', '', (string) $request->input('bi'))) ?: null,
        ]);
        $dados = $request->validate([
            'nome'         => ['required', 'string', 'min:3', 'max:150'],
            'email'        => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'bi'           => ['nullable', 'regex:/^\d{12}[A-Z]$/'],
            'cargo'        => ['nullable', 'string', 'max:60'],
            'justificacao' => ['nullable', 'string', 'max:1000'],
        ], [
            'nome.required'  => 'Escreva o nome completo do colega.',
            'email.required' => 'Escreva o email institucional do colega.',
            'email.email'    => 'Escreva um email válido.',
            'email.unique'   => 'Já existe uma conta com este email.',
            'bi.regex'       => 'O número do BI deve ter 12 algarismos seguidos de 1 letra.',
        ]);

        $repetido = DB::table('pedidos_funcionario')->where('instituicao_id', $func->instituicao_id)
            ->where('email', $dados['email'])->where('estado', 'pendente')->exists();
        if ($repetido) {
            return response()->json(['mensagem' => 'Já existe um pedido pendente para este email.'], 422);
        }

        $id = DB::table('pedidos_funcionario')->insertGetId([
            'uuid'             => (string) Str::uuid(),
            'instituicao_id'   => $func->instituicao_id,
            'proposto_por'     => $request->user()->id,
            'nome'             => $dados['nome'],
            'email'            => $dados['email'],
            'documento_numero' => $dados['bi'] ?? null,
            'cargo'            => $dados['cargo'] ?? 'Funcionária',
            'justificacao'     => $dados['justificacao'] ?? null,
            'estado'           => 'pendente',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return response()->json(['mensagem' => 'Pedido enviado ao administrador.', 'id' => $id, 'estado' => 'pendente'], 201);
    }
}
