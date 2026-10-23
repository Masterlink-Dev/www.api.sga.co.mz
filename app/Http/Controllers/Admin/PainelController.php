<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ficheiro;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Painel do administrador: números de hoje e os passos para pôr a instituição a funcionar
class PainelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $inst = DB::table('instituicoes')->where('administrador_id', $request->user()->id)->whereNull('deleted_at')->first();
        $id = $inst?->id ?? 0; // 0 = conta nova: todas as contagens dão zero

        $visitasHoje = DB::table('visitas')->where('instituicao_id', $id)->whereNull('deleted_at')
            ->whereDate('entrada_em', today());

        $sectores = DB::table('sectores')->where('instituicao_id', $id)->whereNull('deleted_at');
        $campos = DB::table('campos_formulario')->where('instituicao_id', $id)->whereNull('deleted_at');
        $funcionarios = DB::table('funcionarios')->where('instituicao_id', $id)->whereNull('deleted_at')->where('estado', 'activo');

        $logo = $inst?->logo_ficheiro_id ? Ficheiro::find($inst->logo_ficheiro_id) : null;

        return response()->json([
            // Para o menu (nome e logótipo); null = ainda por registar
            'instituicao'  => $inst ? ['nome' => $inst->nome, 'sigla' => $inst->sigla, 'logo' => $logo?->url() ?? ''] : null,

            'visitas_hoje' => (clone $visitasHoje)->count(),
            'dentro_agora' => DB::table('visitas')->where('instituicao_id', $id)->where('estado', 'em_curso')->whereNull('deleted_at')->count(),
            'funcionarios' => (clone $funcionarios)->count(),
            'pedidos'      => DB::table('pedidos_funcionario')->where('instituicao_id', $id)->where('estado', 'pendente')->count(),

            // Passos do ecrã "Primeiros passos" (a senha está sempre feita: sem isso não se chega aqui)
            'passos' => [
                'senha'       => true,
                'instituicao' => (bool) $inst,
                'sectores'    => (clone $sectores)->exists(),
                'formulario'  => (clone $campos)->exists(),
                'equipa'      => (clone $funcionarios)->exists(),
            ],

            // Visitas de hoje por sector activo
            'por_sector' => (clone $sectores)->where('activo', true)->orderBy('ordem')->orderBy('nome')->get(['id', 'nome'])
                ->map(fn ($s) => [
                    'rotulo' => $s->nome,
                    'valor'  => (clone $visitasHoje)->where('sector_id', $s->id)->count(),
                ]),
        ]);
    }
}
