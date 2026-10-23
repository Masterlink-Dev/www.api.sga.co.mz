<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Só o super administrador principal passa (os colaboradores recebem 403 SO_PRINCIPAL).
// Usado nas rotas de registo de colaboradores: ->middleware('super.principal')
class SoSuperPrincipal
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->colaborador) {
            return response()->json([
                'codigo'   => 'SO_PRINCIPAL',
                'mensagem' => 'Só o super administrador principal pode registar colaboradores.',
            ], 403);
        }

        return $next($request);
    }
}
