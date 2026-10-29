<?php

namespace App\Support;

/**
 * Código MeuAcesso do cidadão: 6 caracteres, 5 algarismos + 1 letra (A-Z) em qualquer posição.
 * Ex.: "2A7341", "66B538", "0864C2". Vai no QR code ("MEUACESSO:2A7341").
 *
 * Ficam de fora os códigos com algarismos fáceis de adivinhar:
 *   - 3 ou mais algarismos iguais (ex.: A00000, 1111C2, 5155P2)
 *   - sequências seguidas, a subir ou a descer (ex.: R01234, T43210, 67890X)
 * Sobram 14 261 520 códigos válidos.
 */
class CodigoMeuAcesso
{
    public const TAMANHO = 6;

    private const LETRAS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    // Gera um código válido que ainda ninguém tem ($existe diz se já está em uso)
    public static function gerar(callable $existe): string
    {
        for ($tentativa = 0; $tentativa < 100; $tentativa++) {
            $codigo = static::sortear();

            if (static::valido($codigo) && ! $existe($codigo)) {
                return $codigo;
            }
        }

        throw new \RuntimeException('Não foi possível gerar um código MeuAcesso livre.');
    }

    // 5 algarismos ao acaso e uma letra numa posição ao acaso
    public static function sortear(): string
    {
        $digitos = '';
        for ($i = 0; $i < 5; $i++) {
            $digitos .= random_int(0, 9);
        }

        $letra = self::LETRAS[random_int(0, 25)];
        $posicao = random_int(0, 5);

        return substr($digitos, 0, $posicao).$letra.substr($digitos, $posicao);
    }

    // Formato certo e algarismos sem padrões óbvios
    public static function valido(string $codigo): bool
    {
        if (! preg_match('/^(?=(?:\D*\d){5}\D*$)[0-9A-Z]{6}$/', $codigo)) {
            return false;
        }

        $digitos = array_map('intval', str_split(preg_replace('/\D/', '', $codigo)));

        // 3 ou mais algarismos iguais
        if (max(array_count_values($digitos)) >= 3) {
            return false;
        }

        // Sequência seguida (01234, 43210, 67890, 09876...)
        foreach ([1, 9] as $passo) {
            $seguida = true;
            for ($i = 1; $i < 5; $i++) {
                if (($digitos[$i - 1] + $passo) % 10 !== $digitos[$i]) {
                    $seguida = false;
                    break;
                }
            }
            if ($seguida) {
                return false;
            }
        }

        return true;
    }

    // "meuacesso:2a7341", "2A7 341", "2a-7341" → "2A7341"
    public static function normalizar(?string $texto): string
    {
        $texto = strtoupper(trim((string) $texto));
        $texto = preg_replace('/^MEUACESSO:/', '', $texto);

        return preg_replace('/[^0-9A-Z]/', '', $texto);
    }
}
