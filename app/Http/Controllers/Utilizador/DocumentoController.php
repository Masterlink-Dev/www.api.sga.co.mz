<?php

namespace App\Http\Controllers\Utilizador;

use App\Http\Controllers\Controller;
use App\Models\Cidadao;
use App\Models\Ficheiro;
use App\Models\User;
use App\Services\LeitorBI;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DocumentoController extends Controller
{
    // Categoria do ficheiro e coluna do cidadão onde fica ligado
    private const TIPOS = [
        'frente' => ['categoria' => 'documento_frente', 'coluna' => 'documento_frente_ficheiro_id'],
        'verso'  => ['categoria' => 'documento_verso',  'coluna' => 'documento_verso_ficheiro_id'],
        'foto'   => ['categoria' => 'foto_rosto',       'coluna' => 'foto_ficheiro_id'],
    ];

    private const MENSAGENS_UPLOAD = [
        'required' => 'Este campo é obrigatório.',
        'mimes'    => 'Formato inválido. Use JPG ou PNG.',
        'max'      => 'O ficheiro não pode ter mais de 8 MB.',
        'uploaded' => 'Não foi possível enviar o ficheiro (maior do que o limite do servidor).',
    ];

    // Estado dos documentos do próprio utilizador
    // estado: nao_submetido (ainda não enviou o BI) | verificado (enviado e confirmado automaticamente)
    // ("submetido" só aparece em contas antigas, enviadas antes da verificação automática)
    public function index(Request $request): JsonResponse
    {
        $cidadao = $request->user()->cidadaoOuCriar();

        return response()->json($this->resposta($cidadao));
    }

    // Recebe frente + verso do BI (e, se quiser, a foto), lê os dados, verifica e preenche o cidadão
    public function store(Request $request, LeitorBI $leitor): JsonResponse
    {
        $request->validate([
            'frente' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:8192'],
            'verso'  => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:8192'],
            'foto'   => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:8192'],
        ], self::MENSAGENS_UPLOAD);

        /** @var User $user */
        $user = $request->user();

        // 1) Ler o BI (antes de guardar o que quer que seja)
        try {
            $dados = $leitor->ler($request->file('frente'), $request->file('verso'));
        } catch (RuntimeException $e) {
            return response()->json(['mensagem' => $e->getMessage()], 503);
        }

        if ($dados === null) {
            return response()->json([
                'mensagem' => 'Não foi possível ler o BI. Fotografe a frente e o verso inteiros, direitos, com boa luz e sem reflexos, e envie outra vez.',
            ], 422);
        }

        // 1b) Verificação automática: só aceita um BI legível e dentro da validade
        //     (se falhar, nada é guardado e a app mostra o que corrigir)
        if ($erro = $this->problemaNoBi($dados)) {
            return response()->json(['mensagem' => $erro], 422);
        }

        // 2) Este BI já existe na base?
        $cidadao = $user->cidadaoOuCriar();
        $hash = Cidadao::hashDocumento($dados['numero_documento']);

        $outro = Cidadao::withTrashed()
            ->where('tipo_documento', 'bi')
            ->where('numero_documento_hash', $hash)
            ->where('id', '!=', $cidadao->id)
            ->first();

        if ($outro) {
            if ($outro->user_id || $outro->trashed()) {
                return response()->json(['mensagem' => 'Este BI já está associado a outra conta.'], 409);
            }

            if ($cidadao->numero_documento_hash) {
                return response()->json(['mensagem' => 'Esta conta já tem outro BI registado.'], 409);
            }

            // Registo criado ao balcão: liga-se a esta conta (e apaga-se o registo vazio do registo)
            $cidadao->forceDelete();
            $outro->user_id = $user->id;
            $cidadao = $outro;
        }

        // 3) Preencher o cidadão com o que foi lido
        $cidadao->fill($dados);
        $cidadao->numero_documento_hash = $hash;
        $cidadao->numero_documento_mascarado = LeitorBI::mascarar($dados['numero_documento']);
        $cidadao->save();

        // 3b) As visitas antigas com este BI passam a ser desta conta
        $cidadao->ligarVisitas();

        // 4) Guardar as imagens
        foreach (['frente', 'verso', 'foto'] as $tipo) {
            if ($request->hasFile($tipo)) {
                $this->guardarFicheiro($user, $cidadao, $tipo, $request->file($tipo));
            }
        }

        // 5) BI verificado: a partir daqui a secretaria já pode ver os documentos
        $this->marcarVerificado($cidadao);

        return response()->json(
            ['mensagem' => 'BI lido e verificado com êxito!'] + $this->resposta($cidadao->fresh()),
            201
        );
    }

    // Substitui UMA imagem já enviada (POST /documentos/frente | verso | foto, campo "ficheiro").
    // Frente ou verso: o BI é lido de novo (com o outro lado que já está guardado) e verificado outra vez.
    public function substituir(Request $request, LeitorBI $leitor, string $tipo): JsonResponse
    {
        $request->validate([
            'ficheiro' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:8192'],
        ], self::MENSAGENS_UPLOAD);

        /** @var User $user */
        $user = $request->user();
        $cidadao = $user->cidadaoOuCriar();
        $novo = $request->file('ficheiro');

        if (! $cidadao->documento_frente_ficheiro_id || ! $cidadao->documento_verso_ficheiro_id) {
            return response()->json(['mensagem' => 'Envie primeiro a frente e o verso do BI.'], 422);
        }

        // Fotografia: basta trocar a imagem
        if ($tipo === 'foto') {
            $this->guardarFicheiro($user, $cidadao, 'foto', $novo);
            $cidadao->foto_verificada = true;
            $cidadao->save();

            return response()->json(
                ['mensagem' => 'Fotografia substituída com êxito!'] + $this->resposta($cidadao->fresh())
            );
        }

        // Frente ou verso: junta a imagem nova com o outro lado já guardado e lê o BI outra vez
        $outroLado = $this->ficheiroGuardado($tipo === 'frente' ? $cidadao->documentoVerso : $cidadao->documentoFrente);
        if (! $outroLado) {
            return response()->json(['mensagem' => 'Não encontrámos o outro lado do BI. Envie a frente e o verso juntos.'], 422);
        }

        try {
            $dados = $tipo === 'frente' ? $leitor->ler($novo, $outroLado) : $leitor->ler($outroLado, $novo);
        } catch (RuntimeException $e) {
            return response()->json(['mensagem' => $e->getMessage()], 503);
        }

        if ($dados === null) {
            return response()->json([
                'mensagem' => 'Não foi possível ler o BI com a nova imagem. Fotografe o BI inteiro, direito, com boa luz e sem reflexos.',
            ], 422);
        }

        if ($erro = $this->problemaNoBi($dados)) {
            return response()->json(['mensagem' => $erro], 422);
        }

        // Tem de ser o mesmo BI; para um BI novo usa-se o envio completo (frente + verso)
        if (Cidadao::hashDocumento($dados['numero_documento']) !== $cidadao->numero_documento_hash) {
            return response()->json([
                'mensagem' => 'Esta imagem é de um BI diferente do registado. Se tem um BI novo, use "Tenho um BI novo" e envie a frente e o verso.',
            ], 422);
        }

        $cidadao->fill($dados);
        $cidadao->save();
        $this->guardarFicheiro($user, $cidadao, $tipo, $novo);
        $this->marcarVerificado($cidadao);

        return response()->json(
            ['mensagem' => 'Documento substituído e verificado com êxito!'] + $this->resposta($cidadao->fresh())
        );
    }

    // Entrega o ficheiro (só funciona com o link assinado e ainda válido)
    public function ficheiro(Ficheiro $ficheiro)
    {
        $disco = Storage::disk($ficheiro->disco);

        abort_unless($disco->exists($ficheiro->caminho), 404);

        return $disco->response($ficheiro->caminho, $ficheiro->nome_original, [
            'Content-Type' => $ficheiro->mime,
        ]);
    }

    // Regras da verificação automática. Devolve a mensagem do problema, ou null se o BI está bom.
    private function problemaNoBi(array $dados): ?string
    {
        $falta = [];
        if (empty($dados['nomes']) && empty($dados['apelidos'])) {
            $falta[] = 'o nome';
        }
        if (empty($dados['data_nascimento'])) {
            $falta[] = 'a data de nascimento';
        }
        if ($falta) {
            return 'Não conseguimos ler '.implode(' e ', $falta).' no BI. Fotografe a frente e o verso inteiros, direitos e com boa luz, e envie outra vez.';
        }

        if (Carbon::parse($dados['data_nascimento'])->isFuture()) {
            return 'A data de nascimento lida no BI não é válida. Envie fotografias mais nítidas.';
        }

        if (! empty($dados['documento_data_validade']) && Carbon::parse($dados['documento_data_validade'])->endOfDay()->isPast()) {
            return 'Este BI está fora da validade. Renove o BI e envie o novo.';
        }

        return null;
    }

    // Marca o BI como verificado (verificação automática, sem funcionário)
    private function marcarVerificado(Cidadao $cidadao): void
    {
        if (! in_array($cidadao->nivel_verificacao, ['presencial', 'oficial'])) {
            $cidadao->nivel_verificacao = 'documento';
        }
        $cidadao->verificado_em = now();
        $cidadao->verificado_por = null;
        $cidadao->foto_verificada = (bool) $cidadao->foto_ficheiro_id;
        $cidadao->save();
    }

    // Uma imagem já guardada no disco, como se tivesse acabado de ser enviada (para a voltar a ler)
    private function ficheiroGuardado(?Ficheiro $ficheiro): ?UploadedFile
    {
        if (! $ficheiro || ! Storage::disk($ficheiro->disco)->exists($ficheiro->caminho)) {
            return null;
        }

        return new UploadedFile(
            Storage::disk($ficheiro->disco)->path($ficheiro->caminho),
            $ficheiro->nome_original,
            $ficheiro->mime,
            null,
            true
        );
    }

    // O que a app precisa de saber: estado, imagens e dados lidos do BI
    private function resposta(Cidadao $cidadao): array
    {
        $submetido = $cidadao->documento_frente_ficheiro_id && $cidadao->documento_verso_ficheiro_id;

        if (! $submetido) {
            $estado = 'nao_submetido';
        } elseif (in_array($cidadao->nivel_verificacao, ['documento', 'presencial', 'oficial'])) {
            $estado = 'verificado';
        } else {
            $estado = 'submetido';
        }

        $dados = $submetido ? $this->dadosDoBi($cidadao) : [];

        return [
            'estado' => $estado,
            'dados' => $dados ?: null,
            'por_preencher' => $submetido ? array_values(array_diff(LeitorBI::CAMPOS, array_keys($dados))) : [],
            'documentos' => $cidadao->documentosParaApi(),
        ];
    }

    // Só os campos do BI que estão preenchidos (datas em AAAA-MM-DD)
    private function dadosDoBi(Cidadao $cidadao): array
    {
        $campos = [
            'numero_documento', 'numero_documento_mascarado', 'nomes', 'apelidos', 'nome_completo', 'sexo',
            'data_nascimento', 'naturalidade', 'nacionalidade', 'estado_civil', 'altura_cm', 'nome_pai',
            'nome_mae', 'morada', 'profissao', 'documento_emitido_em', 'documento_data_emissao',
            'documento_data_validade',
        ];

        $dados = [];

        foreach ($campos as $campo) {
            $valor = $cidadao->{$campo};

            if ($valor instanceof \DateTimeInterface) {
                $valor = $valor->format('Y-m-d');
            }

            if ($valor !== null && $valor !== '') {
                $dados[$campo] = $valor;
            }
        }

        return $dados;
    }

    // Guarda uma imagem no disco privado, regista-a em "ficheiros" e liga-a ao cidadão
    private function guardarFicheiro(User $user, Cidadao $cidadao, string $tipo, UploadedFile $upload): void
    {
        $cfg = self::TIPOS[$tipo];

        $mime = $upload->getMimeType();
        $tamanho = $upload->getSize();
        $nome = $upload->getClientOriginalName();

        // Disco privado: storage/app/private/cidadaos/<uuid>/
        $caminho = $upload->store("cidadaos/{$cidadao->uuid}", 'local');

        $novo = Ficheiro::create([
            'dono_type'     => Cidadao::class,
            'dono_id'       => $cidadao->id,
            'categoria'     => $cfg['categoria'],
            'disco'         => 'local',
            'caminho'       => $caminho,
            'nome_original' => $nome,
            'mime'          => $mime,
            'tamanho_bytes' => $tamanho,
            'hash_sha256'   => hash_file('sha256', Storage::disk('local')->path($caminho)),
            'privado'       => true,
            'enviado_por'   => $user->id,
        ]);

        $antigoId = $cidadao->{$cfg['coluna']};
        $cidadao->{$cfg['coluna']} = $novo->id;

        if ($tipo === 'foto') {
            $cidadao->foto_verificada = false; // nova foto: volta a ser marcada depois de guardada
        }

        $cidadao->save();

        // Apaga o ficheiro antigo (disco e base de dados)
        if ($antigoId && $antigo = Ficheiro::find($antigoId)) {
            Storage::disk($antigo->disco)->delete($antigo->caminho);
            $antigo->forceDelete();
        }
    }
}
