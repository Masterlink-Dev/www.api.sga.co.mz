<?php

namespace App\Http\Controllers\Secretaria;

use App\Http\Controllers\Controller;
use App\Models\Cidadao;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CidadaoController extends Controller
{
    use ContextoSecretaria;

    // A secretaria procura o cidadão pelo número (código ou QR) e vê quem é
    public function show(Request $request, string $numero): JsonResponse
    {
        $func = $this->funcionario($request);

        $conta = User::where('numero', $this->soDigitos($numero))->where('tipo', 0)->first();

        if (! $conta) {
            return response()->json(['mensagem' => 'Cidadão não encontrado.'], 404);
        }

        $cidadao = $conta->cidadaoOuCriar();

        // Fica registado quem consultou os dados de quem (auditoria)
        DB::table('consultas_dados')->insert([
            'cidadao_id'      => $cidadao->id,
            'instituicao_id'  => $func->instituicao_id,
            'unidade_id'      => $func->unidade_id,
            'user_id'         => $request->user()->id,
            'tipo'            => 'codigo',
            'campos_acedidos' => json_encode(['nome', 'foto', 'documentos']),
            'ip'              => $request->ip(),
            'user_agent'      => substr((string) $request->userAgent(), 0, 500),
        ]);

        $restricoes = DB::table('restricoes_acesso')
            ->where('instituicao_id', $func->instituicao_id)
            ->where('cidadao_id', $cidadao->id)
            ->where('activa', true)
            ->where(fn ($q) => $q->whereNull('valido_ate')->orWhere('valido_ate', '>=', now()->toDateString()))
            ->get(['tipo', 'motivo']);

        $dentro = DB::table('visitas')
            ->where('instituicao_id', $func->instituicao_id)
            ->where('cidadao_id', $cidadao->id)
            ->where('estado', 'em_curso')
            ->whereNull('deleted_at')
            ->value('uuid');

        return response()->json([
            'cidadao' => [
                'numero'   => $conta->numero,
                'nome'     => $cidadao->nome_completo,
                'bi'       => $cidadao->numero_documento, // completo, sem máscara
                'telefone' => $cidadao->telefone,
                'email'    => $cidadao->email,
                'bi_estado' => $this->estadoBi($cidadao),
            ],
            'documentos'  => $cidadao->documentosParaApi(),
            'restricoes'  => $restricoes,
            'visita_em_curso' => $dentro, // uuid se já está dentro, senão null
        ]);
    }

    private function estadoBi(Cidadao $c): string
    {
        if (in_array($c->nivel_verificacao, ['documento', 'presencial', 'oficial'])) {
            return 'verificado';
        }

        return ($c->documento_frente_ficheiro_id && $c->documento_verso_ficheiro_id) ? 'submetido' : 'nao_submetido';
    }
}
