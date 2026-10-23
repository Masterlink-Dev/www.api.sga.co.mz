<?php

namespace App\Support;

/**
 * Números de telemóvel de Moçambique em formato E.164 (+258 8X XXX XXXX).
 */
class Telefone
{
    // "84 123 4567", "841234567", "258841234567", "00258..." → "+258841234567"
    public static function normalizar(?string $telefone): ?string
    {
        if (! $telefone) {
            return null;
        }

        $digitos = preg_replace('/\D+/', '', $telefone);

        if (str_starts_with($digitos, '00')) {
            $digitos = substr($digitos, 2);
        }
        if (strlen($digitos) === 9) {
            $digitos = '258'.$digitos;
        }

        // Vodacom 84/85, Movitel 86/87, Tmcel 82/83
        return preg_match('/^258(8[2-7])\d{7}$/', $digitos) ? '+'.$digitos : null;
    }

    // "+258841234567" → "+258 84 ••• 4567"
    public static function mascarar(string $telefone): string
    {
        return substr($telefone, 0, 4).' '.substr($telefone, 4, 2).' ••• '.substr($telefone, -4);
    }

    // "marta.sitoe@misau.gov.mz" → "ma•••@misau.gov.mz"
    public static function mascararEmail(string $email): string
    {
        [$nome, $dominio] = explode('@', $email) + [1 => ''];

        return mb_substr($nome, 0, 2).'•••@'.$dominio;
    }
}