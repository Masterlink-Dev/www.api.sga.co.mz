{{-- Relatório da recepção em PDF (dompdf): só HTML e CSS simples, sem imagens externas --}}
<!doctype html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1f2937; font-size: 10px; margin: 0; }
        h1 { font-size: 17px; margin: 0; }
        h2 { font-size: 12px; margin: 16px 0 6px; }
        .cinza { color: #6b7280; }
        .kpis { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 12px -6px 0; }
        .kpis td { border: 1px solid #e5e7eb; border-radius: 6px; padding: 8px 10px; width: 25%; }
        .kpis b { display: block; font-size: 16px; margin-top: 2px; }
        table.lista { width: 100%; border-collapse: collapse; }
        table.lista th { background: #f3f4f6; text-align: left; padding: 5px 6px; font-size: 9px; text-transform: uppercase; }
        table.lista td { border-bottom: 1px solid #e5e7eb; padding: 4px 6px; }
        .lado { width: 100%; border-collapse: separate; border-spacing: 0; }
        .lado > tbody > tr > td { width: 50%; vertical-align: top; padding-right: 10px; }
    </style>
</head>
<body>
    <h1>{{ $instituicao }} ({{ $sigla }}) · Relatório de visitas</h1>
    <p class="cinza">{{ $periodo }} · gerado em {{ $gerado }}</p>

    <table class="kpis">
        <tr>
            <td>Visitas<b>{{ $kpis['visitas'] }}</b></td>
            <td>Visitantes diferentes<b>{{ $kpis['unicos'] }}</b></td>
            <td>Tempo médio de visita<b>{{ $kpis['tempoMedio'] }} min</b></td>
            <td>Entradas pelo código<b>{{ $kpis['viaCodigo'] }}%</b></td>
        </tr>
    </table>

    <table class="lado">
        <tr>
            <td>
                <h2>Visitas por sector</h2>
                <table class="lista">
                    <tr><th>Sector</th><th>Visitas</th></tr>
                    @forelse ($sectores as $s)
                        <tr><td>{{ $s['sector'] }}</td><td>{{ $s['valor'] }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="cinza">Sem visitas no período.</td></tr>
                    @endforelse
                </table>
            </td>
            <td>
                <h2>Motivos das visitas</h2>
                <table class="lista">
                    <tr><th>Motivo</th><th>Visitas</th><th>Tempo médio</th></tr>
                    @forelse ($motivos as $m)
                        <tr><td>{{ $m['motivo'] }}</td><td>{{ $m['visitas'] }}</td><td>{{ $m['tempoMedio'] }} min</td></tr>
                    @empty
                        <tr><td colspan="3" class="cinza">Sem visitas no período.</td></tr>
                    @endforelse
                </table>
            </td>
        </tr>
    </table>

    <h2>Registo das visitas ({{ count($linhas) }})</h2>
    <table class="lista">
        <tr>
            <th>Data</th><th>Entrada</th><th>Saída</th><th>Visitante</th><th>Documento</th>
            <th>Sector</th><th>Motivo</th><th>Pessoa a visitar</th><th>Identificação</th><th>Duração</th>
        </tr>
        @forelse ($linhas as $l)
            <tr>
                <td>{{ $l['data'] }}</td><td>{{ $l['entrada'] }}</td><td>{{ $l['saida'] ?: '—' }}</td>
                <td>{{ $l['visitante'] }}</td><td>{{ $l['documento'] }}</td><td>{{ $l['sector'] }}</td>
                <td>{{ $l['motivo'] }}</td><td>{{ $l['pessoa'] }}</td><td>{{ $l['identificacao'] }}</td>
                <td>{{ $l['duracao'] !== null ? $l['duracao'] . ' min' : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="10" class="cinza">Sem visitas no período.</td></tr>
        @endforelse
    </table>
</body>
</html>
