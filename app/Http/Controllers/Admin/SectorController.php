<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Sectores e motivos de visita da instituição: são as opções que a recepção escolhe ao registar uma entrada.
// Remover = soft delete (as visitas antigas continuam a mostrar o sector/motivo). Voltar a criar com o
// mesmo nome recupera o registo antigo, porque o nome é único por instituição.
class SectorController extends Controller
{
    use ContextoAdmin;

    // GET /admin/sectores → { sectores: [...], motivos: ['Reunião', ..., 'Outro'] }
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->listas($this->instituicao($request)->id));
    }

    // POST /admin/sectores  { nome, piso, responsavel }
    public function store(Request $request): JsonResponse
    {
        $inst = $this->instituicao($request);
        $dados = $this->validarSector($request);

        $existente = DB::table('sectores')->where('instituicao_id', $inst->id)->where('nome', $dados['nome'])->first();
        if ($existente && ! $existente->deleted_at) {
            return response()->json(['errors' => ['nome' => ['Já existe um sector com este nome.']], 'mensagem' => 'Já existe um sector com este nome.'], 422);
        }

        $valores = [
            'piso'             => $dados['piso'] ?? null,
            'responsavel_nome' => $dados['responsavel'] ?? null,
            'activo'           => true,
            'deleted_at'       => null,
            'updated_at'       => now(),
        ];

        if ($existente) {
            // Tinha sido removido: volta a existir
            DB::table('sectores')->where('id', $existente->id)->update($valores);
            $id = $existente->id;
        } else {
            $id = DB::table('sectores')->insertGetId($valores + [
                'instituicao_id' => $inst->id,
                'nome'           => $dados['nome'],
                'ordem'          => (int) DB::table('sectores')->where('instituicao_id', $inst->id)->max('ordem') + 1,
                'created_at'     => now(),
            ]);
        }

        $this->auditar($request, 'criar_sector', $inst->id, 'sectores', $id, $dados['nome']);

        return response()->json(['mensagem' => 'Sector criado.'] + $this->listas($inst->id), 201);
    }

    // PUT /admin/sectores/{id}  { nome?, piso?, responsavel?, activo? }
    public function update(Request $request, int $id): JsonResponse
    {
        $inst = $this->instituicao($request);
        $sector = $this->sectorDaInstituicao($inst->id, $id);

        $dados = $this->validarSector($request, true);

        if (isset($dados['nome']) && $dados['nome'] !== $sector->nome
            && DB::table('sectores')->where('instituicao_id', $inst->id)->where('nome', $dados['nome'])->where('id', '!=', $id)->exists()) {
            return response()->json(['errors' => ['nome' => ['Já existe um sector com este nome.']], 'mensagem' => 'Já existe um sector com este nome.'], 422);
        }

        $valores = array_filter([
            'nome'             => $dados['nome'] ?? null,
            'piso'             => array_key_exists('piso', $dados) ? ($dados['piso'] ?? '') : null,
            'responsavel_nome' => array_key_exists('responsavel', $dados) ? ($dados['responsavel'] ?? '') : null,
        ], fn ($v) => $v !== null);
        if (array_key_exists('activo', $dados)) {
            $valores['activo'] = (bool) $dados['activo'];
        }
        $valores['updated_at'] = now();

        DB::table('sectores')->where('id', $id)->update($valores);
        $this->auditar($request, 'actualizar_sector', $inst->id, 'sectores', $id, $dados['nome'] ?? $sector->nome);

        return response()->json(['mensagem' => 'Sector actualizado.'] + $this->listas($inst->id));
    }

    // DELETE /admin/sectores/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        $inst = $this->instituicao($request);
        $sector = $this->sectorDaInstituicao($inst->id, $id);

        DB::table('sectores')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        $this->auditar($request, 'remover_sector', $inst->id, 'sectores', $id, $sector->nome);

        return response()->json(['mensagem' => 'Sector removido.'] + $this->listas($inst->id));
    }

    // POST /admin/motivos  { nome }
    public function motivo(Request $request): JsonResponse
    {
        $inst = $this->instituicao($request);
        $request->merge(['nome' => trim((string) $request->input('nome'))]);
        $dados = $request->validate(
            ['nome' => ['required', 'string', 'max:150']],
            ['nome.required' => 'Escreva o motivo.', 'nome.max' => 'O motivo tem no máximo 150 caracteres.']
        );

        // "Outro" é da plataforma e já existe em todas as instituições
        if (mb_strtolower($dados['nome']) === 'outro'
            || DB::table('motivos_visita')->where('instituicao_id', $inst->id)->where('nome', $dados['nome'])->whereNull('deleted_at')->exists()) {
            return response()->json(['errors' => ['nome' => ['Este motivo já existe.']], 'mensagem' => 'Este motivo já existe.'], 422);
        }

        $existente = DB::table('motivos_visita')->where('instituicao_id', $inst->id)->where('nome', $dados['nome'])->first();
        if ($existente) {
            DB::table('motivos_visita')->where('id', $existente->id)->update(['deleted_at' => null, 'activo' => true, 'updated_at' => now()]);
            $id = $existente->id;
        } else {
            $id = DB::table('motivos_visita')->insertGetId([
                'instituicao_id' => $inst->id,
                'nome'           => $dados['nome'],
                'ordem'          => (int) DB::table('motivos_visita')->where('instituicao_id', $inst->id)->max('ordem') + 1,
                'activo'         => true,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }

        $this->auditar($request, 'criar_motivo', $inst->id, 'motivos_visita', $id, $dados['nome']);

        return response()->json(['mensagem' => 'Motivo acrescentado.'] + $this->listas($inst->id), 201);
    }

    // DELETE /admin/motivos/{nome}  (o "Outro" da plataforma não se pode retirar)
    public function removerMotivo(Request $request, string $nome): JsonResponse
    {
        $inst = $this->instituicao($request);
        $motivo = DB::table('motivos_visita')->where('instituicao_id', $inst->id)->where('nome', $nome)->whereNull('deleted_at')->first();
        if (! $motivo) {
            return response()->json(['mensagem' => 'Motivo não encontrado.'], 404);
        }

        DB::table('motivos_visita')->where('id', $motivo->id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        $this->auditar($request, 'remover_motivo', $inst->id, 'motivos_visita', $motivo->id, $nome);

        return response()->json(['mensagem' => 'Motivo retirado.'] + $this->listas($inst->id));
    }

    // As duas listas no formato do ecrã "Sectores e motivos"
    private function listas(int $instituicaoId): array
    {
        $sectores = DB::table('sectores')->where('instituicao_id', $instituicaoId)->whereNull('deleted_at')
            ->orderBy('ordem')->orderBy('nome')->get()
            ->map(fn ($s) => [
                'id'          => $s->id,
                'nome'        => $s->nome,
                'piso'        => $s->piso ?? '',
                'responsavel' => $s->responsavel_nome ?? '',
                'activo'      => (bool) $s->activo,
            ]);

        // Os da instituição primeiro; os da plataforma ("Outro") ficam sempre no fim
        $motivos = DB::table('motivos_visita')
            ->where(fn ($q) => $q->where('instituicao_id', $instituicaoId)->orWhereNull('instituicao_id'))
            ->whereNull('deleted_at')->where('activo', true)
            ->orderByRaw('instituicao_id IS NULL')->orderBy('ordem')->orderBy('nome')
            ->pluck('nome');

        return ['sectores' => $sectores, 'motivos' => $motivos];
    }

    private function sectorDaInstituicao(int $instituicaoId, int $id): object
    {
        return DB::table('sectores')->where('id', $id)->where('instituicao_id', $instituicaoId)->whereNull('deleted_at')->first()
            ?? abort(response()->json(['mensagem' => 'Sector não encontrado.'], 404));
    }

    private function validarSector(Request $request, bool $parcial = false): array
    {
        foreach (['nome', 'piso', 'responsavel'] as $campo) {
            if ($request->has($campo)) {
                $request->merge([$campo => trim((string) $request->input($campo))]);
            }
        }

        return $request->validate([
            'nome'        => [$parcial ? 'sometimes' : 'required', 'string', 'max:150'],
            'piso'        => ['nullable', 'string', 'max:30'],
            'responsavel' => ['nullable', 'string', 'max:150'],
            'activo'      => ['sometimes', 'boolean'],
        ], [
            'nome.required' => 'Escreva o nome do sector.',
            'nome.max'      => 'O nome tem no máximo 150 caracteres.',
            'piso.max'      => 'O piso tem no máximo 30 caracteres.',
        ]);
    }
}
