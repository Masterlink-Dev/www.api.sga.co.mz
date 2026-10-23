<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ficheiro;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

// O administrador regista a PRÓPRIA instituição (Identificação → Contactos → Horário) e mantém-na actualizada.
// A instituição é sempre a deste administrador (instituicoes.administrador_id): nunca vem do pedido.
class InstituicaoController extends Controller
{
    // Dias como o portal os envia → dia_semana (1 = segunda ... 7 = domingo)
    private const DIAS = ['Seg' => 1, 'Ter' => 2, 'Qua' => 3, 'Qui' => 4, 'Sex' => 5, 'Sáb' => 6, 'Dom' => 7];

    // Logótipo: tipos aceites e extensão com que se guarda
    private const TIPOS_LOGO = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
    private const LOGO_MAX_BYTES = 1024 * 1024; // 1 MB

    // GET /admin/instituicao — os dados no formato do formulário do portal (vazios se ainda não registou)
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->paraPortal($this->daConta($request->user())));
    }

    // PUT /admin/instituicao — regista (1.ª vez) ou actualiza
    public function update(Request $request): JsonResponse
    {
        $admin = $request->user();
        $inst = $this->daConta($admin);

        // Limpa o que o portal envia antes de validar (espaços, maiúsculas, NUIT só com algarismos)
        $request->merge([
            'sigla' => mb_strtoupper(trim((string) $request->input('sigla'))),
            'email' => mb_strtolower(trim((string) $request->input('email'))) ?: null,
            'nuit'  => preg_replace('/\D+/', '', (string) $request->input('nuit')) ?: null,
        ]);

        $dados = $request->validate([
            // Passo 1 · Identificação
            'nome'      => ['required', 'string', 'max:255'],
            'sigla'     => ['required', 'string', 'max:12'],
            'tipo'      => ['nullable', 'string', Rule::exists('tipos_instituicao', 'nome')],
            'nuit'      => ['nullable', 'digits:9', Rule::unique('instituicoes', 'nuit')->ignore($inst?->id)],
            'descricao' => ['nullable', 'string', 'max:2000'],
            'logo'      => ['nullable', 'string'],
            // Passo 2 · Contactos
            'provincia' => ['nullable', 'string', Rule::exists('provincias', 'nome')],
            'endereco'  => ['nullable', 'string', 'max:255'],
            'telefone'  => ['nullable', 'string', 'max:20', 'regex:/^(?:\D*\d){9,}\D*$/'],
            'email'     => ['nullable', 'email', 'max:255'],
            // Passo 3 · Horário de atendimento
            'abertura'  => ['required', 'date_format:H:i'],
            'fecho'     => ['required', 'date_format:H:i', 'after:abertura'],
            'dias'      => ['required', 'array', 'min:1'],
            'dias.*'    => ['string', Rule::in(array_keys(self::DIAS))],
        ], [
            'nome.required'     => 'Escreva o nome oficial da instituição.',
            'sigla.required'    => 'Escreva a sigla (ex.: MISAU).',
            'sigla.max'         => 'A sigla tem no máximo 12 caracteres.',
            'tipo.exists'       => 'Escolha um tipo de instituição da lista.',
            'nuit.digits'       => 'O NUIT tem 9 algarismos.',
            'nuit.unique'       => 'Já existe uma instituição com este NUIT.',
            'provincia.exists'  => 'Escolha uma província da lista.',
            'telefone.regex'    => 'O telefone tem de ter pelo menos 9 algarismos.',
            'email.email'       => 'Este email não parece válido.',
            'abertura.required' => 'Indique a hora de abertura e a de fecho.',
            'fecho.required'    => 'Indique a hora de abertura e a de fecho.',
            'fecho.after'       => 'A hora de fecho tem de ser depois da abertura.',
            'dias.required'     => 'Escolha pelo menos um dia de atendimento.',
            'dias.min'          => 'Escolha pelo menos um dia de atendimento.',
            '*.date_format'     => 'Use o formato HH:MM (ex.: 07:30).',
        ]);

        // O logótipo chega como data:image/...;base64 (nova imagem), o URL actual (sem mudança) ou "" (remover)
        $logoNovo = null;
        if (! empty($dados['logo']) && str_starts_with($dados['logo'], 'data:')) {
            $logoNovo = $this->lerDataUrl($dados['logo']);
            if (is_string($logoNovo)) {
                return response()->json(['errors' => ['logo' => [$logoNovo]], 'mensagem' => $logoNovo], 422);
            }
        }

        $nova = ! $inst;
        $agora = now();

        $valores = [
            'nome'                => trim($dados['nome']),
            'sigla'               => $dados['sigla'],
            'tipo_instituicao_id' => isset($dados['tipo']) ? DB::table('tipos_instituicao')->where('nome', $dados['tipo'])->value('id') : null,
            'nuit'                => $dados['nuit'] ?? null,
            'descricao'           => $dados['descricao'] ?? null,
            'provincia_id'        => isset($dados['provincia']) ? DB::table('provincias')->where('nome', $dados['provincia'])->value('id') : null,
            'endereco'            => $dados['endereco'] ?? null,
            'telefone'            => isset($dados['telefone']) ? trim($dados['telefone']) : null,
            'email'               => $dados['email'] ?? null,
            'updated_at'          => $agora,
        ];

        $id = DB::transaction(function () use ($inst, $admin, $valores, $dados, $agora) {
            if ($inst) {
                DB::table('instituicoes')->where('id', $inst->id)->update($valores);
                $id = $inst->id;
            } else {
                $id = DB::table('instituicoes')->insertGetId($valores + [
                    'uuid'             => (string) Str::uuid(),
                    'administrador_id' => $admin->id,
                    'slug'             => $this->slugLivre($valores['sigla']),
                    'estado'           => 'activa',
                    'registada_em'     => $agora, // a partir daqui o Super administrador vê-a na lista
                    'created_at'       => $agora,
                ]);

                // O que cada instituição precisa desde o início: definições por omissão e o formulário de visita (vazio)
                DB::table('definicoes_instituicao')->insert(['instituicao_id' => $id, 'created_at' => $agora, 'updated_at' => $agora]);
                DB::table('formularios')->insert([
                    'uuid' => (string) Str::uuid(), 'instituicao_id' => $id, 'finalidade' => 'registo_visita',
                    'nome' => 'Registar entrada', 'created_at' => $agora, 'updated_at' => $agora,
                ]);
            }

            // Horário: uma linha por dia escolhido (o horário geral da instituição, sem unidade)
            DB::table('horarios_atendimento')->where('instituicao_id', $id)->whereNull('unidade_id')->delete();
            foreach (array_unique($dados['dias']) as $dia) {
                DB::table('horarios_atendimento')->insert([
                    'instituicao_id' => $id,
                    'dia_semana'     => self::DIAS[$dia],
                    'abre_as'        => $dados['abertura'],
                    'fecha_as'       => $dados['fecho'],
                    'created_at'     => $agora,
                    'updated_at'     => $agora,
                ]);
            }

            return $id;
        });

        $inst = DB::table('instituicoes')->where('id', $id)->first();

        if ($logoNovo) {
            $this->guardarLogo($inst, $logoNovo['conteudo'], $logoNovo['mime'], $admin);
        } elseif (array_key_exists('logo', $dados) && ($dados['logo'] ?? '') === '') {
            $this->apagarLogo($inst);
        }

        $this->auditar($request, $nova ? 'registar_instituicao' : 'actualizar_instituicao', $id, $valores['nome']);

        return response()->json([
            'mensagem'    => $nova ? 'Instituição registada.' : 'Alterações guardadas.',
            'instituicao' => $this->paraPortal($this->daConta($admin)),
        ], $nova ? 201 : 200);
    }

    // POST /admin/instituicao/logo — alternativa em multipart (campo "logo")
    public function logo(Request $request): JsonResponse
    {
        $inst = $this->daConta($request->user());
        if (! $inst) {
            return response()->json(['mensagem' => 'Registe primeiro a instituição.'], 422);
        }

        $request->validate([
            'logo' => ['required', 'file', 'mimetypes:' . implode(',', array_keys(self::TIPOS_LOGO)), 'max:1024'],
        ], [
            'logo.required'  => 'Escolha uma imagem.',
            'logo.mimetypes' => 'Use uma imagem PNG, JPG, WebP ou SVG.',
            'logo.max'       => 'A imagem tem mais de 1 MB.',
        ]);

        $ficheiro = $request->file('logo');
        $this->guardarLogo($inst, $ficheiro->get(), $ficheiro->getMimeType(), $request->user());

        return response()->json(['logo' => $this->urlLogo($this->daConta($request->user()))]);
    }

    // A instituição deste administrador (null = ainda não registou)
    private function daConta(User $admin): ?object
    {
        return DB::table('instituicoes')->where('administrador_id', $admin->id)->whereNull('deleted_at')->first();
    }

    // Formato que o portal usa (o mesmo objecto que o formulário de 3 passos preenche)
    private function paraPortal(?object $inst): array
    {
        if (! $inst) {
            return [
                'registada' => false, 'nome' => '', 'sigla' => '', 'tipo' => '', 'nuit' => '', 'provincia' => '',
                'descricao' => '', 'logo' => '', 'endereco' => '', 'telefone' => '', 'email' => '',
                'abertura' => '', 'fecho' => '', 'dias' => [],
            ];
        }

        $horarios = DB::table('horarios_atendimento')
            ->where('instituicao_id', $inst->id)->whereNull('unidade_id')->where('activo', true)
            ->orderBy('dia_semana')->get();
        $nomesDias = array_flip(self::DIAS);

        return [
            'registada' => true,
            'id'        => $inst->uuid,
            'estado'    => $inst->estado,
            'nome'      => $inst->nome,
            'sigla'     => $inst->sigla,
            'tipo'      => $inst->tipo_instituicao_id ? (string) DB::table('tipos_instituicao')->where('id', $inst->tipo_instituicao_id)->value('nome') : '',
            'nuit'      => $inst->nuit ?? '',
            'provincia' => $inst->provincia_id ? (string) DB::table('provincias')->where('id', $inst->provincia_id)->value('nome') : '',
            'descricao' => $inst->descricao ?? '',
            'logo'      => $this->urlLogo($inst),
            'endereco'  => $inst->endereco ?? '',
            'telefone'  => $inst->telefone ?? '',
            'email'     => $inst->email ?? '',
            'abertura'  => $horarios->isNotEmpty() ? substr($horarios->first()->abre_as, 0, 5) : '',
            'fecho'     => $horarios->isNotEmpty() ? substr($horarios->first()->fecha_as, 0, 5) : '',
            'dias'      => $horarios->map(fn ($h) => $nomesDias[$h->dia_semana])->values(),
        ];
    }

    // Link assinado (30 min) para o <img> do portal; "" se não houver logótipo
    private function urlLogo(?object $inst): string
    {
        $ficheiro = $inst?->logo_ficheiro_id ? Ficheiro::find($inst->logo_ficheiro_id) : null;

        return $ficheiro ? $ficheiro->url() : '';
    }

    // Lê um data URL (data:image/png;base64,...). Devolve ['conteudo', 'mime'] ou a mensagem de erro.
    private function lerDataUrl(string $dataUrl): array|string
    {
        if (! preg_match('#^data:([a-z/+.-]+);base64,(.+)$#is', $dataUrl, $m) || ! isset(self::TIPOS_LOGO[strtolower($m[1])])) {
            return 'Use uma imagem PNG, JPG, WebP ou SVG.';
        }

        $conteudo = base64_decode($m[2], true);
        if ($conteudo === false) {
            return 'Não foi possível ler a imagem. Escolha-a de novo.';
        }
        if (strlen($conteudo) > self::LOGO_MAX_BYTES) {
            return 'A imagem tem mais de 1 MB.';
        }

        return ['conteudo' => $conteudo, 'mime' => strtolower($m[1])];
    }

    // Guarda o logótipo no disco, regista-o em "ficheiros" e troca-o na instituição (o antigo é apagado)
    private function guardarLogo(object $inst, string $conteudo, string $mime, User $por): void
    {
        $caminho = "instituicoes/{$inst->uuid}/logo-" . Str::random(8) . '.' . self::TIPOS_LOGO[$mime];
        Storage::disk('local')->put($caminho, $conteudo);

        $novo = Ficheiro::create([
            'dono_type'     => 'instituicoes', // não há modelo Instituicao: o dono é a tabela
            'dono_id'       => $inst->id,
            'categoria'     => 'logotipo',
            'disco'         => 'local',
            'caminho'       => $caminho,
            'nome_original' => 'logo-' . Str::slug($inst->sigla) . '.' . self::TIPOS_LOGO[$mime],
            'mime'          => $mime,
            'tamanho_bytes' => strlen($conteudo),
            'hash_sha256'   => hash('sha256', $conteudo),
            'privado'       => false,
            'enviado_por'   => $por->id,
        ]);

        DB::table('instituicoes')->where('id', $inst->id)->update(['logo_ficheiro_id' => $novo->id, 'updated_at' => now()]);

        $this->apagarFicheiro($inst->logo_ficheiro_id);
    }

    private function apagarLogo(object $inst): void
    {
        if (! $inst->logo_ficheiro_id) {
            return;
        }
        DB::table('instituicoes')->where('id', $inst->id)->update(['logo_ficheiro_id' => null, 'updated_at' => now()]);
        $this->apagarFicheiro($inst->logo_ficheiro_id);
    }

    private function apagarFicheiro(?int $id): void
    {
        if ($id && $antigo = Ficheiro::find($id)) {
            Storage::disk($antigo->disco)->delete($antigo->caminho);
            $antigo->forceDelete();
        }
    }

    // A sigla dá o endereço curto da instituição (ex.: "misau"); se já existir, acrescenta -2, -3...
    private function slugLivre(string $sigla): string
    {
        $base = Str::slug($sigla) ?: 'instituicao';
        $slug = $base;
        for ($n = 2; DB::table('instituicoes')->where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    // Fica registado quem fez o quê (tabela registos_auditoria)
    private function auditar(Request $request, string $accao, int $instituicaoId, string $nome): void
    {
        DB::table('registos_auditoria')->insert([
            'user_id'        => $request->user()->id,
            'instituicao_id' => $instituicaoId,
            'accao'          => $accao,
            'entidade_type'  => 'instituicoes',
            'entidade_id'    => $instituicaoId,
            'descricao'      => $nome,
            'ip'             => $request->ip(),
            'user_agent'     => $request->userAgent(),
            'url'            => $request->fullUrl(),
        ]);
    }
}
