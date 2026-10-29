<?php

namespace App\Services;

use App\Mail\CredenciaisAcesso;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

// Contas que nascem com uma senha inicial (administradores, colaboradores e equipa) e o email das credenciais.
// A senha inicial é gerada ao acaso para cada conta (4 algarismos) e vai só no email.
// A protecção é o middleware senha.alterada, que fecha o ambiente até ser mudada.
class Credenciais
{
    // Senhas de 4 algarismos fáceis de adivinhar: nunca são geradas
    private const PREVISIVEIS = ['0000', '1111', '2222', '3333', '4444', '5555', '6666', '7777', '8888', '9999', '1234', '4321', '0123', '9876'];

    // "0427", "8153"… (random_int é criptograficamente seguro)
    public static function gerarSenha(): string
    {
        do {
            $senha = str_pad((string) random_int(0, 9999), User::DIGITOS_SENHA_INICIAL, '0', STR_PAD_LEFT);
        } while (in_array($senha, self::PREVISIVEIS, true));

        return $senha;
    }

    // Só em testes (MOSTRAR_SENHA_GERADA=true no .env): a resposta da API leva a senha para o portal a mostrar.
    // Em produção devolve null e a senha só existe no email.
    public static function paraTeste(string $senha): ?string
    {
        return config('app.mostrar_senha_gerada') ? $senha : null;
    }

    // Cria a conta com uma senha inicial gerada. Quem a recebe é obrigado a mudá-la no primeiro acesso.
    // $extra: colunas a mais (ex.: ['colaborador' => true, 'telefone' => '+258…'])
    // Devolve [User, senha em claro] — a senha só serve para o email (e para os testes); não fica guardada.
    public static function criarConta(string $email, int $tipo, User $criadoPor, ?string $nome = null, array $extra = []): array
    {
        $senha = self::gerarSenha();

        $user = User::create([
            'uuid'               => (string) Str::uuid(),
            'tipo'               => $tipo,
            'name'               => $nome ?: Str::before($email, '@'), // sem nome: usa o início do email
            'email'              => $email,
            'password'           => $senha,                           // o cast "hashed" do modelo encripta
            'estado'             => 'activo',
            'criado_por'         => $criadoPor->id,
            'deve_alterar_senha' => true,
        ] + $extra);

        return [$user, $senha];
    }

    // Gera uma senha inicial nova, desbloqueia a conta e fecha as sessões abertas. Não envia email:
    // devolve a senha para quem repôs a passar à pessoa.
    public static function repor(User $user): string
    {
        $senha = self::gerarSenha();

        $user->forceFill([
            'password'            => $senha,
            'deve_alterar_senha'  => true,
            'tentativas_falhadas' => 0,
            'bloqueado_ate'       => null,
        ])->save();

        // Quem estava dentro com a senha antiga é posto fora
        $user->tokens()->delete();

        return $senha;
    }

    // Envia o email das credenciais (só na criação da conta) e deixa registo em envios_credenciais.
    // Se o envio falhar, a conta fica criada na mesma e a função devolve false.
    public static function enviar(User $user, User $por, string $senha): bool
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
            Mail::to($user->email)->send(new CredenciaisAcesso($user, $senha));

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
