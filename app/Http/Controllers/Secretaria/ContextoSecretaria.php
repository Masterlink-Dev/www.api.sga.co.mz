<?php

namespace App\Http\Controllers\Secretaria;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Descobre quem é a secretaria e em que instituição trabalha.
// A instituição vem SEMPRE da base de dados, nunca do pedido.
trait ContextoSecretaria
{
    protected function funcionario(Request $request): object
    {
        $user = $request->user();

        if (! in_array($user->tipo, [1, 2])) {
            abort(response()->json(['mensagem' => 'Sem permissão.'], 403));
        }

        $funcionario = DB::table('funcionarios as f')
            ->join('instituicoes as i', 'i.id', '=', 'f.instituicao_id')
            ->where('f.user_id', $user->id)
            ->where('f.estado', 'activo')
            ->whereNull('f.deleted_at')
            ->where('i.estado', 'activa')
            ->select('f.id', 'f.instituicao_id', 'f.unidade_id', 'f.cargo', 'i.nome as instituicao', 'i.sigla', 'i.logo_ficheiro_id')
            ->first();

        if (! $funcionario) {
            abort(response()->json(['mensagem' => 'A sua conta não está ligada a uma instituição activa.'], 403));
        }

        return $funcionario;
    }

    // Só os dígitos: aceita "4829", "4 829" ou "4829 "
    protected function soDigitos(string $numero): string
    {
        return preg_replace('/\D+/', '', $numero);
    }
}
