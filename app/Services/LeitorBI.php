<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Lê o BI com o Tesseract OCR (gratuito, corre no próprio servidor).
 *
 * 1) Lê o texto impresso da frente (e do verso), procurando as etiquetas do BI:
 *    "N°", "Nome / Name", "Data de Nascimento", "Sex", "Height", "Place of Birth", "Address".
 * 2) Se o cartão tiver a zona de leitura óptica (linhas "IDMOZ...<<"), usa-a para completar.
 *
 * Só devolve o que leu com clareza: o que não se lê fica em branco (nunca se inventa).
 */
class LeitorBI
{
    // Todos os campos da tabela cidadaos que o BI pode dar (serve para dizer o que ficou por preencher)
    public const CAMPOS = [
        'numero_documento', 'nomes', 'apelidos', 'sexo', 'data_nascimento', 'naturalidade',
        'nacionalidade', 'estado_civil', 'altura_cm', 'nome_pai', 'nome_mae', 'morada',
        'profissao', 'documento_emitido_em', 'documento_data_emissao', 'documento_data_validade',
    ];

    /**
     * Devolve os campos lidos, ou null se não se encontrar um número de BI.
     * Lança RuntimeException se o Tesseract não estiver instalado.
     */
    public function ler(UploadedFile $frente, UploadedFile $verso): ?array
    {
        $f = $frente->getRealPath();
        $v = $verso->getRealPath();

        // 1) Texto impresso da frente e do verso
        $texto = $this->tesseract($f, ['--psm', '3'])."\n".$this->tesseract($v, ['--psm', '3']);
        $dados = self::lerImpresso($texto);

        // 2) Zona de leitura óptica (se existir): completa o que faltar
        if (! isset($dados['numero_documento']) || ! isset($dados['data_nascimento'])) {
            $mrz = $this->tesseract($v, ['--psm', '6', '-c', 'tessedit_char_whitelist=ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789<']);
            $zona = self::lerMrz($mrz."\n".$texto);

            // Só usa a zona óptica se o número bater certo com o impresso (ou se não houver impresso)
            if ($zona && (! isset($dados['numero_documento']) || $zona['numero_documento'] === $dados['numero_documento'])) {
                $dados += $zona;
            }
        }

        return isset($dados['numero_documento']) ? $dados : null;
    }

    private function tesseract(string $imagem, array $opcoes): string
    {
        $exe = config('services.tesseract.path') ?: env('TESSERACT_PATH') ?: 'tesseract';

        try {
            $r = Process::timeout(90)->run([$exe, $imagem, 'stdout', '-l', 'eng', ...$opcoes]);
        } catch (Throwable $e) {
            throw new RuntimeException('Tesseract não encontrado. Instale-o e defina TESSERACT_PATH no .env.');
        }

        if (! $r->successful()) {
            throw new RuntimeException('Tesseract não encontrado ou com erro. Verifique TESSERACT_PATH no .env.');
        }

        return $r->output();
    }

    // ---------------------------------------------------------------- Texto impresso

    public static function lerImpresso(string $texto): array
    {
        // Número do BI: 12 dígitos + 1 letra (só dígitos a sério, para não apanhar ruído)
        if (! preg_match('/\b(\d{12}[A-Z])\b/', $texto, $m)) {
            return [];
        }

        $dados = ['tipo_documento' => 'bi', 'numero_documento' => $m[1]];

        $linhas = array_values(array_filter(array_map('trim', preg_split('/\R/', $texto)), fn ($l) => $l !== ''));

        // Valor de uma etiqueta: o que vem a seguir na mesma linha, ou a linha seguinte
        $valor = function (string $etiqueta) use ($linhas): ?string {
            foreach ($linhas as $i => $l) {
                if (preg_match($etiqueta, $l, $x, PREG_OFFSET_CAPTURE)) {
                    $resto = trim(substr($l, $x[0][1] + strlen($x[0][0])), " :\t|");

                    return $resto !== '' ? $resto : ($linhas[$i + 1] ?? null);
                }
            }

            return null;
        };

        // Só letras (nomes e locais): rejeita ruído
        $soLetras = function (?string $t): ?string {
            $t = trim((string) $t, " .,;:|_-");

            return preg_match('/^[\p{L}\' -]{3,}$/u', $t) ? $t : null;
        };

        // Nome completo: os últimos é o apelido, o resto são os nomes
        if ($nome = $soLetras($valor('/Nome\s*\/\s*Name\s*:?/i'))) {
            $partes = preg_split('/\s+/', $nome);

            if (count($partes) >= 2) {
                $dados['apelidos'] = self::nome(array_pop($partes));
                $dados['nomes'] = self::nome(implode(' ', $partes));
                $dados['nome_completo'] = $dados['nomes'].' '.$dados['apelidos'];
            }
        }

        if (preg_match('/\bSex\s*:?\s*([MF])\b/', $texto, $x)) {
            $dados['sexo'] = $x[1];
        }

        if (preg_match('/Height\s*:?\s*(\d)\s*[.,]\s*(\d{2})/i', $texto, $x)) {
            $cm = (int) $x[1] * 100 + (int) $x[2];

            if ($cm >= 80 && $cm <= 250) {
                $dados['altura_cm'] = $cm;
            }
        }

        if ($local = $soLetras($valor('/(?:[MN]aturalidade\s*\/?\s*)?Place\s+of\s+Birth\s*:?|[MN]aturalidade\s*:?/i'))) {
            $dados['naturalidade'] = self::nome($local);
        }

        // Data de nascimento: a primeira data que aparece depois da etiqueta
        foreach ($linhas as $i => $l) {
            if (! preg_match('/Nascimento|Date\s+of\s+Birth/i', $l)) {
                continue;
            }

            foreach (array_slice($linhas, $i, 7) as $perto) {
                if (preg_match('/\b(\d{2})[\/.-](\d{2})[\/.-](\d{4})\b/', $perto, $x)
                    && checkdate((int) $x[2], (int) $x[1], (int) $x[3])
                    && (int) $x[3] >= 1900 && (int) $x[3] <= (int) date('Y')) {
                    $dados['data_nascimento'] = sprintf('%04d-%02d-%02d', $x[3], $x[2], $x[1]);
                    break 2;
                }
            }
        }

        // Morada: as linhas entre "Address" e "Assinatura"
        foreach ($linhas as $i => $l) {
            if (! preg_match('/Address|Resid[eê]ncia/i', $l)) {
                continue;
            }

            $partes = [];

            foreach (array_slice($linhas, $i + 1, 5) as $seguinte) {
                $limpa = trim(preg_replace('/\s*(Assinatura|Signature).*$/i', '', $seguinte), " :|/");

                if ($limpa !== '') {
                    $partes[] = $limpa;
                }

                if (preg_match('/Assinatura|Signature/i', $seguinte)) {
                    break;
                }
            }

            if ($partes) {
                $dados['morada'] = implode(', ', array_slice($partes, 0, 4));
            }

            break;
        }

        return $dados;
    }

