<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// A instituição do administrador que faz o pedido. Vem SEMPRE da base de dados, nunca do pedido.
trait ContextoAdmin
{
    // A instituição deste administrador, ou null se ainda não a registou
    protected function instituicaoOuNull(Request $request): ?object
    {
        return DB::table('instituicoes')
            ->where('administrador_id', $request->user()->id)
            ->whereNull('deleted_at')
            ->first();
    }

    // Para os ecrãs que só funcionam depois de registar a instituição (sectores, formulário, equipa...)
    protected function instituicao(Request $request): object
    {
        return $this->instituicaoOuNull($request) ?? abort(response()->json([
            'codigo'   => 'SEM_INSTITUICAO',
            'mensagem' => 'Registe primeiro a sua instituição (menu Instituição).',
        ], 409));
    }

    // Fica registado quem fez o quê (tabela registos_auditoria)
    protected function auditar(Request $request, string $accao, ?int $instituicaoId, string $entidade, ?int $entidadeId, ?string $descricao = null): void
    {
        DB::table('registos_auditoria')->insert([
            'user_id'        => $request->user()->id,
            'instituicao_id' => $instituicaoId,
            'accao'          => $accao,
            'entidade_type'  => $entidade,
            'entidade_id'    => $entidadeId,
            'descricao'      => $descricao,
            'ip'             => $request->ip(),
            'user_agent'     => $request->userAgent(),
            'url'            => $request->fullUrl(),
        ]);
    }
}
