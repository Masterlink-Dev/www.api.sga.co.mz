<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

// Formulário de visita (campos dinâmicos): os campos que a recepção preenche ao registar uma entrada.
//
// O portal fala em rótulos ("Lista de escolha") e a base de dados guarda códigos ("lista").
// Alguns campos ligam-se ao sistema (campo_sistema) para a resposta ir para a coluna da visita:
//   "Motivo…" (lista) → motivo · "Sector…" (lista) → sector · "Pessoa a visitar" → pessoa_a_visitar
//   BI → documento_numero · Telefone → telefone_visitante · "Observações" → observacoes
// Os outros campos são livres e as respostas ficam em respostas_visita.
class CampoController extends Controller
{
    use ContextoAdmin;

    // Código na base de dados → rótulo no portal
    private const TIPOS = [
        'texto_curto' => 'Texto curto',
        'texto_longo' => 'Texto longo',
        'lista'       => 'Lista de escolha',
        'telefone'    => 'Telefone',
        'bi'          => 'BI (12 algarismos + 1 letra)',
        'data'        => 'Data',
        'numero'      => 'Número',
    ];

    // GET /admin/formulario/campos → { campos: [...], tipos: ['Texto curto', ...] }
    public function index(Request $request): JsonResponse
    {
        $formulario = $this->formulario($this->instituicao($request));

        return response()->json($this->resposta($formulario->id));
    }

