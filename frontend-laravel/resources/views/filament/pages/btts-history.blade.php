<x-filament-panels::page>
    <x-oracly.page-header eyebrow="Histórico · Ambas marcam · somente ligas principais">
        Histórico de Ambas Marcam.

        <x-slot name="description">
            Cada resultado usa a última previsão BTTS registrada antes do início da partida.
        </x-slot>
    </x-oracly.page-header>

    @if ($this->bestCutoff)
        <x-oracly.stat-tile
            hero
            accent
            :value="number_format($this->bestCutoff['hitRate'], 0).'%'"
            :label="'Melhor corte: ≥ '.$this->bestCutoff['threshold'].'%'"
        >
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                {{ $this->bestCutoff['wins'] }} acertos em {{ $this->bestCutoff['entries'] }} entradas.
            </p>
        </x-oracly.stat-tile>
    @else
        <x-oracly.stat-tile hero :value="'—'" label="Melhor corte ainda indisponível">
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">São necessários pelo menos 20 jogos por corte.</p>
        </x-oracly.stat-tile>
    @endif

    <div>
        <h2 class="mb-2 text-sm font-semibold text-gray-500 dark:text-gray-400">Acerto por corte</h2>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            @foreach ($this->cutoffStats as $threshold => $stat)
                <x-oracly.stat-tile
                    :active="$minProbability === $threshold"
                    :value="$stat['hitRate'] !== null ? number_format($stat['hitRate'], 0).'%' : '—'"
                    :label="'BTTS ≥ '.$threshold.'%'"
                >
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $stat['wins'] }} / {{ $stat['entries'] }} jogos</p>
                </x-oracly.stat-tile>
            @endforeach
        </div>
    </div>

    <x-oracly.chip-group :options="$this::THRESHOLDS" :active="$minProbability" method="setMinProbability" />

    <x-oracly.chip-group :options="$this::RESULT_OPTIONS" :active="$resultFilter" method="setResultFilter" />

    <x-oracly.chip-group :options="$this::FAVORITE_OPTIONS" :active="$favoriteFilter" method="setFavoriteFilter" />

    <div class="max-w-xs">
        <label for="btts-score-filter" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Placar final</label>
        <select id="btts-score-filter" wire:model.live="scoreFilter" class="oracly-select">
            @foreach ($this->scoreOptions as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div class="overflow-x-auto">
        <table class="oracly-table">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Jogo</th>
                    <th>Liga</th>
                    <th>BTTS</th>
                    <th>Resultado HT</th>
                    <th>Resultado</th>
                    <th>Acerto</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->filteredRows as $row)
                    <tr>
                        <td class="whitespace-nowrap">{{ $row['kickoffAt'] ? \Carbon\Carbon::parse($row['kickoffAt'])->timezone('America/Sao_Paulo')->format('d/m H:i') : '—' }}</td>
                        <td class="font-medium text-gray-950 dark:text-white">{{ $row['homeTeam'] }} x {{ $row['awayTeam'] }}</td>
                        <td class="text-gray-500 dark:text-gray-400">{{ $row['country'] }} · {{ $row['competition'] }}</td>
                        <td class="font-semibold">{{ number_format($row['probability'], 0) }}%</td>
                        <td class="whitespace-nowrap">
                            @if ($row['halftimeHomeScore'] !== null && $row['halftimeAwayScore'] !== null)
                                HT {{ $row['halftimeHomeScore'] }}-{{ $row['halftimeAwayScore'] }}
                            @else
                                —
                            @endif
                        </td>
                        <td>FT {{ $row['homeScore'] }}-{{ $row['awayScore'] }}</td>
                        <td><x-oracly.result-badge :hit="$row['hit']" /></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-6 text-gray-500 dark:text-gray-400">Sem jogos para este corte.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