    // ---------------------------------------------------------------- Zona de leitura óptica (IDMOZ...<<)

    /**
     * Só confia na zona óptica se encontrar a linha 1 (IDMOZ + número do BI).
     * As datas têm de passar o dígito de controlo.
     */
    public static function lerMrz(string $texto): array
    {
        $linhas = [];

        foreach (preg_split('/\R/', $texto) as $linha) {
            $l = preg_replace('/[^A-Z0-9<]/', '', strtoupper($linha));

            if (strlen($l) >= 8) {
                $linhas[] = $l;
            }
        }

        $numero = null;

        foreach ($linhas as $l) {
            if (str_starts_with($l, 'ID') && preg_match('/([0-9OIBSZ]{12})([A-Z])/', $l, $m)) {
                $numero = strtr($m[1], 'OIBSZ', '01852').$m[2];
                break;
            }
        }

        if ($numero === null) {
            return [];
        }

        $dados = ['tipo_documento' => 'bi', 'numero_documento' => $numero];

        // Linha 2: AAMMDD d S AAMMDD d NAC ...
        foreach ($linhas as $l) {
            $n = strtr($l, 'OIBSZ', '01852');

            if (! preg_match('/(\d{6})(\d)([MF<])(\d{6})(\d)/', $n, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            if (self::controlo($m[1][0]) === (int) $m[2][0]) {
                $dados['data_nascimento'] = self::dataMrz($m[1][0], nascimento: true);
            }

            if ($m[3][0] !== '<') {
                $dados['sexo'] = $m[3][0];
            }

            if (self::controlo($m[4][0]) === (int) $m[5][0]) {
                $dados['documento_data_validade'] = self::dataMrz($m[4][0], nascimento: false);
            }

            if (substr($l, $m[5][1] + 1, 3) === 'MOZ') {
                $dados['nacionalidade'] = 'Moçambicana';
            }

            break;
        }

        // Linha 3: APELIDOS<<NOMES<PROPRIOS<<<<<
        foreach ($linhas as $l) {
            if (str_starts_with($l, 'ID') || ! preg_match('/^[A-Z]+<<[A-Z<]+/', $l)) {
                continue;
            }

            [$apelidos, $nomes] = explode('<<', $l, 2);

            $limpar = fn ($s) => trim(preg_replace('/\s+/', ' ', str_replace('<', ' ', preg_replace('/K{3,}$/', '', $s))));

            $apelidos = $limpar($apelidos);
            $nomes = $limpar($nomes);

            if ($apelidos !== '' && $nomes !== '') {
                $dados['apelidos'] = self::nome($apelidos);
                $dados['nomes'] = self::nome($nomes);
                $dados['nome_completo'] = $dados['nomes'].' '.$dados['apelidos'];
            }

            break;
        }

        return array_filter($dados, fn ($v) => $v !== null && $v !== '');
    }

    // ---------------------------------------------------------------- Auxiliares

    // "1101••••••56A" para mostrar em listas
    public static function mascarar(string $numero): string
    {
        return substr($numero, 0, 4).'••••••'.substr($numero, -3);
    }

    // Dígito de controlo da zona óptica (pesos 7-3-1)
    private static function controlo(string $campo): int
    {
        $soma = 0;

        foreach (str_split($campo) as $i => $c) {
            $valor = ctype_digit($c) ? (int) $c : ($c === '<' ? 0 : ord($c) - 55);
            $soma += $valor * [7, 3, 1][$i % 3];
        }

        return $soma % 10;
    }

    // AAMMDD → AAAA-MM-DD (nascimento: não pode ser no futuro; validade: século 20xx)
    private static function dataMrz(string $aammdd, bool $nascimento): ?string
    {
        $ano = (int) substr($aammdd, 0, 2);
        $mes = (int) substr($aammdd, 2, 2);
        $dia = (int) substr($aammdd, 4, 2);

        $ano += ($nascimento && $ano > (int) date('y')) ? 1900 : 2000;

        return checkdate($mes, $dia, $ano) ? sprintf('%04d-%02d-%02d', $ano, $mes, $dia) : null;
    }

    // "ANA MARIA" → "Ana Maria"; "JOAO DA SILVA" → "Joao da Silva"
    private static function nome(string $t): string
    {
        $t = mb_convert_case(mb_strtolower($t), MB_CASE_TITLE);

        return str_replace([' De ', ' Da ', ' Do ', ' Dos ', ' Das ', ' E '], [' de ', ' da ', ' do ', ' dos ', ' das ', ' e '], $t);
    }
}
