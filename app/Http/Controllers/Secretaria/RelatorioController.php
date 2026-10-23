<?php

namespace App\Http\Controllers\Secretaria;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Relatórios da recepção, por período (hoje, esta semana, últimos 30 dias, este ano).
// As datas guardam-se em UTC; os dias e as horas contam-se na hora de Maputo.
class RelatorioController extends Controller
{
    use ContextoSecretaria;

    private const FUSO = 'Africa/Maputo';
    private const PERIODOS = ['hoje', 'semana', 'mes', 'ano'];
    private const DIAS = ['seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'];
    private const MESES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    private const HORAS = [7, 8, 9, 10, 11, 12, 13, 14, 15, 16]; // mapa de calor: 07h–16h

    // GET /secretaria/relatorios?periodo=hoje|semana|mes|ano
    public function index(Request $request): JsonResponse
    {
        $func = $this->funcionario($request);
        $periodo = $this->periodoPedido($request);
        $p = $this->limites($periodo);

        $visitas = $this->visitas($func->instituicao_id, $p['inicio'], $p['fim']);
        $anteriores = $this->contar($func->instituicao_id, $p['inicio_anterior'], $p['fim_anterior']);

        return response()->json([
            'periodo'   => ['valor' => $periodo, 'descricao' => $p['descricao'], 'anterior' => $p['anterior']],
            'kpis'      => $this->kpis($visitas, $anteriores),
            'serie'     => $this->serie($periodo, $visitas, $p['inicio'], $p['fim']),
            'sectores'  => $this->porSector($visitas),
            'motivos'   => $this->porMotivo($visitas),
            'movimento' => $this->movimento($func->instituicao_id),
            'adopcao'   => $this->adopcao($func->instituicao_id),
        ]);
    }

    // GET /secretaria/relatorios/exportar?periodo=…&formato=excel|pdf → ficheiro do período escolhido
    public function exportar(Request $request)
    {
        $func = $this->funcionario($request);
        $periodo = $this->periodoPedido($request);
        $formato = $request->validate(['formato' => ['required', 'in:excel,pdf']], ['formato.in' => 'O formato tem de ser excel ou pdf.'])['formato'];
        $p = $this->limites($periodo);
        $visitas = $this->visitas($func->instituicao_id, $p['inicio'], $p['fim']);
        $nome = 'relatorio-' . strtolower($func->sigla) . '-' . $periodo . '-' . now(self::FUSO)->format('Y-m-d');

        // Respostas livres do formulário (ex.: "Vem acompanhado?") → uma coluna por campo
        $respostas = DB::table('respostas_visita')->whereIn('visita_id', $visitas->pluck('id'))->get()->groupBy('visita_id');
        $colunasExtra = $respostas->flatten()->pluck('campo_nome')->unique()->values();

        if ($formato === 'pdf') {
            if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
                return response()->json(['codigo' => 'POR_INSTALAR', 'mensagem' => 'A exportação em PDF precisa do pacote barryvdh/laravel-dompdf (composer require barryvdh/laravel-dompdf).'], 501);
            }

            return \Barryvdh\DomPDF\Facade\Pdf::loadView('relatorios.recepcao', [
                'instituicao' => $func->instituicao,
                'sigla'       => $func->sigla,
                'periodo'     => $p['descricao'],
                'kpis'        => $this->kpis($visitas, $this->contar($func->instituicao_id, $p['inicio_anterior'], $p['fim_anterior'])),
                'sectores'    => $this->porSector($visitas),
                'motivos'     => $this->porMotivo($visitas),
                'linhas'      => $visitas->map(fn ($v) => $this->linha($v)),
                'gerado'      => now(self::FUSO)->format('d/m/Y H:i'),
            ])->setPaper('a4', 'landscape')->download($nome . '.pdf');
        }

