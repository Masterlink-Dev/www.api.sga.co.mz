<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Enquanto a conta tiver a senha inicial (00000000), nada do ambiente fica disponível:
// a única coisa possível é mudar a senha (PUT /senha, que fica fora deste filtro).
// O portal usa o código SENHA_INICIAL para mostrar a janela obrigatória de mudança de senha.
class SenhaAlterada
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->deve_alterar_senha) {
            return response()->json([
                'codigo'   => 'SENHA_INICIAL',
                'mensagem' => 'Tem de alterar a senha inicial antes de continuar.',
            ], 403);
        }

        return $next($request);
    }
}
