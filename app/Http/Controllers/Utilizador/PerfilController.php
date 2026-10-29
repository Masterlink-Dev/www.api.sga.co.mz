<?php

namespace App\Http\Controllers\Utilizador;

use App\Http\Controllers\Controller;
use App\Support\Telefone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// O cidadão edita os dados da sua conta na app (ecrã "Minha conta" → "Editar perfil").
// A palavra-passe muda-se em PUT /senha (igual para todas as contas).
class PerfilController extends Controller
{
    // PUT /perfil  { nome, email, telefone }  → { mensagem, utilizador: { uuid, numero, nome, email, telefone, tipo } }
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        // Arruma antes de validar: "84 123 4567" → "+258841234567"
        $request->merge([
            'nome'     => trim((string) $request->input('nome')),
            'email'    => mb_strtolower(trim((string) $request->input('email'))),
            'telefone' => Telefone::normalizar($request->input('telefone')) ?? $request->input('telefone'),
        ]);

        $dados = $request->validate([
            'nome'     => ['required', 'string', 'min:3', 'max:120'],
            'email'    => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'telefone' => ['required', 'regex:/^\+2588[2-7]\d{7}$/', Rule::unique('users', 'telefone')->ignore($user->id)],
        ], [
            'nome.required'     => 'Escreva o nome completo.',
            'nome.min'          => 'Escreva o nome completo.',
            'email.required'    => 'Escreva o email.',
            'email.email'       => 'O email não é válido.',
            'email.unique'      => 'Este email já está a ser usado por outra conta.',
            'telefone.required' => 'Escreva o telemóvel.',
            'telefone.regex'    => 'O telemóvel não é válido (ex.: 84 123 4567).',
            'telefone.unique'   => 'Este telemóvel já está a ser usado por outra conta.',
        ]);

        $user->forceFill([
            'name'     => $dados['nome'],
            'email'    => $dados['email'],
            'telefone' => $dados['telefone'],
        ])->save();

        // Os contactos do registo do cidadão acompanham a conta.
        // O nome do cidadão não muda aqui: depois de enviar o BI, vale o nome do documento.
        if ($cidadao = $user->cidadao) {
            $cidadao->forceFill(['email' => $dados['email'], 'telefone' => $dados['telefone']])->save();
        }

        return response()->json([
            'mensagem'   => 'Perfil actualizado.',
            'utilizador' => [
                'uuid'     => $user->uuid,
                'numero'   => $user->numero,
                'nome'     => $user->name,
                'email'    => $user->email,
                'telefone' => $user->telefone,
                'tipo'     => $user->tipo,
            ],
        ]);
    }
}
