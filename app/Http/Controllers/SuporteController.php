<?php

namespace App\Http\Controllers;

use App\Services\Suporte;
use Illuminate\Http\JsonResponse;

// Ecrã "Suporte" da app (público: também serve antes de entrar na conta)
class SuporteController extends Controller
{
    // GET /suporte → { contactos: { empresa, telefone, whatsapp, email, morada, horario, site },
    //                  perguntas: [ { id, categoria, pergunta, resposta } ],
    //                  termos: { nome, tamanho_bytes, actualizado_em, url } | null }
    public function index(): JsonResponse
    {
        return response()->json([
            'contactos' => Suporte::contactos(),
            'perguntas' => Suporte::perguntas(paraApp: true),
            'termos'    => Suporte::termos(),
        ]);
    }
}
