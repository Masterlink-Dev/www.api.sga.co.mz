<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Credenciais;
use App\Support\Telefone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// Colaboradores (co-adjuntos) do super administrador principal.
// Nascem com uma senha inicial gerada (4 algarismos) e recebem o email das credenciais, como os administradores.
class ColaboradorController extends Controller
{
    // GET /superadmin/colaboradores → os colaboradores que ESTE super administrador registou
    public function index(Request $request): JsonResponse
    {
        $lista = User::where('tipo', User::TIPO_SUPER_ADMIN)
            ->where('colaborador', true)
            ->where('criado_por', $request->user()->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (User $u) => $this->formatar($u));

        return response()->json(['data' => $lista]);
    }

    // POST /superadmin/colaboradores  { nome, email, contacto }
    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'nome'  => trim((string) $request->input('nome')),
            'email' => mb_strtolower(trim((string) $request->input('email'))),
        ]);

        $dados = $request->validate([
            'nome'     => ['required', 'string', 'min:3', 'max:150'],
            'email'    => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'contacto' => ['required', 'string', 'max:20'],
        ], [
            'nome.required'     => 'Escreva o nome completo.',
            'nome.min'          => 'O nome é demasiado curto.',
            'email.required'    => 'Escreva o email.',
            'email.email'       => 'Escreva um email válido.',
            'email.unique'      => 'Já existe uma conta com este email.',
            'contacto.required' => 'Escreva o contacto (telemóvel).',
        ]);

        // Telemóvel de Moçambique em formato +258… (é também com ele que se pode entrar)
        $telefone = Telefone::normalizar($dados['contacto']);
        if (! $telefone) {
            return response()->json(['errors' => ['contacto' => ['O contacto não é válido. Exemplo: 84 123 4567.']], 'mensagem' => 'O contacto não é válido. Exemplo: 84 123 4567.'], 422);
        }
        if (User::where('telefone', $telefone)->exists()) {
            return response()->json(['errors' => ['contacto' => ['Este contacto já está a ser usado por outra conta.']], 'mensagem' => 'Este contacto já está a ser usado por outra conta.'], 422);
        }

        $super = $request->user();
        [$colaborador, $senha] = Credenciais::criarConta($dados['email'], User::TIPO_SUPER_ADMIN, $super, $dados['nome'], [
            'colaborador' => true,
            'telefone'    => $telefone,
        ]);
        $enviado = Credenciais::enviar($colaborador, $super, $senha);

        DB::table('registos_auditoria')->insert([
            'user_id'       => $super->id,
            'accao'         => 'registar_colaborador',
            'entidade_type' => User::class,
            'entidade_id'   => $colaborador->id,
            'descricao'     => $colaborador->email,
            'ip'            => $request->ip(),
            'user_agent'    => $request->userAgent(),
            'url'           => $request->fullUrl(),
        ]);

        return response()->json([
            'mensagem'      => $enviado
                ? 'Colaborador registado. As credenciais foram enviadas por email.'
                : 'Colaborador registado, mas não foi possível enviar o email. Indique-lhe a senha inicial.',
            'email_enviado' => $enviado,
            'senha_gerada'  => Credenciais::paraTeste($senha), // só com MOSTRAR_SENHA_GERADA=true
            'colaborador'   => $this->formatar($colaborador->fresh()),
        ], 201);
    }

    // Formato da tabela do portal
    private function formatar(User $u): array
    {
        return [
            'id'            => $u->uuid,
            'nome'          => $u->name,
            'email'         => $u->email,
            'contacto'      => $u->telefone,
            'senha'         => $u->deve_alterar_senha ? 'inicial' : 'alterada',
            'estado'        => $u->estado,
            'ultimo_acesso' => $u->ultimo_acesso_em,
            'criado_em'     => $u->created_at,
        ];
    }
}
