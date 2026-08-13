<x-filament-panels::page>
    <x-oracly.page-header eyebrow="Sinais validados · somente ligas principais">
        Melhores entradas Over 1.5 FT.

        <x-slot name="description">
            @if ($mode === 'upcoming')
                Jogos de {{ \Carbon\Carbon::parse($date)->format('d/m/Y') }} com pelo menos 2 dos 4 sinais validados.
            @else
                Histórico consolidado com a última previsão antes do início de cada jogo.
            @endif
        </x-slot>
    </x-oracly.page-header>

    <x-oracly.chip-group :options="$this::MODE_OPTIONS" :active="$mode" method="setMode" />

    @if ($this->bestCutoff)
        <x-oracly.stat-tile hero accent :value="number_format($this->bestCutoff['hitRate'], 0).'%'" :label="'Melhor corte: ≥ '.$this->bestCutoff['threshold'].'/4 sinais'">
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $this->bestCutoff['wins'] }} greens · {{ $this->bestCutoff['reds'] }} reds em {{ $this->bestCutoff['entries'] }} jogos.</p>
        </x-oracly.stat-tile>
    @endif

    <div>
        <h2 class="mb-2 text-sm font-semibold text-gray-500 dark:text-gray-400">Assertividade por força do sinal</h2>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            @foreach ($this->cutoffStats as $threshold => $stat)
                <x-oracly.stat-tile :active="$minSignalScore === $threshold" :value="$stat['hitRate'] !== null ? number_format($stat['hitRate'], 0).'%' : '—'" :label="'≥ '.$threshold.'/4 sinais'">
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $stat['wins'] }}G · {{ $stat['reds'] }}R / {{ $stat['entries'] }}</p>
                </x-oracly.stat-tile>
            @endforeach
        </div>
    </div>

    <x-oracly.chip-group :options="$this::SIGNAL_THRESHOLDS" :active="$minSignalScore" method="setMinSignalScore" />
    <x-oracly.chip-group :options="$this::FAVORITE_OPTIONS" :active="$favoriteFilter" method="setFavoriteFilter" />

    @if ($mode === 'history')
        <div class="max-w-xs">
            <label for="over15-score-filter" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Placar final</label>
            <select id="over15-score-filter" wire:model.live="scoreFilter" class="oracly-select">
                @foreach ($this->scoreOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    @endif

    <div class="overflow-x-auto">
        <table class="oracly-table">
            <thead>
                <tr>
                    <th>{{ $mode === 'history' ? 'Data' : 'Horário' }}</th>
                    <th>Jogo</th>
                    <th>Liga</th>
                    <th>O1.5</th>
                    <th>Sinais</th>
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
                        <td class="font-semibold">{{ $row['signalScore'] ?? 0 }}/4</td>
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
