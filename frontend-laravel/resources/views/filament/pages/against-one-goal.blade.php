<x-filament-panels::page>
    <x-oracly.page-header eyebrow="Estratégia FT · somente ligas principais">
        Contra os placares 0x0, 1x0 e 0x1.

        <x-slot name="description">
            A entrada é green quando o placar final não é 0x0, 1x0 nem 0x1. O sinal principal é a previsão Over 1.5 FT.
        </x-slot>
    </x-oracly.page-header>

    <x-oracly.chip-group :options="$this::MODE_OPTIONS" :active="$mode" method="setMode" />
    <x-oracly.chip-group :options="$this::THRESHOLDS" :active="$minProbability" method="setMinProbability" />
    <x-oracly.chip-group :options="$this::FAVORITE_OPTIONS" :active="$favoriteFilter" method="setFavoriteFilter" />

    @if ($this->bestCutoff)
        <x-oracly.stat-tile hero accent :value="number_format($this->bestCutoff['hitRate'], 0).'%'" :label="'Melhor corte: O1.5 ≥ '.$this->bestCutoff['threshold'].'%'">
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $this->bestCutoff['wins'] }} greens em {{ $this->bestCutoff['entries'] }} jogos.</p>
        </x-oracly.stat-tile>
    @endif

    <div>
        <h2 class="mb-2 text-sm font-semibold text-gray-500 dark:text-gray-400">Assertividade por corte O1.5</h2>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            @foreach ($this->cutoffStats as $threshold => $stat)
                <x-oracly.stat-tile :active="$minProbability === $threshold" :value="$stat['hitRate'] !== null ? number_format($stat['hitRate'], 0).'%' : '—'" :label="'O1.5 ≥ '.$threshold.'%'">
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $stat['wins'] }} / {{ $stat['entries'] }} jogos</p>
                </x-oracly.stat-tile>
            @endforeach
        </div>
    </div>

    @if ($mode === 'history')
        <div class="max-w-xs">
            <label for="against-one-goal-score" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Placar final</label>
            <select id="against-one-goal-score" wire:model.live="scoreFilter" class="oracly-select">
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
                    <th>O2.5</th>
                    <th>BTTS</th>
                    <th>Média gols</th>
                    @if ($mode === 'history')
                        <th>HT</th>
                        <th>FT</th>
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
                        <td>{{ ($row['over25Probability'] ?? null) !== null ? number_format($row['over25Probability'], 0).'%' : '—' }}</td>
                        <td>{{ ($row['bttsProbability'] ?? null) !== null ? number_format($row['bttsProbability'], 0).'%' : '—' }}</td>
                        <td>{{ ($row['combinedGoalsAverage'] ?? null) !== null ? number_format($row['combinedGoalsAverage'], 1) : '—' }}</td>
                        @if ($mode === 'history')
                            <td>{{ $row['halftimeHomeScore'] !== null && $row['halftimeAwayScore'] !== null ? $row['halftimeHomeScore'].'-'.$row['halftimeAwayScore'] : '—' }}</td>
                            <td>{{ $row['homeScore'] }}-{{ $row['awayScore'] }}</td>
                            <td><x-oracly.result-badge :hit="$row['againstHit']" /></td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $mode === 'history' ? 10 : 7 }}" class="py-6 text-gray-500 dark:text-gray-400">Sem jogos para este corte.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
