<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Credenciais;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// A equipa da instituição: as secretárias / funcionárias que registam as entradas e saídas.
// O administrador regista-as (ficam logo activas, com a senha inicial e um email com as credenciais)
// e pode suspendê-las ou reactivá-las.
class EquipaController extends Controller
{
    use ContextoAdmin;

    // Funções que o portal oferece → perfil da plataforma (perfis.slug)
    public const FUNCOES = ['Secretária' => 'secretaria', 'Recepcionista' => 'recepcionista', 'Funcionária' => 'funcionaria'];

    // GET /admin/equipa?estado=activo|suspenso → { data: [...], funcoes: [...] }
    public function index(Request $request): JsonResponse
    {
        $inst = $this->instituicao($request);
        $estado = $request->query('estado');

        $lista = $this->consulta($inst->id)
            ->when(in_array($estado, ['activo', 'suspenso'], true), fn ($q) => $q->where('f.estado', $estado))
            ->get()->map(fn ($l) => $this->formatar($l));

        return response()->json(['data' => $lista, 'funcoes' => array_keys(self::FUNCOES)]);
    }

    // POST /admin/equipa  { nome, email, perfil: 'Secretária' | 'Recepcionista' | 'Funcionária' }
    public function store(Request $request): JsonResponse
    {
        $inst = $this->instituicao($request);

        $request->merge([
            'nome'  => trim((string) $request->input('nome')),
            'email' => mb_strtolower(trim((string) $request->input('email'))),
        ]);
        $dados = $request->validate([
            'nome'   => ['required', 'string', 'min:3', 'max:150'],
            'email'  => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'perfil' => ['nullable', Rule::in(array_keys(self::FUNCOES))],
        ], [
            'nome.required' => 'Escreva o nome completo.',
            'nome.min'      => 'O nome é demasiado curto.',
            'email.required' => 'Escreva o email institucional.',
            'email.email'   => 'Escreva um email válido.',
            'email.unique'  => 'Já existe uma conta com este email.',
            'perfil.in'     => 'Escolha uma função da lista.',
        ]);

        $admin = $request->user();
        $funcao = $dados['perfil'] ?? 'Secretária';

        [$user, $senha] = DB::transaction(function () use ($dados, $admin, $inst, $funcao) {
            [$user, $senha] = Credenciais::criarConta($dados['email'], User::TIPO_FUNCIONARIO, $admin, $dados['nome']);
            $this->ligarAInstituicao($user, $inst->id, $funcao, $admin, 'administrador');

            return [$user, $senha];
        });

        $enviado = Credenciais::enviar($user, $admin, $senha);
        $this->auditar($request, 'registar_funcionario', $inst->id, User::class, $user->id, $user->email);

        return response()->json([
            'mensagem'      => $enviado
                ? 'Funcionário registado. As credenciais foram enviadas por email.'
                : 'Funcionário registado, mas não foi possível enviar o email. Indique-lhe a senha inicial.',
            'email_enviado' => $enviado,
            'senha_gerada'  => Credenciais::paraTeste($senha), // só com MOSTRAR_SENHA_GERADA=true
            'pessoa'        => $this->formatar($this->consulta($inst->id)->where('u.id', $user->id)->first()),
        ], 201);
    }

    // PUT /admin/equipa/{uuid}  { nome?, perfil?, estado?: activo | suspenso }
    public function update(Request $request, string $uuid): JsonResponse
    {
        $inst = $this->instituicao($request);
        $linha = $this->consulta($inst->id)->where('u.uuid', $uuid)->first();
        if (! $linha) {
            return response()->json(['mensagem' => 'Funcionário não encontrado.'], 404);
        }

        $dados = $request->validate([
            'nome'   => ['sometimes', 'required', 'string', 'min:3', 'max:150'],
            'perfil' => ['sometimes', Rule::in(array_keys(self::FUNCOES))],
            'estado' => ['sometimes', Rule::in(['activo', 'suspenso'])],
        ], ['estado.in' => 'O estado tem de ser activo ou suspenso.']);

        DB::transaction(function () use ($dados, $linha) {
            if (isset($dados['nome'])) {
                DB::table('users')->where('id', $linha->user_id)->update(['name' => trim($dados['nome']), 'updated_at' => now()]);
            }
            $f = ['updated_at' => now()];
            if (isset($dados['perfil'])) {
                $f['cargo'] = $dados['perfil'];
                $f['perfil_id'] = $this->perfilId($dados['perfil']);
            }
            if (isset($dados['estado'])) {
                $f['estado'] = $dados['estado'];
            }
            DB::table('funcionarios')->where('id', $linha->funcionario_id)->update($f);

            // Suspenso: as sessões abertas terminam já
            if (($dados['estado'] ?? null) === 'suspenso') {
                DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $linha->user_id)->delete();
            }
        });

        $this->auditar($request, 'actualizar_funcionario', $inst->id, User::class, $linha->user_id, $linha->email);

        return response()->json([
            'mensagem' => 'Dados actualizados.',
            'pessoa'   => $this->formatar($this->consulta($inst->id)->where('u.uuid', $uuid)->first()),
        ]);
    }

    // Cria a ligação funcionário ↔ instituição (usado também ao aprovar um pedido de colega)
    public function ligarAInstituicao(User $user, int $instituicaoId, string $funcao, User $por, string $origem): int
    {
        return DB::table('funcionarios')->insertGetId([
            'user_id'        => $user->id,
            'instituicao_id' => $instituicaoId,
            'perfil_id'      => $this->perfilId($funcao),
            'cargo'          => $funcao,
            'origem'         => $origem,          // administrador | pedido_colega
            'registado_por'  => $por->id,
            'estado'         => 'activo',
            'aprovado_por'   => $por->id,
            'aprovado_em'    => now(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    private function perfilId(string $funcao): ?int
    {
        $slug = self::FUNCOES[$funcao] ?? 'funcionaria';

        return DB::table('perfis')->whereNull('instituicao_id')->where('slug', $slug)->value('id');
    }

    private function consulta(int $instituicaoId)
    {
        return DB::table('funcionarios as f')
            ->join('users as u', 'u.id', '=', 'f.user_id')
            ->where('f.instituicao_id', $instituicaoId)
            ->whereNull('f.deleted_at')->whereNull('u.deleted_at')
            ->orderBy('u.name')
            ->select('f.id as funcionario_id', 'f.user_id', 'f.cargo', 'f.estado', 'f.origem', 'f.created_at',
                'u.uuid', 'u.name', 'u.email', 'u.deve_alterar_senha', 'u.ultimo_acesso_em');
    }

    // Formato do ecrã "Equipa"
    private function formatar(object $l): array
    {
        return [
            'id'            => $l->uuid,
            'nome'          => $l->name,
            'email'         => $l->email,
            'perfil'        => $l->cargo ?? 'Funcionária',
            'estado'        => $l->estado,                    // activo | suspenso
            'senha_inicial' => (bool) $l->deve_alterar_senha, // registado, mas ainda não fez o 1.º acesso
            'origem'        => $l->origem,
            'ultimo_acesso' => $l->ultimo_acesso_em,
            'registado_em'  => $l->created_at,
        ];
    }
}
