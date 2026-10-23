<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Só deixa passar quem tem um dos tipos de conta indicados: ->middleware('tipo:3') ou ->middleware('tipo:1,2')
// (0 utilizador da app · 1 funcionário · 2 administrador da instituição · 3 super administrador)
class ExigeTipo
{
    public function handle(Request $request, Closure $next, string ...$tipos): Response
    {
        $tipo = $request->user()?->tipo;

        if ($tipo === null || ! in_array((string) $tipo, $tipos, true)) {
            return response()->json([
                'codigo'   => 'SEM_PERMISSAO',
                'mensagem' => 'Esta conta não tem acesso a esta área.',
            ], 403);
        }

        return $next($request);
    }
}
