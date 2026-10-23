<?php

namespace App\Services;

use App\Mail\CredenciaisAcesso;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

// Contas que nascem com a senha inicial (administradores e equipa) e o email das credenciais.
// Usado pelo Super administrador (cria administradores) e, na parte II, pelo administrador (cria a equipa).
// A senha inicial não tem prazo: a protecção é o middleware senha.alterada, que fecha o ambiente até ser mudada.
class Credenciais
{
    // Cria a conta com a senha 00000000. Quem a recebe é obrigado a mudá-la no primeiro acesso.
    // $extra: colunas a mais (ex.: ['colaborador' => true, 'telefone' => '+258…'])
    public static function criarConta(string $email, int $tipo, User $criadoPor, ?string $nome = null, array $extra = []): User
    {
        return User::create([
            'uuid'               => (string) Str::uuid(),
            'tipo'               => $tipo,
            'name'               => $nome ?: Str::before($email, '@'), // sem nome: usa o início do email
            'email'              => $email,
            'password'           => User::SENHA_INICIAL,              // o cast "hashed" do modelo encripta
            'estado'             => 'activo',
            'criado_por'         => $criadoPor->id,
            'deve_alterar_senha' => true,
        ] + $extra);
    }

    // Volta à senha inicial, desbloqueia a conta e fecha as sessões abertas. Não envia email.
    public static function repor(User $user): void
    {
        $user->forceFill([
            'password'            => User::SENHA_INICIAL,
            'deve_alterar_senha'  => true,
            'tentativas_falhadas' => 0,
            'bloqueado_ate'       => null,
        ])->save();

        // Quem estava dentro com a senha antiga é posto fora
        $user->tokens()->delete();
    }

    // Envia o email das credenciais (só na criação da conta) e deixa registo em envios_credenciais.
    // Se o envio falhar, a conta fica criada na mesma e a função devolve false.
    public static function enviar(User $user, User $por): bool
    {
        $envioId = DB::table('envios_credenciais')->insertGetId([
            'user_id'     => $user->id,
            'enviado_por' => $por->id,
            'motivo'      => 'criacao',
            'canal'       => 'email',
            'destino'     => $user->email,
            'estado'      => 'pendente',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        try {
            Mail::to($user->email)->send(new CredenciaisAcesso($user));

            DB::table('envios_credenciais')->where('id', $envioId)
                ->update(['estado' => 'enviado', 'enviado_em' => now(), 'updated_at' => now()]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Falha ao enviar as credenciais', ['user' => $user->id, 'erro' => $e->getMessage()]);

            DB::table('envios_credenciais')->where('id', $envioId)
                ->update(['estado' => 'falhou', 'updated_at' => now()]);

            return false;
        }
    }
}
