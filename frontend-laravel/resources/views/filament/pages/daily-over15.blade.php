<x-filament-panels::page>
    <x-oracly.page-header eyebrow="Over 1.5 FT · somente ligas principais">
        Over 1.5 gols no jogo.

        <x-slot name="description">
            @if ($mode === 'upcoming')
                Jogos de {{ \Carbon\Carbon::parse($date)->format('d/m/Y') }} com previsão pré-jogo para pelo menos dois gols.
            @else
                Histórico consolidado com a última previsão antes do início de cada jogo.
            @endif
        </x-slot>
    </x-oracly.page-header>

    <x-oracly.chip-group :options="$this::MODE_OPTIONS" :active="$mode" method="setMode" />

    @if ($this->bestCutoff)
        <x-oracly.stat-tile hero accent :value="number_format($this->bestCutoff['hitRate'], 0).'%'" :label="'Melhor corte: ≥ '.$this->bestCutoff['threshold'].'%'">
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $this->bestCutoff['wins'] }} acertos em {{ $this->bestCutoff['entries'] }} jogos.</p>
        </x-oracly.stat-tile>
    @endif

    <div>
        <h2 class="mb-2 text-sm font-semibold text-gray-500 dark:text-gray-400">Assertividade por corte</h2>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            @foreach ($this->cutoffStats as $threshold => $stat)
                <x-oracly.stat-tile :active="$minProbability === $threshold" :value="$stat['hitRate'] !== null ? number_format($stat['hitRate'], 0).'%' : '—'" :label="'O1.5 ≥ '.$threshold.'%'">
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $stat['wins'] }} / {{ $stat['entries'] }} jogos</p>
                </x-oracly.stat-tile>
            @endforeach
        </div>
    </div>

    <x-oracly.chip-group :options="$this::THRESHOLDS" :active="$minProbability" method="setMinProbability" />

    <div class="overflow-x-auto">
        <table class="oracly-table">
            <thead>
                <tr>
                    <th>{{ $mode === 'history' ? 'Data' : 'Horário' }}</th>
                    <th>Jogo</th>
                    <th>Liga</th>
                    <th>O1.5</th>
                    @if ($mode === 'history')
                        <th>Resultado HT</th>
                        <th>Resultado FT</th>
                        <th>Acerto</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($this->filteredRows as $row)
                    <tr>
                        <td class="whitespace-nowrap">{{ $row['kickoffAt'] ? \Carbon\Carbon::parse($row['kickoffAt'])->timezone('America/Sao_Paulo')->format($mode === 'history' ? 'd/m H:i' : 'H:i') : '—' }}</td>
                        <td class="font-medium text-gray-950 dark:text-white">{{ $row['homeTeam'] }} x {{ $row['awayTeam'] }}</td>
                        <td class="text-gray-500 dark:text-gray-400">{{ $row['country'] }} · {{ $row['competition'] }}</td>
                        <td class="font-semibold">{{ number_format($row['probability'], 0) }}%</td>
                        @if ($mode === 'history')
                            <td>{{ $row['halftimeHomeScore'] !== null && $row['halftimeAwayScore'] !== null ? 'HT '.$row['halftimeHomeScore'].'-'.$row['halftimeAwayScore'] : '—' }}</td>
                            <td>FT {{ $row['homeScore'] }}-{{ $row['awayScore'] }}</td>
                            <td><x-oracly.result-badge :hit="$row['hit']" /></td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-6 text-gray-500 dark:text-gray-400">Sem jogos neste corte.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
