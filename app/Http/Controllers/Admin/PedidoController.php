<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Credenciais;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Colegas propostos pelos funcionários (POST /secretaria/colegas). Só o administrador decide:
// aprovar cria a conta (senha inicial + email das credenciais) e liga-a à instituição; recusar não dá acesso.
class PedidoController extends Controller
{
    use ContextoAdmin;

    // GET /admin/pedidos → { data: [ pendentes ] }
    public function index(Request $request): JsonResponse
    {
        $inst = $this->instituicao($request);

        $lista = DB::table('pedidos_funcionario as p')
            ->leftJoin('users as u', 'u.id', '=', 'p.proposto_por')
            ->where('p.instituicao_id', $inst->id)
            ->where('p.estado', 'pendente')
            ->orderBy('p.created_at')
            ->get(['p.id', 'p.nome', 'p.email', 'p.telefone', 'p.documento_numero', 'p.cargo', 'p.justificacao', 'p.created_at', 'u.name as proposto_por'])
            ->map(fn ($p) => [
                'id'           => $p->id,
                'nome'         => $p->nome,
                'email'        => $p->email,
                'telefone'     => $p->telefone,
                'bi'           => $p->documento_numero,
                'cargo'        => $p->cargo ?? 'Funcionária',
                'justificacao' => $p->justificacao,
                'por'          => $p->proposto_por ?? 'Um funcionário',
                'em'           => $p->created_at,
            ]);

        return response()->json(['data' => $lista]);
    }

    // POST /admin/pedidos/{id}/aprovar
    public function aprovar(Request $request, int $id): JsonResponse
    {
        $inst = $this->instituicao($request);
        $pedido = $this->pendente($inst->id, $id);

        if (User::where('email', mb_strtolower($pedido->email))->exists()) {
            return response()->json(['mensagem' => 'Já existe uma conta com o email ' . $pedido->email . '. Recuse o pedido ou peça outro email.'], 422);
        }

        $admin = $request->user();
        $funcao = array_key_exists($pedido->cargo, EquipaController::FUNCOES) ? $pedido->cargo : 'Funcionária';

        [$user, $senha] = DB::transaction(function () use ($pedido, $admin, $inst, $funcao) {
            [$user, $senha] = Credenciais::criarConta(mb_strtolower($pedido->email), User::TIPO_FUNCIONARIO, $admin, $pedido->nome);
            $funcionarioId = app(EquipaController::class)->ligarAInstituicao($user, $inst->id, $funcao, $admin, 'pedido_colega');
            DB::table('funcionarios')->where('id', $funcionarioId)->update(['documento_numero' => $pedido->documento_numero]);

            DB::table('pedidos_funcionario')->where('id', $pedido->id)->update([
                'estado' => 'aprovado', 'decidido_por' => $admin->id, 'decidido_em' => now(),
                'funcionario_id' => $funcionarioId, 'updated_at' => now(),
            ]);

            return [$user, $senha];
        });

        $enviado = Credenciais::enviar($user, $admin, $senha);
        $this->auditar($request, 'aprovar_pedido_funcionario', $inst->id, 'pedidos_funcionario', $pedido->id, $pedido->email);

        return response()->json([
            'mensagem'      => "{$pedido->nome} já faz parte da equipa.",
            'email_enviado' => $enviado,
            'senha_gerada'  => Credenciais::paraTeste($senha), // só com MOSTRAR_SENHA_GERADA=true
            'pessoa'        => ['id' => $user->uuid, 'nome' => $user->name, 'email' => $user->email],
        ]);
    }

    // POST /admin/pedidos/{id}/recusar  { motivo? }
    public function recusar(Request $request, int $id): JsonResponse
    {
        $inst = $this->instituicao($request);
        $pedido = $this->pendente($inst->id, $id);
        $dados = $request->validate(['motivo' => ['nullable', 'string', 'max:255']]);

        DB::table('pedidos_funcionario')->where('id', $pedido->id)->update([
            'estado' => 'recusado', 'decidido_por' => $request->user()->id, 'decidido_em' => now(),
            'motivo_recusa' => $dados['motivo'] ?? null, 'updated_at' => now(),
        ]);
        $this->auditar($request, 'recusar_pedido_funcionario', $inst->id, 'pedidos_funcionario', $pedido->id, $pedido->email);

        return response()->json(['mensagem' => 'Pedido recusado.', 'pedido' => ['id' => $pedido->id, 'nome' => $pedido->nome]]);
    }

    private function pendente(int $instituicaoId, int $id): object
    {
        return DB::table('pedidos_funcionario')->where('id', $id)->where('instituicao_id', $instituicaoId)->where('estado', 'pendente')->first()
            ?? abort(response()->json(['mensagem' => 'Pedido não encontrado ou já decidido.'], 404));
    }
}