        // Excel: CSV com ";" e BOM, para o Excel abrir os acentos e as colunas bem
        return new StreamedResponse(function () use ($visitas, $respostas, $colunasExtra) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_merge(['Data', 'Entrada', 'Saída', 'Visitante', 'Documento', 'Sector', 'Motivo', 'Pessoa a visitar', 'Identificação', 'Duração (min)'], $colunasExtra->all()), ';');
            foreach ($visitas as $v) {
                $porCampo = ($respostas[$v->id] ?? collect())->pluck('valor', 'campo_nome');
                fputcsv($out, array_merge(array_values($this->linha($v)), $colunasExtra->map(fn ($c) => $porCampo[$c] ?? '')->all()), ';');
            }
            fclose($out);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $nome . '.csv"',
        ]);
    }

    // ---------- auxiliares ----------

    private function periodoPedido(Request $request): string
    {
        return $request->validate(
            ['periodo' => ['nullable', 'in:' . implode(',', self::PERIODOS)]],
            ['periodo.in' => 'O período tem de ser hoje, semana, mes ou ano.']
        )['periodo'] ?? 'mes';
    }

    // Início e fim do período (e do período anterior, para comparar), na hora de Maputo
    private function limites(string $periodo): array
    {
        $agora = now(self::FUSO);
        $fmt = fn (Carbon $d) => $d->format('d') . ' ' . self::MESES[$d->month - 1] . ' ' . $d->year;

        return match ($periodo) {
            'hoje' => [
                'inicio' => $agora->copy()->startOfDay(), 'fim' => $agora,
                'inicio_anterior' => $agora->copy()->subDay()->startOfDay(), 'fim_anterior' => $agora->copy()->subDay(),
                'descricao' => ucfirst(self::DIAS[$agora->dayOfWeekIso - 1]) . ', ' . $fmt($agora) . ' · até às ' . $agora->format('H:i'),
                'anterior' => 'ontem à mesma hora',
            ],
            'semana' => [
                'inicio' => $agora->copy()->startOfWeek(), 'fim' => $agora,
                'inicio_anterior' => $agora->copy()->startOfWeek()->subWeek(), 'fim_anterior' => $agora->copy()->subWeek(),
                'descricao' => $fmt($agora->copy()->startOfWeek()) . ' a ' . $fmt($agora),
                'anterior' => 'semana passada',
            ],
            'ano' => [
                'inicio' => $agora->copy()->startOfYear(), 'fim' => $agora,
                'inicio_anterior' => $agora->copy()->startOfYear()->subYear(), 'fim_anterior' => $agora->copy()->subYear(),
                'descricao' => 'Janeiro a ' . $fmt($agora),
                'anterior' => 'ao mesmo período de ' . ($agora->year - 1),
            ],
            default => [ // mes: últimos 30 dias
                'inicio' => $agora->copy()->subDays(29)->startOfDay(), 'fim' => $agora,
                'inicio_anterior' => $agora->copy()->subDays(59)->startOfDay(), 'fim_anterior' => $agora->copy()->subDays(29)->startOfDay(),
                'descricao' => $fmt($agora->copy()->subDays(29)) . ' a ' . $fmt($agora),
                'anterior' => 'aos 30 dias anteriores',
            ],
        };
    }

    // Visitas do período, com a hora local já calculada ($v->local)
    private function visitas(int $instituicaoId, Carbon $inicio, Carbon $fim): Collection
    {
        return DB::table('visitas as v')
            ->leftJoin('sectores as s', 's.id', '=', 'v.sector_id')
            ->leftJoin('motivos_visita as m', 'm.id', '=', 'v.motivo_id')
            ->where('v.instituicao_id', $instituicaoId)->whereNull('v.deleted_at')
            ->whereBetween('v.entrada_em', [$inicio->copy()->utc(), $fim->copy()->utc()])
            ->orderBy('v.entrada_em')
            ->get(['v.id', 'v.entrada_em', 'v.saida_em', 'v.duracao_minutos', 'v.estado', 'v.metodo_identificacao',
                'v.cidadao_id', 'v.documento_numero', 'v.nome_visitante', 'v.pessoa_a_visitar', 'v.motivo_outro',
                's.nome as sector', 'm.nome as motivo'])
            ->each(fn ($v) => $v->local = Carbon::parse($v->entrada_em, 'UTC')->setTimezone(self::FUSO));
    }

    private function contar(int $instituicaoId, Carbon $inicio, Carbon $fim): int
    {
        return DB::table('visitas')->where('instituicao_id', $instituicaoId)->whereNull('deleted_at')
            ->whereBetween('entrada_em', [$inicio->copy()->utc(), $fim->copy()->utc()])->count();
    }

    private function kpis(Collection $visitas, int $anteriores): array
    {
        $total = $visitas->count();

        return [
            'visitas'         => $total,
            'visitasAnterior' => $anteriores,
            // Visitante diferente: a conta do cidadão, ou o BI, ou o nome (registo manual)
            'unicos'          => $visitas->map(fn ($v) => $v->cidadao_id ? "c{$v->cidadao_id}" : ($v->documento_numero ?: $v->nome_visitante))->unique()->count(),
            'tempoMedio'      => (int) round($visitas->whereNotNull('duracao_minutos')->avg('duracao_minutos') ?? 0),
            'viaCodigo'       => $total ? (int) round($visitas->whereIn('metodo_identificacao', ['codigo', 'qr'])->count() * 100 / $total) : 0,
        ];
    }

    private function porSector(Collection $visitas): Collection
    {
        return $visitas->groupBy(fn ($v) => $v->sector ?? 'Sem sector')
            ->map(fn ($g, $s) => ['sector' => $s, 'valor' => $g->count()])
            ->sortByDesc('valor')->values();
    }

    private function porMotivo(Collection $visitas): Collection
    {
        return $visitas->groupBy(fn ($v) => $v->motivo ?? 'Sem motivo')
            ->map(fn ($g, $m) => [
                'motivo'     => $m,
                'visitas'    => $g->count(),
                'tempoMedio' => (int) round($g->whereNotNull('duracao_minutos')->avg('duracao_minutos') ?? 0),
            ])
            ->sortByDesc('visitas')->values();
    }

    // Barras do gráfico: por hora (hoje), por dia (semana, 30 dias) ou por mês (ano); código vs. BI manual
    private function serie(string $periodo, Collection $visitas, Carbon $inicio, Carbon $fim): array
    {
        if ($periodo === 'hoje') {
            $titulo = 'Visitas por hora';
            $de = min(7, (int) ($visitas->min(fn ($v) => $v->local->hour) ?? 7));
            $ate = max(16, (int) ($visitas->max(fn ($v) => $v->local->hour) ?? 16));
            $baldes = collect(range($de, $ate))->mapWithKeys(fn ($h) => [$h => sprintf('%02dh', $h)]);
            $chave = fn ($v) => $v->local->hour;
        } elseif ($periodo === 'ano') {
            $titulo = 'Visitas por mês';
            $baldes = collect(range(1, $fim->month))->mapWithKeys(fn ($m) => [$m => self::MESES[$m - 1]]);
            $chave = fn ($v) => $v->local->month;
        } else {
            $titulo = $periodo === 'semana' ? 'Visitas por dia' : 'Visitas por dia · últimos 30 dias';
            $dias = (int) $inicio->copy()->startOfDay()->diffInDays($fim->copy()->startOfDay());
            $baldes = collect(range(0, $dias))->mapWithKeys(function ($i) use ($inicio, $periodo) {
                $d = $inicio->copy()->addDays($i);

                return [$d->format('Y-m-d') => $periodo === 'semana' ? self::DIAS[$d->dayOfWeekIso - 1] . ' ' . $d->format('d') : $d->format('d/m')];
            });
            $chave = fn ($v) => $v->local->format('Y-m-d');
        }

        $porBalde = $visitas->groupBy($chave);
        $doBalde = fn ($k) => $porBalde[$k] ?? collect();

        return [
            'titulo'  => $titulo,
            'rotulos' => $baldes->values(),
            'codigo'  => $baldes->keys()->map(fn ($k) => $doBalde($k)->whereIn('metodo_identificacao', ['codigo', 'qr'])->count())->values(),
            'manual'  => $baldes->keys()->map(fn ($k) => $doBalde($k)->whereNotIn('metodo_identificacao', ['codigo', 'qr'])->count())->values(),
        ];
    }

    // "Quando há mais movimento": média de entradas por dia da semana e hora (últimas 4 semanas)
    private function movimento(int $instituicaoId): array
    {
        $visitas = $this->visitas($instituicaoId, now(self::FUSO)->subWeeks(4)->startOfDay(), now(self::FUSO));
        $conta = $visitas->groupBy(fn ($v) => $v->local->dayOfWeekIso . '-' . $v->local->hour)->map->count();
        // O fim-de-semana só aparece se houver visitas nesses dias
        $dias = collect(range(1, 7))->filter(fn ($d) => $d <= 5 || $visitas->contains(fn ($v) => $v->local->dayOfWeekIso === $d));

        return [
            'horas' => array_map(fn ($h) => sprintf('%02dh', $h), self::HORAS),
            'dias'  => $dias->map(fn ($d) => [
                'dia'     => ucfirst(self::DIAS[$d - 1]),
                // Média por semana; com poucas visitas, arredonda para cima para não esconder o que existe
                'valores' => array_map(fn ($h) => (int) ceil(($conta["$d-$h"] ?? 0) / 4), self::HORAS),
            ])->values(),
        ];
    }

    // "Adopção do código": % de entradas feitas com o código, mês a mês, este ano
    private function adopcao(int $instituicaoId): array
    {
        $agora = now(self::FUSO);
        $porMes = $this->visitas($instituicaoId, $agora->copy()->startOfYear(), $agora)->groupBy(fn ($v) => $v->local->month);

        return [
            'meses'   => array_map(fn ($m) => self::MESES[$m - 1], range(1, $agora->month)),
            'valores' => array_map(function ($m) use ($porMes) {
                $g = $porMes[$m] ?? collect();

                return $g->count() ? (int) round($g->whereIn('metodo_identificacao', ['codigo', 'qr'])->count() * 100 / $g->count()) : 0;
            }, range(1, $agora->month)),
        ];
    }

    // Uma visita como linha do Excel / do PDF
    private function linha(object $v): array
    {
        return [
            'data'          => $v->local->format('d/m/Y'),
            'entrada'       => $v->local->format('H:i'),
            'saida'         => $v->saida_em ? Carbon::parse($v->saida_em, 'UTC')->setTimezone(self::FUSO)->format('H:i') : '',
            'visitante'     => $v->nome_visitante,
            'documento'     => $v->documento_numero,
            'sector'        => $v->sector,
            'motivo'        => $v->motivo === 'Outro' && $v->motivo_outro ? "Outro: {$v->motivo_outro}" : $v->motivo,
            'pessoa'        => $v->pessoa_a_visitar,
            'identificacao' => in_array($v->metodo_identificacao, ['codigo', 'qr'], true) ? 'Código MeuAcesso' : 'BI manual',
            'duracao'       => $v->duracao_minutos,
        ];
    }
}
