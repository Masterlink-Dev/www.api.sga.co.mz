<?php

namespace App\Http\Controllers\Secretaria;

use App\Http\Controllers\Controller;
use App\Models\Ficheiro;
use App\Models\User;
use App\Services\FormularioVisita;
use App\Support\Telefone;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class VisitaController extends Controller
{
    use ContextoSecretaria;

    // Quem sou eu + sectores e motivos para os menus do balcão
    public function me(Request $request): JsonResponse
    {
        $func = $this->funcionario($request);

        return response()->json([
            'nome'        => $request->user()->name,
            'email'       => $request->user()->email,
            'telefone'    => $request->user()->telefone,
            'cargo'       => $func->cargo,
            // Nome, sigla e logótipo da instituição que registou este funcionário (o menu mostra o logótipo)
            'instituicao' => [
                'nome'  => $func->instituicao,
                'sigla' => $func->sigla,
                'logo'  => $func->logo_ficheiro_id ? Ficheiro::find($func->logo_ficheiro_id)?->url() : null,
            ],
            'sectores'    => DB::table('sectores')
                ->where('instituicao_id', $func->instituicao_id)->where('activo', true)->whereNull('deleted_at')
                ->orderBy('ordem')->orderBy('nome')->get(['id', 'nome']),
            'motivos'     => DB::table('motivos_visita')
                ->where(fn ($q) => $q->whereNull('instituicao_id')->orWhere('instituicao_id', $func->instituicao_id))
                ->where('activo', true)->whereNull('deleted_at')
                ->orderBy('ordem')->orderBy('nome')->get(['id', 'nome', 'e_outro']),
            // O Passo 2 do "Registar entrada": os campos que o administrador definiu
            'formulario'  => FormularioVisita::campos($func->instituicao_id),
        ]);
    }

    // O próprio funcionário actualiza o seu perfil (nome, email e telemóvel)
    public function atualizarPerfil(Request $request): JsonResponse
    {
        $this->funcionario($request);
        $user = $request->user();

        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);

        $dados = $request->validate([
            'nome'     => ['required', 'string', 'min:3', 'max:150'],
            'email'    => ['nullable', 'required_without:telefone', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'telefone' => ['nullable', 'required_without:email', 'string', 'max:20'],
        ], [
            'nome.required'             => 'Escreva o seu nome.',
            'nome.min'                  => 'O nome é demasiado curto.',
            'email.required_without'    => 'Indique o email ou o telemóvel: é com um deles que entra no portal.',
            'telefone.required_without' => 'Indique o email ou o telemóvel: é com um deles que entra no portal.',
            'email.email'               => 'O email não é válido.',
            'email.unique'              => 'Este email já está a ser usado por outra conta.',
        ]);

        $telefone = null;
        if (! empty($dados['telefone'])) {
            $telefone = Telefone::normalizar($dados['telefone']);

            if (! $telefone) {
                return response()->json(['mensagem' => 'O telemóvel não é válido. Exemplo: 84 123 4567.'], 422);
            }

            if (User::where('telefone', $telefone)->where('id', '!=', $user->id)->exists()) {
                return response()->json(['mensagem' => 'Este telemóvel já está a ser usado por outra conta.'], 422);
            }
        }

        $user->name = trim($dados['nome']);
        $user->email = $dados['email'] ?: null;
        $user->telefone = $telefone;
        $user->save();

        return response()->json([
            'mensagem' => 'Perfil actualizado.',
            'nome'     => $user->name,
            'email'    => $user->email,
            'telefone' => $user->telefone,
        ]);
    }

    // REGISTAR ENTRADA
    public function entrada(Request $request): JsonResponse
    {
        $func = $this->funcionario($request);

        $dados = $request->validate([
            'numero'       => ['required', 'string'],
            'metodo'       => ['nullable', 'in:codigo,qr'],
            'respostas'    => ['nullable', 'array'],             // Passo 2: { chave: valor } (formulário do administrador)
            'motivo_outro' => ['nullable', 'string', 'max:255'], // quando o motivo escolhido é "Outro"
            'observacoes'  => ['nullable', 'string', 'max:1000'],
        ]);

        $conta = User::where('numero', $this->soDigitos($dados['numero']))->where('tipo', 0)->first();
        if (! $conta) {
            return response()->json(['mensagem' => 'Cidadão não encontrado.'], 404);
        }
        $cidadao = $conta->cidadaoOuCriar();

        // Acesso proibido nesta instituição
        $proibido = DB::table('restricoes_acesso')
            ->where('instituicao_id', $func->instituicao_id)->where('cidadao_id', $cidadao->id)
            ->where('tipo', 'proibido')->where('activa', true)
            ->where(fn ($q) => $q->whereNull('valido_ate')->orWhere('valido_ate', '>=', now()->toDateString()))
            ->first();
        if ($proibido) {
            return response()->json(['mensagem' => 'Entrada não permitida: ' . $proibido->motivo], 403);
        }

        // Já está dentro? (evita entradas duplicadas)
        $jaDentro = DB::table('visitas')
            ->where('instituicao_id', $func->instituicao_id)->where('cidadao_id', $cidadao->id)
            ->where('estado', 'em_curso')->whereNull('deleted_at')->exists();
        if ($jaDentro) {
            return response()->json(['mensagem' => 'Este visitante já tem uma entrada registada. Registe primeiro a saída.'], 409);
        }

        // Passo 2: respostas do formulário (422 com o erro junto de cada campo se faltar um obrigatório).
        // O BI e o telefone vêm da conta do cidadão (Passo 1).
        $form = FormularioVisita::validar($func->instituicao_id, $dados['respostas'] ?? [], $dados['motivo_outro'] ?? null, [
            'documento_numero'   => $cidadao->numero_documento,
            'telefone_visitante' => $cidadao->telefone,
        ]);

        $uuid = (string) Str::uuid();
        $agora = now();

        $this->gravarVisita(array_merge([
            'uuid'                  => $uuid,
            'instituicao_id'        => $func->instituicao_id,
            'unidade_id'            => $func->unidade_id,
            'cidadao_id'            => $cidadao->id,
            'nome_visitante'        => $cidadao->nome_completo ?: $conta->name,
            'documento_numero'      => $cidadao->numero_documento,
            'telefone_visitante'    => $cidadao->telefone,
            'sexo_visitante'        => $cidadao->sexo,
            'metodo_identificacao'  => $dados['metodo'] ?? 'codigo',
            'entrada_em'            => $agora,
            'entrada_registada_por' => $request->user()->id,
            'observacoes'           => $dados['observacoes'] ?? null, // caixa fixa; o campo do formulário, se existir, ganha
            'estado'                => 'em_curso',
            'created_at'            => $agora,
            'updated_at'            => $agora,
        ], $form['colunas']), $form);

        return response()->json([
            'mensagem' => 'Entrada registada.',
            'visita'   => $this->visitaPorUuid($uuid),
        ], 201);
    }

    // REGISTAR ENTRADA MANUAL — visitante sem conta na app: a secretaria escreve os dados do BI
    public function entradaManual(Request $request): JsonResponse
    {
        $func = $this->funcionario($request);

        // O BI chega sem espaços e em maiúsculas: 12 algarismos + 1 letra
        $request->merge(['bi' => strtoupper(preg_replace('/\s+/', '', (string) $request->input('bi')))]);

        $dados = $request->validate([
            'nome'             => ['required', 'string', 'min:3', 'max:150'],
            'bi'               => ['required', 'string', 'regex:/^\d{12}[A-Z]$/'],
            'telefone'         => ['nullable', 'string', 'max:20'],
            'data_nascimento'  => ['nullable', 'date', 'before:today'],
            'sexo'             => ['nullable', 'in:F,M'],
            'naturalidade'     => ['nullable', 'string', 'max:100'],
            'nacionalidade'    => ['nullable', 'string', 'max:60'],
            'local_emissao'    => ['nullable', 'string', 'max:100'],
            'data_emissao'     => ['nullable', 'date'],
            'data_validade'    => ['nullable', 'date', 'after_or_equal:data_emissao'],
            'morada'           => ['nullable', 'string', 'max:255'],
            'autorizou'        => ['accepted'],
            'respostas'        => ['nullable', 'array'],             // Passo 2: { chave: valor } (formulário do administrador)
            'motivo_outro'     => ['nullable', 'string', 'max:255'], // quando o motivo escolhido é "Outro"
            'observacoes'      => ['nullable', 'string', 'max:1000'],
        ], [
            'nome.required'                => 'Escreva o nome completo do visitante.',
            'nome.min'                     => 'O nome é demasiado curto.',
            'bi.required'                  => 'Escreva o número do BI.',
            'bi.regex'                     => 'O número do BI deve ter exactamente 12 algarismos seguidos de 1 letra (13 caracteres).',
            'autorizou.accepted'           => 'Confirme que o visitante autorizou o registo dos seus dados.',
            'data_nascimento.before'       => 'A data de nascimento tem de ser anterior a hoje.',
            'data_validade.after_or_equal' => 'A data de validade não pode ser anterior à data de emissão.',
            '*.date'                       => 'Uma das datas não é válida.',
        ]);

        // O número do BI guarda-se completo, sem espaços e em maiúsculas
        $bi = strtoupper(preg_replace('/\s+/', '', $dados['bi']));

        $jaDentro = DB::table('visitas')
            ->where('instituicao_id', $func->instituicao_id)->where('documento_numero', $bi)
            ->where('estado', 'em_curso')->whereNull('deleted_at')->exists();
        if ($jaDentro) {
            return response()->json(['mensagem' => 'Já existe uma entrada em curso com este número de BI. Registe primeiro a saída.'], 409);
        }

        // Passo 2: respostas do formulário. O BI e o telefone vêm do que se escreveu no Passo 1.
        $form = FormularioVisita::validar($func->instituicao_id, $dados['respostas'] ?? [], $dados['motivo_outro'] ?? null, [
            'documento_numero'   => $bi,
            'telefone_visitante' => $dados['telefone'] ?? null,
        ]);

        $uuid = (string) Str::uuid();
        $agora = now();

        $this->gravarVisita(array_merge([
            'uuid'                  => $uuid,
            'instituicao_id'        => $func->instituicao_id,
            'unidade_id'            => $func->unidade_id,
            'nome_visitante'        => trim($dados['nome']),
            'documento_numero'      => $bi,
            'telefone_visitante'    => $dados['telefone'] ?? null,
            'sexo_visitante'        => $dados['sexo'] ?? null,
            'idade_visitante'       => ! empty($dados['data_nascimento']) ? Carbon::parse($dados['data_nascimento'])->age : null,
            'dados_documento'       => json_encode([
                'data_nascimento' => $dados['data_nascimento'] ?? null,
                'naturalidade'    => $dados['naturalidade'] ?? null,
                'nacionalidade'   => $dados['nacionalidade'] ?? null,
                'local_emissao'   => $dados['local_emissao'] ?? null,
                'data_emissao'    => $dados['data_emissao'] ?? null,
                'data_validade'   => $dados['data_validade'] ?? null,
                'morada'          => $dados['morada'] ?? null,
            ], JSON_UNESCAPED_UNICODE),
            'metodo_identificacao'  => 'bi_manual',
            'entrada_em'            => $agora,
            'entrada_registada_por' => $request->user()->id,
            'observacoes'           => $dados['observacoes'] ?? null, // caixa fixa; o campo do formulário, se existir, ganha
            'estado'                => 'em_curso',
            'created_at'            => $agora,
            'updated_at'            => $agora,
        ], $form['colunas']), $form);

        return response()->json([
            'mensagem' => 'Entrada registada.',
            'visita'   => $this->visitaPorUuid($uuid),
        ], 201);
    }

    // Grava a visita e as respostas livres do formulário (respostas_visita) de uma só vez
    private function gravarVisita(array $visita, array $form): void
    {
        DB::transaction(function () use ($visita, $form) {
            $id = DB::table('visitas')->insertGetId($visita);
            FormularioVisita::gravarRespostas($id, $form['livres'], $form['versao']);
        });
    }

    // REGISTAR SAÍDA — por uuid da visita (lista "Dentro agora") ou pelo número do cidadão
    public function saida(Request $request): JsonResponse
    {
        $func = $this->funcionario($request);

        $dados = $request->validate([
            'visita' => ['required_without:numero', 'nullable', 'string'],
            'numero' => ['required_without:visita', 'nullable', 'string'],
        ]);

        $consulta = DB::table('visitas')
            ->where('instituicao_id', $func->instituicao_id)
            ->where('estado', 'em_curso')->whereNull('deleted_at');

        if (! empty($dados['visita'])) {
            $consulta->where('uuid', $dados['visita']);
        } else {
            $conta = User::where('numero', $this->soDigitos($dados['numero']))->where('tipo', 0)->first();
            $cidadaoId = $conta ? DB::table('cidadaos')->where('user_id', $conta->id)->value('id') : null;
            $consulta->where('cidadao_id', $cidadaoId ?? 0);
        }

        $visita = $consulta->first();
        if (! $visita) {
            return response()->json(['mensagem' => 'Não há entrada em curso para este visitante.'], 404);
        }

        $agora = now();
        $minutos = max(0, (int) round(Carbon::parse($visita->entrada_em)->diffInMinutes($agora)));

        DB::table('visitas')->where('id', $visita->id)->update([
            'saida_em'            => $agora,
            'saida_registada_por' => $request->user()->id,
            'tipo_saida'          => 'manual',
            'duracao_minutos'     => $minutos,
            'estado'              => 'concluida',
            'updated_at'          => $agora,
        ]);

        return response()->json([
            'mensagem' => 'Saída registada.',
            'visita'   => $this->visitaPorUuid($visita->uuid),
        ]);
    }

    // Quem está dentro neste momento
    public function dentroAgora(Request $request): JsonResponse
    {
        $func = $this->funcionario($request);

        $lista = $this->consultaVisitas($func)
            ->where('v.estado', 'em_curso')
            ->orderBy('v.entrada_em')
            ->get()
            ->map(fn ($v) => $this->formatar($v));

        return response()->json(['total' => $lista->count(), 'visitas' => $lista]);
    }

    // Histórico com filtros: ?de=2026-10-01&ate=2026-10-19&estado=concluida&sector_id=1&q=maria
    public function index(Request $request): JsonResponse
    {
        $func = $this->funcionario($request);

        $f = $request->validate([
            'de'        => ['nullable', 'date'],
            'ate'       => ['nullable', 'date'],
            'estado'    => ['nullable', 'in:em_curso,concluida,fechada_automaticamente,cancelada'],
            'sector_id' => ['nullable', 'integer'],
            'q'         => ['nullable', 'string', 'max:100'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $consulta = $this->consultaVisitas($func)->orderByDesc('v.entrada_em');

        if (! empty($f['de']))        $consulta->where('v.entrada_em', '>=', Carbon::parse($f['de'])->startOfDay());
        if (! empty($f['ate']))       $consulta->where('v.entrada_em', '<=', Carbon::parse($f['ate'])->endOfDay());
        if (! empty($f['estado']))    $consulta->where('v.estado', $f['estado']);
        if (! empty($f['sector_id'])) $consulta->where('v.sector_id', $f['sector_id']);
        if (! empty($f['q']))         $consulta->where('v.nome_visitante', 'like', '%' . $f['q'] . '%');

        $pagina = $consulta->paginate($f['per_page'] ?? 20);
        $pagina->getCollection()->transform(fn ($v) => $this->formatar($v));

        return response()->json($pagina);
    }

    // Números do dia (para o painel)
    public function resumo(Request $request): JsonResponse
    {
        $func = $this->funcionario($request);

        $base = fn () => DB::table('visitas')
            ->where('instituicao_id', $func->instituicao_id)->whereNull('deleted_at');

        $hoje = fn () => $base()->whereBetween('entrada_em', [now()->startOfDay(), now()->endOfDay()]);

        // Entradas por hora (07h, 08h...) agrupadas aqui, para funcionar em qualquer base de dados
        $porHora = [];
        foreach ($hoje()->pluck('entrada_em') as $entrada) {
            $h = (int) Carbon::parse($entrada)->format('G');
            $porHora[$h] = ($porHora[$h] ?? 0) + 1;
        }
        ksort($porHora);

        $porSector = DB::table('visitas')
            ->where('visitas.instituicao_id', $func->instituicao_id)->whereNull('visitas.deleted_at')
            ->whereBetween('visitas.entrada_em', [now()->startOfDay(), now()->endOfDay()])
            ->leftJoin('sectores as s', 's.id', '=', 'visitas.sector_id')
            ->selectRaw("COALESCE(s.nome, 'Sem sector') as sector, COUNT(*) as total")
            ->groupBy('s.nome')->orderByDesc('total')->get()
            ->map(fn ($r) => ['sector' => $r->sector, 'valor' => (int) $r->total]);

        return response()->json([
            'entradas_hoje'  => $hoje()->count(),
            'entradas_ontem' => $base()->whereBetween('entrada_em', [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()])->count(),
            'saidas_hoje'    => $hoje()->where('estado', 'concluida')->count(),
            'dentro_agora'   => $base()->where('estado', 'em_curso')->count(),
            'media_minutos'  => (int) round($hoje()->where('estado', 'concluida')->avg('duracao_minutos') ?? 0),
            'via_codigo'     => $hoje()->whereIn('metodo_identificacao', ['codigo', 'qr'])->count(),
            'via_manual'     => $hoje()->whereNotIn('metodo_identificacao', ['codigo', 'qr'])->count(),
            'por_hora'       => collect($porHora)->map(fn ($v, $h) => ['hora' => (int) $h, 'valor' => $v])->values(),
            'por_sector'     => $porSector,
        ]);
    }

    // ---------- auxiliares ----------

    private function consultaVisitas(object $func)
    {
        return DB::table('visitas as v')
            ->leftJoin('cidadaos as c', 'c.id', '=', 'v.cidadao_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->leftJoin('sectores as s', 's.id', '=', 'v.sector_id')
            ->leftJoin('motivos_visita as m', 'm.id', '=', 'v.motivo_id')
            ->where('v.instituicao_id', $func->instituicao_id)
            ->whereNull('v.deleted_at')
            ->select('v.uuid', 'v.nome_visitante', 'u.numero', 's.nome as sector', 'm.nome as motivo',
                'v.motivo_outro', 'v.pessoa_a_visitar', 'v.entrada_em', 'v.saida_em', 'v.duracao_minutos', 'v.estado',
                'v.metodo_identificacao as via', DB::raw('COALESCE(v.documento_numero, v.documento_mascarado) as documento'));
    }

    private function visitaPorUuid(string $uuid): array
    {
        $v = DB::table('visitas as v')
            ->leftJoin('cidadaos as c', 'c.id', '=', 'v.cidadao_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->leftJoin('sectores as s', 's.id', '=', 'v.sector_id')
            ->leftJoin('motivos_visita as m', 'm.id', '=', 'v.motivo_id')
            ->where('v.uuid', $uuid)
            ->select('v.uuid', 'v.nome_visitante', 'u.numero', 's.nome as sector', 'm.nome as motivo',
                'v.motivo_outro', 'v.pessoa_a_visitar', 'v.entrada_em', 'v.saida_em', 'v.duracao_minutos', 'v.estado',
                'v.metodo_identificacao as via', DB::raw('COALESCE(v.documento_numero, v.documento_mascarado) as documento'))
            ->first();

        return $this->formatar($v);
    }

    private function formatar(object $v): array
    {
        $entrada = Carbon::parse($v->entrada_em);
        $fim = $v->saida_em ? Carbon::parse($v->saida_em) : now();

        return [
            'id'               => $v->uuid,
            'nome'             => $v->nome_visitante,
            'numero'           => $v->numero,
            'sector'           => $v->sector,
            'motivo'           => $v->motivo ?: $v->motivo_outro,
            'pessoa_a_visitar' => $v->pessoa_a_visitar,
            'estado'           => $v->estado,
            'via'              => $v->via,
            'documento'        => $v->documento,
            'entrada_em'       => $entrada->toIso8601String(),
            'saida_em'         => $v->saida_em ? Carbon::parse($v->saida_em)->toIso8601String() : null,
            'minutos'          => $v->duracao_minutos ?? max(0, (int) round($entrada->diffInMinutes($fim))),
        ];
    }
}
