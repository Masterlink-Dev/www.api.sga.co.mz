<?php

namespace App\Http\Controllers\Utilizador;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Telefone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    // Área de cada tipo de conta: o portal usa-a para abrir a página certa depois do login.
    // Quem decide o que cada conta pode fazer são os middlewares (tipo:N, senha.alterada); isto é só navegação.
    private const AREAS = [
        User::TIPO_UTILIZADOR  => 'app',        // só a app móvel
        User::TIPO_FUNCIONARIO => 'portal',     // recepção: /portal
        User::TIPO_ADMIN       => 'admin',      // administrador da instituição: /admin
        User::TIPO_SUPER_ADMIN => 'superadmin', // equipa MeuAcesso: /superadmin
    ];

    public function login(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'login'    => ['required', 'string'], // e-mail ou telefone
            'password' => ['required', 'string'],
        ]);

        // Se tiver "@" é e-mail, senão é telefone ("84 123 4567" → "+258841234567")
        $login = trim($dados['login']);

        if (str_contains($login, '@')) {
            $campo = 'email';
            $valor = mb_strtolower($login);
        } else {
            $campo = 'telefone';
            $valor = Telefone::normalizar($login) ?? $login;
        }

        $user = User::where($campo, $valor)->first();

        // A mesma mensagem para "não existe" e "password errada" (não revela qual falhou)
        // Aceita a palavra-passe como foi escrita e também sem espaços no início/fim
        $passwordOk = $user && (
            Hash::check($dados['password'], $user->password)
            || Hash::check(trim($dados['password']), $user->password)
        );

        if (! $passwordOk) {
            $this->registar($request, $user, 'login_falhado', false, $valor);

            return response()->json(['mensagem' => 'Credenciais inválidas.'], 401);
        }

        if ($user->estado !== 'activo') {
            $this->registar($request, $user, 'login_falhado', false, $valor, 'conta_' . $user->estado);

            return response()->json(['mensagem' => 'A sua conta não está activa.'], 403);
        }

        $token = $user->createToken('meuacesso')->plainTextToken;

        // Último acesso (o Super administrador vê-o em "Credenciais") e o primeiro, que fica para sempre
        $user->forceFill([
            'ultimo_acesso_em'   => now(),
            'ultimo_acesso_ip'   => $request->ip(),
            'primeiro_acesso_em' => $user->primeiro_acesso_em ?? now(),
        ])->save();

        $this->registar($request, $user, 'login', true);

        return response()->json([
            'mensagem'   => 'Login feito com êxito!',
            'utilizador' => ['token' => $token] + $this->dados($user),
        ]);
    }

    // GET /me — quem tem a sessão aberta. O portal chama-a depois de recarregar a página (F5)
    // para saber a área e se ainda tem de mudar a senha, sem confiar só no que guardou no navegador.
    public function me(Request $request): JsonResponse
    {
        return response()->json(['utilizador' => $this->dados($request->user())]);
    }

    // Termina a sessão: apaga só o token que fez este pedido
    public function logout(Request $request): JsonResponse
    {
        $this->registar($request, $request->user(), 'logout', true);
        $request->user()->currentAccessToken()->delete();

        return response()->json(['mensagem' => 'Sessão terminada.']);
    }

    // Os dados da conta que o login e o /me devolvem
    private function dados(User $user): array
    {
        return [
            'uuid'               => $user->uuid,
            'numero'             => $user->numero,
            'nome'               => $user->name,
            'email'              => $user->email,
            'telefone'           => $user->telefone,
            'tipo'               => $user->tipo,                       // 0 utilizador, 1 funcionário, 2 admin, 3 super admin
            'area'               => self::AREAS[$user->tipo] ?? 'app', // app | portal | admin | superadmin
            'colaborador'        => (bool) $user->colaborador,         // tipo 3: true = colaborador (não regista colaboradores)
            'deve_alterar_senha' => (bool) $user->deve_alterar_senha,  // true = senha inicial: tem de a mudar
        ];
    }

    // Histórico de entradas na conta (tabela registos_acesso)
    private function registar(Request $request, ?User $user, string $evento, bool $sucesso, ?string $tentado = null, ?string $motivo = null): void
    {
        DB::table('registos_acesso')->insert([
            'user_id'               => $user?->id,
            'evento'                => $evento,
            'metodo'                => 'senha',
            'sucesso'               => $sucesso,
            'motivo'                => $motivo,
            'identificador_tentado' => $sucesso ? null : $tentado, // email/telefone usado numa tentativa falhada
            'ip'                    => $request->ip(),
            'user_agent'            => $request->userAgent(),
        ]);
    }
}