    // POST /admin/formulario/campos  { nome, tipo, obrigatorio?, opcoes?: ['A', 'B'] }
    public function store(Request $request): JsonResponse
    {
        $inst = $this->instituicao($request);
        $formulario = $this->formulario($inst);

        $request->merge(['nome' => trim((string) $request->input('nome')), 'tipo' => $this->codigoDoTipo($request->input('tipo'))]);
        $dados = $request->validate([
            'nome'        => ['required', 'string', 'max:100'],
            'tipo'        => ['required', Rule::in(array_keys(self::TIPOS))],
            'obrigatorio' => ['sometimes', 'boolean'],
            'opcoes'      => ['nullable', 'array', 'max:50'],
            'opcoes.*'    => ['string', 'max:150'],
        ], [
            'nome.required' => 'Dê um nome ao campo.',
            'nome.max'      => 'O nome tem no máximo 100 caracteres.',
            'tipo.required' => 'Escolha o tipo de informação.',
            'tipo.in'       => 'Escolha um tipo de informação da lista.',
        ]);

        // Ligação ao sistema (só se esse campo ainda não estiver no formulário)
        $sistema = $this->campoSistema($dados['nome'], $dados['tipo']);
        if ($sistema && DB::table('campos_formulario')->where('formulario_id', $formulario->id)->where('campo_sistema', $sistema)->whereNull('deleted_at')->exists()) {
            return response()->json(['errors' => ['nome' => ['Este campo já existe no formulário.']], 'mensagem' => 'Este campo já existe no formulário.'], 422);
        }
        // A chave dos campos do sistema é o próprio nome do campo_sistema (ex.: "motivo"), se estiver livre
        $chave = $sistema && ! DB::table('campos_formulario')->where('formulario_id', $formulario->id)->where('chave', $sistema)->whereNull('deleted_at')->exists()
            ? $sistema
            : $this->chaveLivre($formulario->id, $dados['nome']);

        $valores = [
            'instituicao_id'  => $inst->id,
            'nome'            => $dados['nome'],
            'tipo'            => $dados['tipo'],
            'campo_sistema'   => $sistema,
            'fonte_opcoes'    => $dados['tipo'] === 'lista' ? ($sistema === 'motivo' ? 'motivos' : ($sistema === 'sector' ? 'sectores' : 'manual')) : null,
            'obrigatorio'     => (bool) ($dados['obrigatorio'] ?? false),
            'visivel'         => true,
            'ordem'           => (int) DB::table('campos_formulario')->where('formulario_id', $formulario->id)->whereNull('deleted_at')->max('ordem') + 1,
            'actualizado_por' => $request->user()->id,
            'deleted_at'      => null,
            'updated_at'      => now(),
        ];

        // O mesmo campo pode ter sido removido antes (chave única por formulário): recupera-se
        $antigo = DB::table('campos_formulario')->where('formulario_id', $formulario->id)
            ->where(fn ($q) => $q->where('chave', $chave)->when($sistema, fn ($w) => $w->orWhere('campo_sistema', $sistema)))
            ->first();

        DB::transaction(function () use ($antigo, $valores, $formulario, $chave, $request, $dados, &$id) {
            if ($antigo) {
                DB::table('campos_formulario')->where('id', $antigo->id)->update($valores + ['chave' => $chave]);
                $id = $antigo->id;
                DB::table('opcoes_campo')->where('campo_id', $id)->delete();
            } else {
                $id = DB::table('campos_formulario')->insertGetId($valores + [
                    'formulario_id' => $formulario->id,
                    'chave'         => $chave,
                    'criado_por'    => $request->user()->id,
                    'created_at'    => now(),
                ]);
            }

            // Opções escritas à mão (listas que não são o motivo nem o sector)
            if ($valores['fonte_opcoes'] === 'manual') {
                $usados = [];
                foreach (array_values(array_filter(array_map('trim', $dados['opcoes'] ?? []))) as $i => $rotulo) {
                    $valor = Str::slug($rotulo, '_') ?: 'opcao_' . ($i + 1);
                    if (isset($usados[$valor])) {
                        continue; // opção repetida
                    }
                    $usados[$valor] = true;
                    DB::table('opcoes_campo')->insert([
                        'campo_id' => $id, 'valor' => $valor, 'rotulo' => $rotulo, 'ordem' => $i,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }

            $this->novaVersao($formulario->id, $request->user()->id);
        });

        $this->auditar($request, 'criar_campo', $inst->id, 'campos_formulario', $id, $dados['nome']);

        return response()->json(['mensagem' => 'Campo acrescentado.'] + $this->resposta($formulario->id), 201);
    }

    // PUT /admin/formulario/campos/{id}  { nome?, obrigatorio?, visivel?, opcoes? }
    public function update(Request $request, int $id): JsonResponse
    {
        $inst = $this->instituicao($request);
        $formulario = $this->formulario($inst);
        $campo = $this->campoDoFormulario($formulario->id, $id);

        if ($request->has('nome')) {
            $request->merge(['nome' => trim((string) $request->input('nome'))]);
        }
        $dados = $request->validate([
            'nome'        => ['sometimes', 'required', 'string', 'max:100'],
            'obrigatorio' => ['sometimes', 'boolean'],
            'visivel'     => ['sometimes', 'boolean'],
            'opcoes'      => ['sometimes', 'nullable', 'array', 'max:50'],
            'opcoes.*'    => ['string', 'max:150'],
        ], ['nome.required' => 'Dê um nome ao campo.']);

        $valores = array_intersect_key($dados, array_flip(['nome', 'obrigatorio', 'visivel']));
        // Um campo oculto não pode ser obrigatório (a recepção não o vê)
        if (array_key_exists('visivel', $valores) && ! $valores['visivel']) {
            $valores['obrigatorio'] = false;
        }

        DB::transaction(function () use ($campo, $valores, $dados, $formulario, $request) {
            DB::table('campos_formulario')->where('id', $campo->id)
                ->update($valores + ['actualizado_por' => $request->user()->id, 'updated_at' => now()]);

            if (array_key_exists('opcoes', $dados) && $campo->fonte_opcoes === 'manual') {
                DB::table('opcoes_campo')->where('campo_id', $campo->id)->delete();
                foreach (array_values(array_filter(array_map('trim', $dados['opcoes'] ?? []))) as $i => $rotulo) {
                    DB::table('opcoes_campo')->insertOrIgnore([
                        'campo_id' => $campo->id, 'valor' => Str::slug($rotulo, '_') ?: 'opcao_' . ($i + 1), 'rotulo' => $rotulo,
                        'ordem' => $i, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }

            $this->novaVersao($formulario->id, $request->user()->id);
        });

        $this->auditar($request, 'actualizar_campo', $inst->id, 'campos_formulario', $campo->id, $valores['nome'] ?? $campo->nome);

        return response()->json(['mensagem' => 'Campo actualizado.'] + $this->resposta($formulario->id));
    }

    // DELETE /admin/formulario/campos/{id}  (soft delete: as visitas antigas guardam a cópia do campo)
    public function destroy(Request $request, int $id): JsonResponse
    {
        $inst = $this->instituicao($request);
        $formulario = $this->formulario($inst);
        $campo = $this->campoDoFormulario($formulario->id, $id);

        DB::table('campos_formulario')->where('id', $campo->id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        $this->novaVersao($formulario->id, $request->user()->id);
        $this->auditar($request, 'remover_campo', $inst->id, 'campos_formulario', $campo->id, $campo->nome);

        return response()->json(['mensagem' => 'Campo removido.'] + $this->resposta($formulario->id));
    }

    // O formulário de registo de visita da instituição (criado no registo da instituição; cria-se se faltar)
    private function formulario(object $inst): object
    {
        $formulario = DB::table('formularios')->where('instituicao_id', $inst->id)->where('finalidade', 'registo_visita')->first();
        if (! $formulario) {
            DB::table('formularios')->insert([
                'uuid' => (string) Str::uuid(), 'instituicao_id' => $inst->id, 'finalidade' => 'registo_visita',
                'nome' => 'Registar entrada', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $formulario = DB::table('formularios')->where('instituicao_id', $inst->id)->where('finalidade', 'registo_visita')->first();
        }

        return $formulario;
    }

    // Campos + tipos, no formato do ecrã "Formulário de visita"
    private function resposta(int $formularioId): array
    {
        $campos = DB::table('campos_formulario')->where('formulario_id', $formularioId)->whereNull('deleted_at')
            ->orderBy('ordem')->orderBy('id')->get();
        $opcoes = DB::table('opcoes_campo')->whereIn('campo_id', $campos->pluck('id'))->where('activo', true)
            ->orderBy('ordem')->get()->groupBy('campo_id');

        return [
            'campos' => $campos->map(fn ($c) => [
                'id'            => $c->id,
                'nome'          => $c->nome,
                'chave'         => $c->chave,
                'tipo'          => self::TIPOS[$c->tipo] ?? $c->tipo, // rótulo para o portal
                'tipo_codigo'   => $c->tipo,
                'campo_sistema' => $c->campo_sistema,
                'fonte_opcoes'  => $c->fonte_opcoes,                  // motivos | sectores | manual | null
                'opcoes'        => ($opcoes[$c->id] ?? collect())->pluck('rotulo')->values(),
                'obrigatorio'   => (bool) $c->obrigatorio,
                'visivel'       => (bool) $c->visivel,
            ])->values(),
            'tipos'  => array_values(self::TIPOS),
        ];
    }

    // Aceita o rótulo do portal ("Lista de escolha") ou o código ("lista")
    private function codigoDoTipo(mixed $tipo): ?string
    {
        $tipo = trim((string) $tipo);
        if (isset(self::TIPOS[$tipo])) {
            return $tipo;
        }
        $codigo = array_search($tipo, self::TIPOS, true);

        return $codigo === false ? $tipo : $codigo;
    }

    // Que coluna da visita este campo alimenta (pelo nome e pelo tipo)
    private function campoSistema(string $nome, string $tipo): ?string
    {
        $n = Str::lower(Str::ascii($nome));

        return match (true) {
            $tipo === 'lista' && str_contains($n, 'motivo') => 'motivo',
            $tipo === 'lista' && str_contains($n, 'sector') => 'sector',
            $tipo === 'bi'                                   => 'documento_numero',
            $tipo === 'telefone'                             => 'telefone_visitante',
            str_contains($n, 'pessoa a visitar')             => 'pessoa_a_visitar',
            str_contains($n, 'observa')                      => 'observacoes',
            default                                          => null,
        };
    }

    // "Número do processo" → numero_do_processo (com _2, _3... se já existir um activo)
    private function chaveLivre(int $formularioId, string $nome): string
    {
        $base = Str::limit(Str::slug($nome, '_'), 50, '') ?: 'campo';
        $chave = $base;
        for ($n = 2; DB::table('campos_formulario')->where('formulario_id', $formularioId)->where('chave', $chave)->whereNull('deleted_at')->exists(); $n++) {
            $chave = "{$base}_{$n}";
        }

        return $chave;
    }

    private function campoDoFormulario(int $formularioId, int $id): object
    {
        return DB::table('campos_formulario')->where('id', $id)->where('formulario_id', $formularioId)->whereNull('deleted_at')->first()
            ?? abort(response()->json(['mensagem' => 'Campo não encontrado.'], 404));
    }

    // Cada mudança sobe a versão (as respostas guardam a versão com que foram preenchidas)
    private function novaVersao(int $formularioId, int $userId): void
    {
        DB::table('formularios')->where('id', $formularioId)
            ->update(['versao' => DB::raw('versao + 1'), 'actualizado_por' => $userId, 'updated_at' => now()]);
    }
}
