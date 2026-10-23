<?php

namespace App\Http\Controllers\Utilizador;

use Illuminate\Http\Request;
use App\Models\User;
use App\Support\Telefone;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use App\Http\Controllers\Controller;


class RegistroController extends Controller
{
    // metodo responsavel pelo registo de utilizador
    public function register(Request $request): JsonResponse
    {


        // Arruma os dados antes de validar: "84 123 4567" → "+258841234567"
       $request->merge([
    'telefone' => Telefone::normalizar($request->telefone) ?? $request->telefone,
    'email' => $request->email ? mb_strtolower(trim($request->email)) : null,
    'password' => is_string($request->password) ? trim($request->password) : $request->password,
    'password_confirmation' => is_string($request->password_confirmation) ? trim($request->password_confirmation) : $request->password_confirmation,
    ]);

        $dados = $request->validate([
            'nome' => ['required', 'string', 'min:3', 'max:120'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'telefone' => ['required', 'regex:/^\+2588[2-7]\d{7}$/', 'unique:users,telefone'],
            'password' => ['required', 'string', 'min:8', 'confirmed'], // pede também password_confirmation
        ], [
            'required' => 'Preencha todos os campos.',
            'nome.min' => 'Escreva o nome completo.',
            'email.email' => 'O email não é válido.',
            'email.unique' => 'Este email já está registado.',
            'telefone.regex' => 'O telemóvel não é válido (ex.: 84 123 4567).',
            'telefone.unique' => 'Este telemóvel já está registado.',
            'password.min' => 'A palavra-passe deve ter pelo menos 8 caracteres.',
            'password.confirmed' => 'As palavras-passe não coincidem.',
        ]);

      
        $user = User::create([
            'tipo' => 0,                 // se a pessoa for validade apartir desse metodo ja e e 0 - utilizador
            'uuid'     => (string) Str::uuid(),
            'numero'   => User::gerarNumero(),   // número único (vai no QR code)
            'name' => $dados['nome'],
            'email' => $dados['email'],
            'telefone' => $dados['telefone'],
            'password' => $dados['password'],     // o model encripta (cast "hashed")
            'estado' => 'activo',
        ]);

        $user->cidadaoOuCriar();   // cria o registo do cidadão ligado a esta conta

        return response()->json([
            'message' => 'O registo foi feito com êxito!',
            'numero'  => $user->numero,
        ], 201);

    }


}
