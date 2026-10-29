<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Credenciais;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// O Super administrador gere os ACESSOS dos administradores.
// Não entra no ambiente deles nem regista instituições: isso é feito pelo próprio administrador.
class AdministradorController extends Controller
{
    // Lista de administradores, com o estado da senha e a instituição (se já a registaram)
    public function index(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'q'     => ['nullable', 'string', 'max:100'],
            'senha' => ['nullable', 'in:inicial,alterada'],
        ]);

        // São poucas contas: carrega todas e filtra aqui (o resumo conta sempre o total)
        $todos = $this->consulta()->get()->map(fn ($l) => $this->formatar($l));

        $q = mb_strtolower(trim($dados['q'] ?? ''));
        $lista = $todos
            ->when($q !== '', fn ($c) => $c->filter(fn ($a) => str_contains(
                mb_strtolower($a['email'] . ' ' . ($a['instituicao']['nome'] ?? '') . ' ' . ($a['instituicao']['sigla'] ?? '')),
                $q
            )))
            ->when($dados['senha'] ?? null, fn ($c, $senha) => $c->where('senha', $senha))
            ->values();

        return response()->json([
            'data'   => $lista,
            'resumo' => [
                'total'         => $todos->count(),
                'senha_inicial' => $todos->where('senha', 'inicial')->count(),
                // já mudou a senha, mas ainda não registou a instituição
                'por_registar'  => $todos->where('senha', 'alterada')->whereNull('instituicao')->count(),
            ],
        ]);
    }

    // Cria o acesso com o email e a senha inicial, e envia o (único) email das credenciais
    public function store(Request $request): JsonResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);

        $dados = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
        ], [
            'email.required' => 'Escreva o email do administrador.',
            'email.email'    => 'Escreva um email válido.',
            'email.unique'   => 'Já existe uma conta com este email.',
        ]);

        $super = $request->user();
        [$admin, $senha] = Credenciais::criarConta($dados['email'], User::TIPO_ADMIN, $super);
        $enviado = Credenciais::enviar($admin, $super, $senha);

        $this->auditar($request, 'criar_administrador', $admin);

        return response()->json([
            'mensagem'      => $enviado
                ? 'Administrador registado. As credenciais foram enviadas por email.'
                : 'Administrador registado, mas não foi possível enviar o email. Indique-lhe a senha inicial.',
            'email_enviado' => $enviado,
            'senha_gerada'  => Credenciais::paraTeste($senha), // só com MOSTRAR_SENHA_GERADA=true
            'administrador' => $this->formatar($this->consulta()->where('u.id', $admin->id)->first()),
        ], 201);
    }

    // Gera uma senha inicial nova (4 algarismos) e o administrador tem de a mudar no próximo acesso.
    // Não envia email: a resposta traz a senha para o Super administrador a passar ao administrador.
    public function redefinirSenha(Request $request, string $uuid): JsonResponse
    {
        $admin = User::where('uuid', $uuid)->where('tipo', User::TIPO_ADMIN)->first();
        if (! $admin) {
            return response()->json(['mensagem' => 'Administrador não encontrado.'], 404);
        }

        $senha = Credenciais::repor($admin);

        $this->auditar($request, 'redefinir_senha_administrador', $admin);

        return response()->json([
            'mensagem'      => "A nova senha temporária é {$senha}. No próximo acesso terá de a mudar.",
            'senha_gerada'  => $senha,
            'administrador' => $this->formatar($this->consulta()->where('u.id', $admin->id)->first()),
        ]);
    }

    // Administradores + a instituição que cada um registou (se já registou)
    private function consulta()
    {
        return DB::table('users as u')
            ->leftJoin('instituicoes as i', fn ($j) => $j->on('i.administrador_id', '=', 'u.id')->whereNull('i.deleted_at'))
            ->where('u.tipo', User::TIPO_ADMIN)
            ->whereNull('u.deleted_at')
            ->orderByDesc('u.created_at')
            ->select(
                'u.id', 'u.uuid', 'u.email', 'u.estado', 'u.deve_alterar_senha', 'u.ultimo_acesso_em', 'u.created_at',
                'i.id as instituicao_id', 'i.nome as instituicao_nome', 'i.sigla as instituicao_sigla',
                'i.estado as instituicao_estado'
            );
    }

    // O formato que o portal recebe (o "id" público é o uuid, nunca o id da base de dados)
    private function formatar(object $l): array
    {
        return [
            'id'            => $l->uuid,
            'email'         => $l->email,
            'estado'        => $l->estado,
            'senha'         => $l->deve_alterar_senha ? 'inicial' : 'alterada',
            'instituicao'   => $l->instituicao_id ? [
                'nome'   => $l->instituicao_nome,
                'sigla'  => $l->instituicao_sigla,
                'estado' => $l->instituicao_estado,
            ] : null,
            'ultimo_acesso' => $l->ultimo_acesso_em,
            'criada_em'     => $l->created_at,
        ];
    }

    // Fica registado quem fez o quê (tabela registos_auditoria)
    private function auditar(Request $request, string $accao, User $alvo): void
    {
        DB::table('registos_auditoria')->insert([
            'user_id'       => $request->user()->id,
            'accao'         => $accao,
            'entidade_type' => User::class,
            'entidade_id'   => $alvo->id,
            'descricao'     => $alvo->email,
            'ip'            => $request->ip(),
            'user_agent'    => $request->userAgent(),
            'url'           => $request->fullUrl(),
        ]);
    }
}
