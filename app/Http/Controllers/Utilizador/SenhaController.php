<?php

namespace App\Http\Controllers\Utilizador;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// Mudar a senha — igual para todas as contas (super administrador, administrador, funcionário e app).
// No primeiro acesso (senha inicial de 4 algarismos) não pede a senha actual: a pessoa acabou de entrar com ela.
class SenhaController extends Controller
{
    public function alterar(Request $request): JsonResponse
    {
        $user = $request->user();
        $inicial = (bool) $user->deve_alterar_senha;

        $dados = $request->validate([
            'senha_actual' => [$inicial ? 'nullable' : 'required', 'string'],
            // As mesmas regras que o portal mostra na janela "Defina a sua senha"
            'password'     => ['required', 'string', 'min:8', 'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/\d/', 'confirmed'],
        ], [
            'senha_actual.required' => 'Escreva a senha actual.',
            'password.required'     => 'Escreva a nova senha.',
            'password.min'          => 'A nova senha tem de ter pelo menos 8 caracteres.',
            'password.regex'        => 'A nova senha tem de ter letra maiúscula, letra minúscula e número.',
            'password.confirmed'    => 'As duas senhas não são iguais.',
        ]);

        if (! $inicial && ! Hash::check($dados['senha_actual'], $user->password)) {
            return response()->json(['errors' => ['senha_actual' => ['A senha actual não está correcta.']], 'mensagem' => 'A senha actual não está correcta.'], 422);
        }

        if (Hash::check($dados['password'], $user->password)) {
            return response()->json(['mensagem' => 'A nova senha tem de ser diferente da actual.'], 422);
        }

        $user->forceFill([
            'password'           => $dados['password'], // o cast "hashed" encripta
            'deve_alterar_senha' => false,              // a partir daqui o ambiente abre (middleware senha.alterada)
            'senha_alterada_em'  => now(),
        ])->save();

        // Fecha as outras sessões abertas com a senha antiga; esta continua
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        DB::table('registos_acesso')->insert([
            'user_id'    => $user->id,
            'evento'     => 'senha_alterada',
            'metodo'     => 'senha',
            'sucesso'    => true,
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['mensagem' => 'Senha alterada.', 'deve_alterar_senha' => false]);
    }
}
