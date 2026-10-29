<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\Suporte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// O super administrador muda o que a app mostra no ecrã "Suporte":
// os contactos da empresa, as perguntas frequentes e os termos e condições (PDF).
// Todas as respostas devolvem { contactos, perguntas, termos } já actualizados.
class SuporteController extends Controller
{
    // GET /superadmin/suporte
    public function index(): JsonResponse
    {
        return $this->tudo();
    }

    // POST /superadmin/suporte/termos  (multipart: "ficheiro" PDF até 10 MB) → substitui o anterior
    public function termos(Request $request): JsonResponse
    {
        $request->validate([
            'ficheiro' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ], [
            'ficheiro.required' => 'Escolha o ficheiro PDF dos termos e condições.',
            'ficheiro.mimes'    => 'Os termos têm de estar em PDF.',
            'ficheiro.max'      => 'O PDF pode ter no máximo 10 MB.',
            'ficheiro.uploaded' => 'O envio falhou. O PDF pode ter no máximo 10 MB.',
        ]);

        $pdf = $request->file('ficheiro');
        Suporte::guardarTermos($pdf, $request->user()->id);
        $this->auditar($request, 'carregar_termos', $pdf->getClientOriginalName());

        return $this->tudo('Termos e condições actualizados. A app já mostra a versão nova.');
    }

    // PUT /superadmin/suporte/contactos  { empresa, telefone, whatsapp?, email?, morada?, horario?, site? }
    public function contactos(Request $request): JsonResponse
    {
        $regras = [];
        foreach (Suporte::CAMPOS as $campo => [, $max, $obrigatorio]) {
            $regras[$campo] = [$obrigatorio ? 'required' : 'nullable', 'string', "max:$max"];
        }
        $regras['email'][] = 'email';

        $dados = $request->validate($regras, [
            'empresa.required'  => 'Escreva o nome da empresa.',
            'telefone.required' => 'Escreva o telefone de apoio.',
            'email.email'       => 'Escreva um email válido.',
            'max'               => 'O texto é demasiado longo.',
        ]);

        Suporte::guardarContactos($dados);
        $this->auditar($request, 'editar_contactos_suporte', 'Contactos de suporte');

        return $this->tudo('Contactos actualizados.');
    }

    // POST /superadmin/suporte/perguntas  { pergunta, resposta, categoria?, activo? }  (fica no fim da lista)
    public function criar(Request $request): JsonResponse
    {
        $dados = $this->validarPergunta($request);

        DB::table('perguntas_frequentes')->insert([
            ...$dados,
            'publico'    => 'cidadao',
            'ordem'      => (int) DB::table('perguntas_frequentes')->max('ordem') + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->auditar($request, 'criar_pergunta_frequente', $dados['pergunta']);

        return $this->tudo('Pergunta acrescentada.', 201);
    }

    // PUT /superadmin/suporte/perguntas/{id}  { pergunta?, resposta?, categoria?, activo? }
    public function actualizar(Request $request, int $id): JsonResponse
    {
        if (! DB::table('perguntas_frequentes')->where('id', $id)->exists()) {
            return response()->json(['mensagem' => 'Pergunta não encontrada.'], 404);
        }

        $dados = $this->validarPergunta($request, parcial: true);
        DB::table('perguntas_frequentes')->where('id', $id)->update([...$dados, 'updated_at' => now()]);

        return $this->tudo('Pergunta actualizada.');
    }

    // POST /superadmin/suporte/perguntas/{id}/mover  { direccao: cima | baixo }  → troca com a vizinha
    public function mover(Request $request, int $id): JsonResponse
    {
        $direccao = $request->validate(['direccao' => ['required', 'in:cima,baixo']])['direccao'];

        $ids = DB::table('perguntas_frequentes')->orderBy('ordem')->orderBy('id')->pluck('id')->all();
        $i = array_search($id, $ids, true);
        if ($i === false) {
            return response()->json(['mensagem' => 'Pergunta não encontrada.'], 404);
        }

        $j = $direccao === 'cima' ? $i - 1 : $i + 1;
        if (isset($ids[$j])) {
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            // Regrava a ordem de todas (corrige também ordens repetidas)
            DB::transaction(function () use ($ids) {
                foreach ($ids as $ordem => $pid) {
                    DB::table('perguntas_frequentes')->where('id', $pid)->update(['ordem' => $ordem + 1]);
                }
            });
        }

        return $this->tudo();
    }

    // DELETE /superadmin/suporte/perguntas/{id}
    public function remover(Request $request, int $id): JsonResponse
    {
        $pergunta = DB::table('perguntas_frequentes')->where('id', $id)->value('pergunta');
        if ($pergunta === null) {
            return response()->json(['mensagem' => 'Pergunta não encontrada.'], 404);
        }

        DB::table('perguntas_frequentes')->where('id', $id)->delete();
        $this->auditar($request, 'remover_pergunta_frequente', $pergunta);

        return $this->tudo('Pergunta removida.');
    }

    private function validarPergunta(Request $request, bool $parcial = false): array
    {
        $obrigatorio = $parcial ? 'sometimes' : 'required';

        $dados = $request->validate([
            'pergunta'  => [$obrigatorio, 'string', 'min:5', 'max:255'],
            'resposta'  => [$obrigatorio, 'string', 'min:5', 'max:2000'],
            'categoria' => ['nullable', 'string', 'max:60'],
            'activo'    => ['sometimes', 'boolean'],
        ], [
            'pergunta.required' => 'Escreva a pergunta.',
            'pergunta.min'      => 'A pergunta é demasiado curta.',
            'resposta.required' => 'Escreva a resposta.',
            'resposta.min'      => 'A resposta é demasiado curta.',
            'resposta.max'      => 'A resposta pode ter no máximo 2000 caracteres.',
        ]);

        foreach (['pergunta', 'resposta', 'categoria'] as $campo) {
            if (array_key_exists($campo, $dados)) {
                $dados[$campo] = trim((string) $dados[$campo]) ?: null;
            }
        }

        return $dados;
    }

    private function tudo(?string $mensagem = null, int $estado = 200): JsonResponse
    {
        return response()->json(array_filter([
            'mensagem'  => $mensagem,
            'contactos' => Suporte::contactos(),
            'perguntas' => Suporte::perguntas(),
        ], fn ($v) => $v !== null) + ['termos' => Suporte::termos()], $estado);
    }

    private function auditar(Request $request, string $accao, string $descricao): void
    {
        DB::table('registos_auditoria')->insert([
            'user_id'    => $request->user()->id,
            'accao'      => $accao,
            'descricao'  => mb_substr($descricao, 0, 255),
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
            'url'        => $request->fullUrl(),
        ]);
    }
}
